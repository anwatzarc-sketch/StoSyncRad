<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domains\IdentityAccess\Enums\DocumentAction;
use App\Domains\IdentityAccess\Enums\SodRuleType;
use App\Domains\IdentityAccess\Models\SodRule;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Permissions\PermissionRegistry;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Inventory\Models\Recall;
use App\Domains\Inventory\Models\ReconciliationFinding;
use App\Domains\Planning\Services\ReplenishmentService;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Transfers\Enums\TransferSide;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * "My work" (Chapter 12 §12.6): the WorkflowCatalog queues a person can act on, counted where they hold
 * the awaited action's permission — not merely where they can read — plus the hubs and start actions
 * they may use and a few signals worth watching. Read-only; the services still decide every action.
 */
final class WorkQueues
{
    /** Who performed an action, by the column documents record it in (see ManagesDocuments::documentActors). */
    private const ACTOR_COLUMNS = ['create' => 'created_by', 'submit' => 'submitted_by', 'approve' => 'approved_by', 'post' => 'posted_by'];

    /** @var array<string, array<int, string>>|null `document_type|second_action` → actor columns a person may not have filled */
    private ?array $sodColumns = null;

    /** Catalog order of the queues presented so far (approvals are listed first, then this order). */
    private int $presented = 0;

    public function __construct(
        private readonly Access $access,
        private readonly PlatformConfig $config,
        private readonly PermissionRegistry $permissions,
        private readonly ResourceScope $scope,
    ) {}

    /**
     * Hubs (all, or one) with their start actions, queues and lists, plus the waiting queues and alerts.
     *
     * @return array<string, mixed>
     */
    public function summary(UserAccount $actor, ?string $onlyHub = null): array
    {
        $counted = [];
        $hubs = [];
        foreach ($this->visibleHubs($actor) as $key) {
            if ($onlyHub !== null && $key !== $onlyHub) {
                continue;
            }
            $hub = WorkflowCatalog::HUBS[$key];
            $queues = [];
            foreach (WorkflowCatalog::queues() as $queue) {
                if (! in_array($key, $queue['hubs'], true) || ! $this->queueVisible($actor, $queue)) {
                    continue;
                }
                $counted[$queue['key']] ??= $this->present($actor, $queue);
                $queues[] = $counted[$queue['key']];
            }
            $hubs[] = [
                'key' => $key, 'label' => $hub['label'], 'icon' => $hub['icon'], 'description' => $hub['description'], 'href' => "/work/$key",
                'start' => $this->startActions($actor, $hub['start']),
                'queues' => $queues,
                'lists' => array_values(array_map(fn (string $r) => ['resource' => $r, 'href' => "/r/$r",
                    'label' => Str::ucfirst(Str::plural(WorkflowCatalog::document($r)['noun'] ?? Str::lower(Str::headline($r))))],
                    array_filter($hub['resources'], fn (string $r) => $this->resourceReadable($actor, $r)))),
            ];
        }
        if ($onlyHub === null) {
            foreach (WorkflowCatalog::queues() as $queue) {   // queues of no hub (e.g. opening balances) still wait for someone
                if ($queue['hubs'] === [] && $this->queueVisible($actor, $queue)) {
                    $counted[$queue['key']] = $this->present($actor, $queue);
                }
            }
        }
        // Waiting = something to do (approvals first), or one's own open requests; "watch" queues stay in their hub.
        $waiting = array_values(array_filter($counted, fn (array $q) => $q['count'] > 0 && ($q['kind'] !== 'watch' || $q['own'])));
        usort($waiting, fn (array $a, array $b) => [$a['kind'] !== 'approve', $a['order']] <=> [$b['kind'] !== 'approve', $b['order']]);

        return [
            'hubs' => $hubs,
            'waiting' => $waiting,
            'start' => array_values(collect($hubs)->flatMap(fn (array $h) => $h['start'])->unique('href')->all()),
            'alerts' => $onlyHub === null ? $this->alerts($actor) : [],
        ];
    }

