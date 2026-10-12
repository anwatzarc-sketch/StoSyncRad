<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Billing\Services\CreditService;
use App\Domains\CustomerPortal\Services\CustomerContext;
use App\Domains\CustomerPortal\Services\PortalCatalog;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\IdentityAccess\Services\TotpService;
use App\Http\Controllers\Api\ApiController;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed-in customer: profile, dashboard, catalogue, notifications and authenticator management.
 * Every query starts from the account's own customer (CustomerContext), never from request input.
 */
final class PortalController extends ApiController
{
    private const MFA_CANDIDATE = 'portal.mfa_candidate';

    public function __construct(private readonly CustomerContext $context, private readonly PortalCatalog $catalog) {}

    public function me(Request $request): JsonResponse
    {
        $account = $this->actor($request);
        $customer = $this->context->partnerFor($account);

        return response()->json(['data' => [
            'account' => ['name' => $account->displayName(), 'email' => $account->email, 'mfa_enrolled' => app(TotpService::class)->isEnrolled($account),
                'last_login_at' => $account->last_login_at?->toIso8601String()],
            'customer' => ['name' => $customer->name, 'code' => $customer->code, 'status' => $customer->status->value],
            'permissions' => array_values(array_filter(app(Access::class)->permissionCodes($account), fn (string $c) => str_starts_with($c, 'customer.'))),
        ]]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $customer = $this->context->partnerFor($this->actor($request));
        $open = ['draft', 'confirmed', 'reserved', 'picking', 'packed', 'partially_delivered'];
        $due = DB::table('invoices')->where('customer_partner_id', $customer->id)->whereIn('status', ['issued', 'partially_paid']);

        return response()->json(['data' => [
            'quotations_to_review' => DB::table('quotations')->where('customer_partner_id', $customer->id)->where('status', 'sent')->count(),
            'quotations_in_progress' => DB::table('quotations')->where('customer_partner_id', $customer->id)->whereIn('status', ['requested', 'revision_requested'])->count(),
            'open_orders' => DB::table('sales_orders')->where('customer_partner_id', $customer->id)->whereIn('status', $open)->count(),
            'unpaid_invoices' => (clone $due)->count(),
            'balance_due' => (clone $due)->groupBy('currency')->selectRaw('currency, SUM(balance_due) AS amount')->get()->map(fn ($r) => ['currency' => $r->currency, 'amount' => (string) $r->amount])->all(),
            'overdue_invoices' => (clone $due)->where('due_date', '<', now()->toDateString())->count(),
            'payments_under_review' => DB::table('payment_confirmations')->where('customer_partner_id', $customer->id)->whereIn('status', ['submitted', 'info_requested'])->count(),
            'payments_needing_info' => DB::table('payment_confirmations')->where('customer_partner_id', $customer->id)->where('status', 'info_requested')->count(),
            'notifications' => $this->recentNotifications($request, 6),
            'credit' => app(CreditService::class)->summary($customer),
        ]]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $this->context->partnerFor($this->actor($request));
        $result = $this->catalog->search($request->query('q'), $request->query('category_id'), (int) $request->query('page', 1), (int) $request->query('per_page', 24));

        return response()->json($result);
    }

    public function categories(Request $request): JsonResponse
    {
        $this->context->partnerFor($this->actor($request));

        return response()->json(['data' => array_map(fn (array $c) => ['id' => $c['id'], 'name' => $c['name'], 'path' => $c['full_path'], 'item_count' => (int) $c['item_count']],
            $this->catalog->categories())]);
    }

    public function item(Request $request, string $id): JsonResponse
    {
        $this->context->partnerFor($this->actor($request));

        return response()->json(['data' => $this->catalog->item($id)]);
    }

    public function itemImage(Request $request, string $id): Response
    {
        $this->context->partnerFor($this->actor($request));
        $this->catalog->visibleItems()->whereKey($id)->firstOr(fn () => throw DomainException::notFound('product'));
        $image = DB::table('item_images')->where('item_id', $id)->orderByDesc('is_primary')->orderBy('sort_order')->first(['path', 'mime']);
        abort_if($image === null || ! Storage::disk('private')->exists($image->path), 404);

        return response(Storage::disk('private')->get($image->path), 200, ['Content-Type' => $image->mime ?: 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function notifications(Request $request): JsonResponse
    {
        $this->context->partnerFor($this->actor($request));

        return response()->json(['data' => $this->recentNotifications($request, 50)]);
    }

    public function readNotification(Request $request, string $id): JsonResponse
    {
        $account = $this->actor($request);
        DB::table('notifications')->where('id', $id)->where('user_account_id', $account->id)->whereNull('read_at')
            ->update(['read_at' => now(), 'status' => 'read', 'updated_at' => now()]);

        return response()->json(['data' => ['status' => 'read']]);
    }

    /** Step 1 of replacing the authenticator: prove the current one, get a new QR code (not stored yet). */
    public function beginAuthenticator(Request $request, TotpService $totp): JsonResponse
    {
        $account = $this->actor($request);
        if (! $totp->verify($account, (string) ($this->payload($request)['code'] ?? ''))) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Enter the current code from your authenticator app (or a recovery code).', ['field' => 'code']);
        }
        $candidate = $totp->candidate($account);
        $request->session()->put(self::MFA_CANDIDATE, ['secret' => $candidate['secret'], 'at' => time()]);

        return response()->json(['data' => $candidate]);
    }

    /** Step 2: the first code from the new authenticator switches over and returns new recovery codes. */
    public function confirmAuthenticator(Request $request, TotpService $totp): JsonResponse
    {
        $account = $this->actor($request);
        $candidate = $request->session()->get(self::MFA_CANDIDATE);
        if (! is_array($candidate) || time() - (int) $candidate['at'] > 600) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Start again: the new QR code has expired.', ['field' => 'code']);
        }
        $codes = $totp->replace($account, (string) $candidate['secret'], (string) ($this->payload($request)['code'] ?? ''))
            ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'That code is not from the new authenticator. Scan the new QR code and try again.', ['field' => 'code']);
        $request->session()->forget(self::MFA_CANDIDATE);
        app(AuditRecorder::class)->record($account, 'portal.account.authenticator_replaced', 'user_accounts', $account->id);

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    /** @return array<int, array<string, mixed>> */
    private function recentNotifications(Request $request, int $limit): array
    {
        return DB::table('notifications')->where('user_account_id', $this->actor($request)->id)->orderByDesc('created_at')->limit($limit)
            ->get(['id', 'title', 'link', 'read_at', 'created_at'])
            ->map(fn ($n) => ['id' => $n->id, 'title' => $n->title, 'link' => str_starts_with((string) $n->link, '/portal') ? $n->link : null,
                'read' => $n->read_at !== null, 'created_at' => Carbon::parse($n->created_at)->toIso8601String()])->all();
    }
}
