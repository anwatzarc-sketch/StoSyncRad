<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Services;

use App\Domains\IdentityAccess\Enums\AccountStatus;
use App\Domains\IdentityAccess\Enums\AccountType;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Messaging\Enums\NotificationChannel;
use App\Domains\Messaging\Enums\NotificationStatus;
use App\Domains\Messaging\Models\Notification;
use App\Domains\Messaging\Models\NotificationPreference;
use App\Domains\Messaging\Models\OutboxEvent;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Services\BrandResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Turns outbox events into notifications for the people who must act (Chapter 11 §11.7): each
 * route names the configurable notification event, a title, and either the permission (in the
 * document's warehouse) that identifies recipients or a payload key holding a specific account.
 * Channels come from `messaging.events.<event>.channels`, narrowed by personal preferences.
 */
final class NotificationRouter
{
    /**
     * outbox event => [notification event, title, permission|null, warehouse payload key|'@aggregate'|null, recipient payload key|null, link]
     * Links are web pages: records open at /r/{resource}/{id} (see routes/web/app.php).
     */
    public const ROUTES = [
        'fulfillment.stock_request.submitted' => ['approval_pending', 'Stock request {document_number} awaits approval', 'fulfillment.stock_request.approve', 'warehouse_id', null, '/r/stock-requests/{aggregate_id}'],
        'fulfillment.stock_request.approved' => ['issue_ready_for_picking', 'Stock request {document_number} is approved and ready to pick', 'fulfillment.stock_issue.create', 'warehouse_id', null, '/r/stock-requests/{aggregate_id}'],
        'fulfillment.stock_request.rejected' => ['approval_decided', 'Your stock request {document_number} was rejected', null, null, 'requester_id', '/r/stock-requests/{aggregate_id}'],
        'procurement.purchase_request.submitted' => ['approval_pending', 'Purchase request {document_number} awaits approval', 'procurement.purchase_request.approve', 'warehouse_id', null, '/r/purchase-requests/{aggregate_id}'],
        'procurement.purchase_order.submitted' => ['approval_pending', 'Purchase order {document_number} awaits approval', 'procurement.purchase_order.approve', 'warehouse_id', null, '/r/purchase-orders/{aggregate_id}'],
        'transfers.transfer.submitted' => ['approval_pending', 'Transfer {document_number} awaits approval', 'transfers.transfer.approve', 'source_warehouse_id', null, '/r/transfers/{aggregate_id}'],
        'transfers.transfer.approved' => ['transfer_awaiting_dispatch', 'Transfer {document_number} is approved — pick and dispatch', 'transfers.transfer.dispatch', 'source_warehouse_id', null, '/r/transfers/{aggregate_id}'],
        'transfers.transfer.dispatched' => ['transfer_awaiting_receipt', 'Transfer {document_number} is on its way to you', 'transfers.transfer.receive', 'destination_warehouse_id', null, '/r/transfers/{aggregate_id}'],
        'transfers.discrepancy.reported' => ['transfer_discrepancy', 'Discrepancy reported on transfer {document_number}', 'transfers.discrepancy.resolve', null, null, '/r/transfers/{aggregate_id}'],
        'receiving.goods_receipt.rejections_recorded' => ['receipt_requires_inspection', 'Receipt {document_number} has rejected quantities', 'receiving.qc.inspect', null, null, '/r/goods-receipts/{aggregate_id}'],
        'stocktaking.adjustment.submitted' => ['adjustment_requires_approval', 'Adjustment {document_number} awaits approval', 'stocktaking.adjustment.approve', 'warehouse_id', null, '/r/stock-adjustments/{aggregate_id}'],
        'stocktaking.stocktake.scheduled' => ['stocktake_assigned', 'Stocktake {document_number} is scheduled', 'stocktaking.stocktake.count', null, null, '/r/stocktakes/{aggregate_id}'],
        'inventory.movement.reversal_requested' => ['approval_pending', 'Reversal of {movement_number} awaits approval', 'inventory.movement.reverse', null, null, '/r/movements'],
        'inventory.lot.expiry_warning' => ['expiry_warning', 'Lot {lot_number} expires on {expiry_date}', 'inventory.lot.read', null, null, '/r/lots/{aggregate_id}'],
        'inventory.lot.expired' => ['expiry_warning', 'Expired stock was moved to the expired condition', 'inventory.stock.read', '@aggregate', null, '/stock'],
        'inventory.recall.initiated' => ['expiry_warning', 'Recall {document_number}: affected lots are locked', 'inventory.stock.read', null, null, '/r/recalls/{aggregate_id}'],
        'inventory.reconciliation.findings' => ['reconciliation_failed', 'Stock reconciliation found {findings} difference(s)', 'inventory.reconciliation.read', null, null, '/r/reconciliation-runs/{aggregate_id}'],
        'reporting.report.completed' => ['report_run_completed', 'Your export "{report_name}" is ready', null, null, 'runner_account_id', '/reports/runs?run={aggregate_id}'],
        'reporting.report.failed' => ['report_run_failed', 'Your export "{report_name}" failed', null, null, 'runner_account_id', '/reports/runs?run={aggregate_id}'],
        'exchange.import.completed' => ['import_completed', 'Import {filename} finished: {imported} imported, {invalid} invalid', null, null, 'created_by', '/imports?batch={aggregate_id}'],
    ];

    public function __construct(private readonly Access $access, private readonly PlatformConfig $config, private readonly BrandResolver $brand) {}

    /** @return int notifications created */
    public function route(OutboxEvent $event): int
    {
        $route = self::ROUTES[$event->event_type] ?? null;
        if ($route === null) {
            return 0;
        }
        [$code, $title, $permission, $warehouseKey, $recipientKey, $link] = $route;
        $payload = (array) $event->payload;
        $vars = $payload + ['aggregate_id' => $event->aggregate_id];
        $title = $this->fill($title, $vars);
        $link = $this->fill($link, $vars);
        $recipients = $recipientKey !== null
            ? UserAccount::query()->whereKey($payload[$recipientKey] ?? null)->get()
            : $this->holders((string) $permission, $warehouseKey === '@aggregate' ? $event->aggregate_id : ($warehouseKey !== null ? ($payload[$warehouseKey] ?? null) : null));
        $defaults = array_map(fn ($c) => $c instanceof NotificationChannel ? $c->value : (string) $c, (array) $this->config->get("messaging.events.$code.channels"));
        $created = 0;
        foreach ($recipients as $account) {
            if (! $account->isActive()) {
                continue;
            }
            $pref = NotificationPreference::query()->where('user_account_id', $account->id)->where('event_type', $code)->first();
            if ($pref?->is_muted) {
                continue;
            }
            $channels = $pref !== null && $pref->channels !== [] ? array_values(array_intersect($pref->channels, $defaults ?: ['in_app'])) : $defaults;
            if (in_array(NotificationChannel::IN_APP->value, $channels, true) || $channels === []) {
                $inserted = DB::table('notifications')->insertOrIgnore([
                    'id' => (string) \Illuminate\Support\Str::uuid7(), 'user_account_id' => $account->id, 'event_type' => $code, 'title' => $title,
                    'link' => $link, 'data' => json_encode(['event' => $event->event_type, 'aggregate_type' => $event->aggregate_type,
                        'aggregate_id' => $event->aggregate_id] + $payload), 'status' => NotificationStatus::UNREAD->value,
                    'outbox_event_id' => $event->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $created += $inserted;
            }
            if (in_array(NotificationChannel::EMAIL->value, $channels, true) && $account->email) {
                $this->email($account, $title, $link);
            }
        }

        return $created;
    }

    /** Active human accounts holding the permission (in the warehouse, when given). Platform super admins are not broadcast to. */
    private function holders(string $permission, ?string $warehouseId)
    {
        $candidates = UserAccount::query()->where('account_status', AccountStatus::ACTIVE->value)->where('account_type', AccountType::HUMAN->value)
            ->where('is_super_admin', false)
            ->whereIn('id', DB::table('role_assignments as ra')->join('role_permissions as rp', 'rp.role_id', '=', 'ra.role_id')
                ->where('rp.permission_code', $permission)->where(fn ($q) => $q->whereNull('ra.effective_to')->orWhere('ra.effective_to', '>', now()))
                ->select('ra.user_account_id'))
            ->limit(500)->get();

        return $candidates->filter(fn (UserAccount $a) => $this->access->can($a, $permission, $warehouseId))->values();
    }

    private function email(UserAccount $account, string $title, ?string $link): void
    {
        $brand = $this->brand->publicProfile();
        $from = (string) $this->config->get('messaging.mail.from_address');
        try {
            Mail::raw($title.($link ? "\n\n".url($link) : ''), function ($m) use ($account, $title, $brand, $from) {
                $m->to($account->email)->subject('['.($brand['app_short_name'] ?? 'Stock').'] '.$title);
                if ($from !== '') {
                    $m->from($from, (string) $this->config->get('messaging.mail.from_name') ?: ($brand['app_name'] ?? null));
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Notification email failed', ['account' => $account->id, 'error' => $e->getMessage()]);
        }
    }

    /** @param  array<string, mixed>  $vars */
    private function fill(?string $template, array $vars): ?string
    {
        if ($template === null) {
            return null;
        }

        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => is_scalar($vars[$m[1]] ?? null) ? (string) $vars[$m[1]] : '', $template);
    }
}