    /**
     * Hubs this person works in: they can start something there, or read its documents. Documents
     * readable with mere stock visibility (moves, condition changes, write-offs: inventory.stock.read)
     * do not make someone part of that workflow.
     *
     * @return array<int, string>
     */
    public function visibleHubs(UserAccount $actor): array
    {
        $out = [];
        foreach (WorkflowCatalog::HUBS as $key => $hub) {
            $usable = $this->startActions($actor, $hub['start']) !== []
                || array_filter($hub['resources'], fn (string $r) => ResourceRegistry::get($r)['read'] !== 'inventory.stock.read'
                    && $this->resourceReadable($actor, $r)) !== [];
            if ($usable) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /** The documents waiting in one queue, oldest first. */
    public function query(UserAccount $actor, array $queue): Builder
    {
        $cfg = ResourceRegistry::get($queue['resource']);
        /** @var Builder $query */
        $query = $cfg['model']::query()->whereIn('status', $queue['statuses']);
        if ($queue['own'] ?? false) {
            $this->scope->apply($query, $cfg, $actor, true);
        } else {
            // Queues are scoped by the awaited action's permission; own-record visibility is for `own` queues only.
            $this->scope->apply($query, ['own' => null] + $cfg, $actor, false, $queue['permission'], $this->scopeColumns($queue));
        }
        $columns = Schema::getColumnListing($query->getModel()->getTable());
        if (isset($queue['mine']) && in_array($queue['mine'], $columns, true)) {
            $query->where($queue['mine'], $actor->id);
        }
        foreach ($this->sodColumnsFor($queue) as $column) {
            if (in_array($column, $columns, true)) {
                $query->where(fn ($w) => $w->whereNull($column)->orWhere($column, '<>', $actor->id));
            }
        }

        return $query->orderBy(in_array('created_at', $columns, true) ? 'created_at' : $query->getModel()->getKeyName())->orderBy($query->getModel()->getKeyName());
    }

    /** A queue the person may see: its feature is on and they hold the awaited permission somewhere. */
    public function queueVisible(UserAccount $actor, array $queue): bool
    {
        $feature = ResourceRegistry::get($queue['resource'])['feature'] ?? null;

        return ($feature === null || $this->config->feature($feature)) && $this->permitted($actor, $queue['permission']);
    }

    /** @return array<string, mixed> */
    private function present(UserAccount $actor, array $queue): array
    {
        $query = $this->query($actor, $queue);
        $count = (clone $query)->count();
        $single = $count === 1 ? (clone $query)->value($query->getModel()->getQualifiedKeyName()) : null;
        $href = $single !== null ? "/r/{$queue['resource']}/$single"
            : ($queue['hubs'] !== [] ? "/work/{$queue['hubs'][0]}?queue={$queue['key']}"
                : "/r/{$queue['resource']}?filter[status]=".implode(',', $queue['statuses']));

        $schema = ResourceRegistry::get($queue['resource'])['schema'];

        return ['key' => $queue['key'], 'label' => $queue['label'], 'kind' => $queue['kind'], 'resource' => $queue['resource'], 'statuses' => $queue['statuses'],
            'schema' => $schema === null ? null : $schema::name(),
            'hub' => $queue['hubs'][0] ?? null, 'count' => $count, 'href' => $href, 'own' => (bool) ($queue['own'] ?? false), 'order' => $this->presented++];
    }

    /**
     * @param  array<int, array{label: string, href: string, icon: string, permission: array<int, string>}>  $actions
     * @return array<int, array{label: string, href: string, icon: string}>
     */
    private function startActions(UserAccount $actor, array $actions): array
    {
        $out = [];
        foreach ($actions as $a) {
            foreach ($a['permission'] as $permission) {
                if ($this->permitted($actor, $permission)) {
                    $out[] = ['label' => $a['label'], 'href' => $a['href'], 'icon' => $a['icon']];

                    break;
                }
            }
        }

        return $out;
    }

    private function resourceReadable(UserAccount $actor, string $resource): bool
    {
        $cfg = ResourceRegistry::get($resource);
        if (! empty($cfg['feature']) && ! $this->config->feature($cfg['feature'])) {
            return false;
        }

        return $this->permitted($actor, $cfg['read']) || (isset($cfg['own']) && $this->permitted($actor, $cfg['own'][0]));
    }

    /** Held somewhere, and not switched off with the feature it belongs to. */
    private function permitted(UserAccount $actor, string $permission): bool
    {
        $flag = $this->permissions->get($permission)?->featureFlag;

        return ($flag === null || $this->config->feature($flag)) && $this->access->hasPermissionAnywhere($actor, $permission);
    }

    /** @return array<int, string>|null */
    private function scopeColumns(array $queue): ?array
    {
        if (($queue['scope_config'] ?? null) === 'transfers.approve_side') {
            return match (TransferSide::from((string) $this->config->get('transfers.approve_side'))) {
                TransferSide::SOURCE => ['source_warehouse_id'],
                TransferSide::DESTINATION => ['destination_warehouse_id'],
                default => ['source_warehouse_id', 'destination_warehouse_id'],
            };
        }

        return $queue['scope'] ?? null;
    }

    /**
     * Columns naming people who may not take this queue's action on the same document under an active,
     * unconditional "different actor required" rule (e.g. the creator of a purchase order cannot approve it).
     *
     * @return array<int, string>
     */
    private function sodColumnsFor(array $queue): array
    {
        $action = $queue['sod'] ?? null;
        if (! $action instanceof DocumentAction || $queue['document_type'] === null) {
            return [];
        }
        if ($this->sodColumns === null) {
            $this->sodColumns = [];
            $rules = SodRule::query()->where('is_active', true)->where('rule', SodRuleType::DIFFERENT_ACTOR_REQUIRED->value)->whereNull('threshold')->get();
            foreach ($rules as $rule) {
                $column = self::ACTOR_COLUMNS[$rule->first_action->value] ?? null;
                if ($column !== null) {
                    $this->sodColumns[$rule->document_type->value.'|'.$rule->second_action->value][] = $column;
                }
            }
        }

        return $this->sodColumns[$queue['document_type']->value.'|'.$action->value] ?? [];
    }

    /**
     * Signals worth a look, each only for people allowed to see what it points to.
     *
     * @return array<int, array{key: string, label: string, count: int, href: ?string, icon: string}>
     */
    private function alerts(UserAccount $actor): array
    {
        $out = [];
        if ($this->permitted($actor, 'planning.replenishment.read')) {
            $out[] = ['key' => 'below_reorder', 'label' => 'Items below reorder point', 'icon' => 'refresh', 'href' => '/planning/replenishment',
                'count' => count(app(ReplenishmentService::class)->suggestions($actor, ['below_only' => true]))];
        }
        if ($this->permitted($actor, 'inventory.stock.read')) {
            $allowed = $this->access->warehouseIdsFor($actor, 'inventory.stock.read');
            $count = (int) DB::table('inventory_balances as b')->join('inventory_lots as l', 'l.id', '=', 'b.inventory_lot_id')
                ->where('b.quantity_on_hand', '>', 0)->whereNotNull('l.expiry_date')
                ->whereBetween('l.expiry_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
                ->when($allowed !== '*', fn ($q) => $q->whereIn('b.warehouse_id', $allowed === [] ? ['00000000-0000-0000-0000-000000000000'] : $allowed))
                ->distinct()->count('b.inventory_lot_id');
            $report = $this->permitted($actor, 'reporting.report.run') ? DB::table('report_definitions')->where('code', 'std_expiring_stock')->value('id') : null;
            $out[] = ['key' => 'expiring_lots', 'label' => 'Lots expiring within 30 days', 'icon' => 'calendar', 'count' => $count,
                'href' => $report !== null ? "/reports/$report" : null];
        }
        if ($this->config->feature('feature.recalls') && $this->permitted($actor, 'inventory.recall.read')) {
            $out[] = ['key' => 'open_recalls', 'label' => 'Open recalls', 'icon' => 'alert', 'href' => '/r/recalls?filter[status]=initiated,in_progress',
                'count' => Recall::query()->whereIn('status', ['initiated', 'in_progress'])->count()];
        }
        if ($this->permitted($actor, 'inventory.reconciliation.read')) {
            $out[] = ['key' => 'reconciliation_findings', 'label' => 'Stock reconciliation differences', 'icon' => 'alert',
                'href' => '/r/reconciliation-findings?filter[status]=open,investigating',
                'count' => ReconciliationFinding::query()->whereIn('status', ['open', 'investigating'])->count()];
        }

        return $out;
    }
}
