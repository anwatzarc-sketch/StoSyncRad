<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Catalog\Actions\SaveCategoryAction;
use App\Domains\Catalog\Actions\SaveItemAction;
use App\Domains\Catalog\Enums\CategoryStatus;
use App\Domains\Catalog\Enums\ItemStatus;
use App\Domains\Catalog\Enums\TrackingMode;
use App\Domains\Catalog\Models\Item;
use App\Domains\IdentityAccess\Enums\AccountType;
use App\Domains\IdentityAccess\Enums\PersonStatus;
use App\Domains\IdentityAccess\Enums\SecurityEventType;
use App\Domains\IdentityAccess\Enums\SodRuleType;
use App\Domains\IdentityAccess\Models\ApprovalPolicy;
use App\Domains\IdentityAccess\Models\Person;
use App\Domains\IdentityAccess\Models\RoleAssignment;
use App\Domains\IdentityAccess\Models\SecurityEvent;
use App\Domains\IdentityAccess\Models\SodRule;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Organization\Enums\BranchStatus;
use App\Domains\Organization\Enums\StorageLocationStatus;
use App\Domains\Organization\Enums\WarehouseStatus;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\StorageLocation;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Organization\Schemas\CompanyProfileSchema;
use App\Domains\Partners\Enums\PartnerStatus;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Services\SetupState;
use App\Domains\Platform\Setup\SetupService;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Math\Decimal as D;
use App\Support\Schema\SchemaValidated;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lifecycle and maintenance endpoints for master data, identity and platform settings — one
 * permission per endpoint (Chapter 04 §4.2): status changes with integrity guards, item
 * sub-collections, tracking conversion, assignments, SoD rules and approval tiers, service-account
 * tokens, company profile, numbering, translations and setup reopening.
 */
final class LifecycleController extends ApiController
{
    public function __construct(private readonly AuditRecorder $audit, private readonly Access $access) {}

    // ---- Catalog -----------------------------------------------------------------------------

    public function itemStatus(Request $request, SaveItemAction $items, string $id): JsonResponse
    {
        $status = match ((string) $request->route()->getAction('variant')) {
            'activate' => ItemStatus::ACTIVE, 'deactivate' => ItemStatus::INACTIVE, default => ItemStatus::ARCHIVED,
        };

        return $this->respond($items->setStatus($id, $status, $this->actor($request)));
    }

    public function itemCollection(Request $request, SaveItemAction $items, string $id): JsonResponse
    {
        $field = (string) $request->route()->getAction('variant');   // barcodes | packagings | kit_components
        $item = $items->execute([$field => (array) $request->input($field, [])] + ($request->filled('version') ? ['version' => (int) $request->input('version')] : []),
            $this->actor($request), $id);

        return $this->respond($item->load(['barcodes', 'packagings']));
    }

    public function itemBarcodes(string $id): JsonResponse
    {
        return response()->json(['data' => DB::table('item_barcodes')->where('item_id', $id)->orderByDesc('is_primary')->get()->map(fn ($b) => (array) $b)->all()]);
    }

    /** Change tracking mode — only when nothing is on hand, reserved or in transit (step-up). */
    public function convertTracking(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $mode = TrackingMode::tryFrom((string) $request->input('tracking_mode')) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
            'Allowed: '.implode(', ', TrackingMode::values()).'.', ['field' => 'tracking_mode']);
        $item = DB::transaction(function () use ($id, $mode, $actor, $request) {
            $item = Item::query()->lockForUpdate()->findOrFail($id);
            $stock = (string) DB::table('inventory_balances')->where('item_id', $item->id)->selectRaw('COALESCE(SUM(abs(quantity_on_hand) + quantity_reserved), 0) AS q')->value('q');
            if (! D::isZero($stock)) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Tracking can only change when the item has no stock or reservations anywhere. Issue or adjust the stock first.');
            }
            $before = $item->tracking_mode->value;
            $item->forceFill(['tracking_mode' => $mode, 'version' => $item->version + 1, 'updated_by' => $actor->id])->save();
            $this->audit->record($actor, 'catalog.item.tracking_converted', 'items', $item->id, ['tracking_mode' => $before], ['tracking_mode' => $mode->value], $request->input('reason'));

            return $item;
        });

        return $this->respond($item);
    }

    public function categoryArchive(Request $request, SaveCategoryAction $categories, string $id): JsonResponse
    {
        return $this->respond($categories->setStatus($id, CategoryStatus::ARCHIVED, $this->actor($request)));
    }

    public function categoryMove(Request $request, SaveCategoryAction $categories, string $id): JsonResponse
    {
        $parent = $request->input('parent_id');
        if ($parent !== null && ! Str::isUuid((string) $parent)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'parent_id must be a UUID or null.', ['field' => 'parent_id']);
        }

        return $this->respond($categories->execute(['parent_id' => $parent, 'move_reason' => $request->input('reason')], $this->actor($request), $id));
    }

    // ---- Organization ------------------------------------------------------------------------

    public function closeWarehouse(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $wh = DB::transaction(function () use ($id, $actor) {
            $wh = Warehouse::query()->lockForUpdate()->findOrFail($id);
            $onHand = (string) DB::table('inventory_balances')->where('warehouse_id', $wh->id)->selectRaw('COALESCE(SUM(abs(quantity_on_hand)), 0) AS q')->value('q');
            $next = D::isZero($onHand) ? WarehouseStatus::CLOSED : WarehouseStatus::CLOSING;
            $wh->forceFill(['status' => $next, 'updated_by' => $actor->id])->save();
            $this->audit->record($actor, 'organization.warehouse.'.$next->value, 'warehouses', $wh->id, null, ['remaining_on_hand' => $onHand]);

            return $wh;
        });

        return $this->respond($wh);
    }

    public function closeBranch(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $branch = Branch::query()->findOrFail($id);
        if (Warehouse::query()->where('branch_id', $branch->id)->where('status', '<>', WarehouseStatus::CLOSED->value)->exists()) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Close every warehouse of the branch first.');
        }
        $branch->forceFill(['status' => BranchStatus::CLOSED, 'closed_on' => now()->toDateString(), 'updated_by' => $actor->id])->save();
        $this->audit->record($actor, 'organization.branch.closed', 'branches', $branch->id);

        return $this->respond($branch);
    }

    public function locationStatus(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $action = (string) $request->route()->getAction('variant');   // block | unblock | archive
        $loc = StorageLocation::query()->findOrFail($id);
        if ($loc->is_system && $action === 'archive') {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'System locations cannot be archived.');
        }
        if ($action === 'archive') {
            $stock = DB::table('inventory_balances as b')->join('inventory_locations as il', 'il.id', '=', 'b.inventory_location_id')
                ->where('il.storage_location_id', $loc->id)->where('b.quantity_on_hand', '<>', 0)->exists();
            if ($stock || StorageLocation::query()->where('parent_id', $loc->id)->where('status', '<>', StorageLocationStatus::ARCHIVED->value)->exists()) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Only empty locations without active children can be archived.');
            }
        }
        $loc->forceFill(['status' => match ($action) {
            'block' => StorageLocationStatus::BLOCKED, 'archive' => StorageLocationStatus::ARCHIVED, default => StorageLocationStatus::ACTIVE
        },
            'updated_by' => $actor->id])->save();
        DB::table('inventory_locations')->where('storage_location_id', $loc->id)->update(['is_active' => $action !== 'archive', 'updated_at' => now()]);
        $this->audit->record($actor, 'organization.storage_location.'.$action, 'storage_locations', $loc->id, null, null, $request->input('reason'));

        return $this->respond($loc);
    }

    // ---- Partners ----------------------------------------------------------------------------

    public function partnerStatus(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $status = match ((string) $request->route()->getAction('variant')) {
            'hold' => PartnerStatus::ON_HOLD, 'blacklist' => PartnerStatus::BLACKLISTED, 'archive' => PartnerStatus::ARCHIVED, default => PartnerStatus::ACTIVE,
        };
        if (in_array($status, [PartnerStatus::BLACKLISTED, PartnerStatus::ON_HOLD], true) && trim((string) $request->input('reason')) === '') {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Give a reason.', ['field' => 'reason']);
        }
        $partner = Partner::query()->findOrFail($id);
        // Reactivating needs the permission that put the partner in its current state.
        $lifting = match ($status === PartnerStatus::ACTIVE ? $partner->status : null) {
            PartnerStatus::BLACKLISTED => 'partners.partner.blacklist', PartnerStatus::ARCHIVED => 'partners.partner.archive', default => null,
        };
        if ($lifting !== null && ! $this->access->can($actor, $lifting)) {
            throw DomainException::permissionDenied($lifting);
        }
        $before = $partner->status->value;
        $partner->forceFill(['status' => $status, 'updated_by' => $actor->id])->save();
        $this->audit->record($actor, 'partners.partner.status_changed', 'partners', $partner->id, ['status' => $before], ['status' => $status->value], $request->input('reason'));

        return $this->respond($partner);
    }

    public function carriers(): JsonResponse
    {
        return response()->json(['data' => $this->presenter()->collection(Partner::query()->whereRaw("'carrier' MEMBER OF (partner_types)")->orderBy('name')->get())]);
    }

    /** Carrier profile: the public tracking link template (`{tracking}` is replaced by the shipment's number). */
    public function updateCarrier(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $partner = Partner::query()->whereRaw("'carrier' MEMBER OF (partner_types)")->findOrFail($id);
        $template = $request->input('tracking_url_template');
        if ($template !== null && (! str_starts_with((string) $template, 'https://') || ! str_contains((string) $template, '{tracking}') || mb_strlen((string) $template) > 500)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Use an https:// link containing {tracking}.', ['field' => 'tracking_url_template']);
        }
        $before = $partner->only(['tracking_url_template']);
        $partner->forceFill(['tracking_url_template' => $template, 'updated_by' => $actor->id])->save();
        $this->audit->record($actor, 'partners.carrier.updated', 'partners', $partner->id, $before, $partner->only(['tracking_url_template']));

        return $this->respond($partner);
    }

    // ---- Identity ----------------------------------------------------------------------------

    public function archivePerson(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $person = Person::query()->findOrFail($id);
        if (UserAccount::query()->where('person_id', $person->id)->where('account_status', 'active')->exists()) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Deactivate the person’s accounts first.');
        }
        $person->forceFill(['status' => PersonStatus::LEFT, 'archived_at' => now(), 'updated_by' => $actor->id])->save();
        $this->audit->record($actor, 'identity.person.archived', 'persons', $person->id);

        return $this->respond($person);
    }

    public function updateAssignment(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $a = RoleAssignment::query()->findOrFail($id);
        $from = $request->input('effective_from');
        $to = $request->input('effective_to');
        if ($from && $to && strtotime((string) $to) <= strtotime((string) $from)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The end must be after the start.', ['field' => 'effective_to']);
        }
        $before = $a->only(['effective_from', 'effective_to']);
        $a->forceFill(['effective_from' => $from, 'effective_to' => $to])->save();
        $this->access->forget($a->user_account_id);
        $this->audit->record($actor, 'identity.assignment.updated', 'role_assignments', $a->id, $before, $a->only(['effective_from', 'effective_to']), $request->input('reason'));

        return $this->respond($a);
    }

    public function updateSodRule(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $rule = SodRule::query()->findOrFail($id);
        $type = $request->has('rule') ? (SodRuleType::tryFrom((string) $request->input('rule')) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
            'Allowed: '.implode(', ', SodRuleType::values()).'.', ['field' => 'rule'])) : $rule->rule;
        $before = $rule->only(['rule', 'is_active', 'threshold']);
        $rule->forceFill(['rule' => $type, 'is_active' => $request->boolean('is_active', $rule->is_active),
            'threshold' => $request->has('threshold') ? $request->input('threshold') : $rule->threshold, 'updated_by' => $actor->id])->save();
        $this->audit->record($actor, 'identity.sod_rule.updated', 'sod_rules', $rule->id, $before, $rule->only(['rule', 'is_active', 'threshold']), $request->input('reason'));

        return $this->respond($rule);
    }

    /** Approval tiers: [{tier, max_value|null}] ascending; values are exact decimals. */
    public function updateApprovalPolicy(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $policy = ApprovalPolicy::query()->findOrFail($id);
        $tiers = array_values((array) $request->input('tiers', []));
        $previous = '-1';
        foreach ($tiers as $i => $t) {
            $max = $t['max_value'] ?? null;
            if ($max !== null && (! is_numeric($max) || D::lte((string) $max, $previous))) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Tier limits must increase.', ['field' => "tiers.$i.max_value"]);
            }
            $previous = $max ?? $previous;
        }
        $before = $policy->only(['tiers', 'is_active']);
        $policy->forceFill(['tiers' => $tiers, 'is_active' => $request->boolean('is_active', $policy->is_active), 'updated_by' => $actor->id])->save();
        $this->audit->record($actor, 'identity.approval_policy.updated', 'approval_policies', $policy->id, $before, $policy->only(['tiers', 'is_active']));

        return $this->respond($policy);
    }

    public function securityEvents(Request $request): JsonResponse
    {
        $page = SecurityEvent::query()->when($request->filled('account_id'), fn ($q) => $q->where('account_id', (string) $request->query('account_id')))
            ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', (string) $request->query('event_type')))
            ->orderByDesc('occurred_at')->orderByDesc('id')->cursorPaginate(100);

        return response()->json(['data' => $this->presenter()->collection($page->items()), 'meta' => ['next_cursor' => $page->nextCursor()?->encode()]]);
    }

    // ---- Service-account API tokens ---------------------------------------------------------

    public function tokens(): JsonResponse
    {
        return response()->json(['data' => DB::table('personal_access_tokens as t')->join('user_accounts as u', 'u.id', '=', 't.tokenable_id')
            ->orderByDesc('t.created_at')->get(['t.id', 't.name', 't.abilities', 't.last_used_at', 't.expires_at', 't.created_at', 'u.username'])->map(fn ($r) => (array) $r)->all()]);
    }

    public function issueToken(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $account = UserAccount::query()->findOrFail((string) $request->input('user_account_id'));
        if ($account->account_type !== AccountType::SERVICE) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'API tokens are issued to service accounts only.');
        }
        $days = min((int) $request->input('expires_in_days', 90), (int) app(PlatformConfig::class)->int('security.api_token.max_lifetime_days'));
        $token = $account->createToken(mb_substr((string) $request->input('name', 'integration'), 0, 120), ['*'], now()->addDays(max(1, $days)));
        SecurityEvent::log(SecurityEventType::TOKEN_ISSUED, $account->id, null, ['token_id' => $token->accessToken->getKey(), 'issued_by' => $actor->id]);
        $this->audit->record($actor, 'identity.api_token.issued', 'user_accounts', $account->id, null, ['name' => $token->accessToken->name, 'expires_at' => $token->accessToken->expires_at]);

        return response()->json(['data' => ['token' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at]], 201);
    }

    public function revokeToken(Request $request, string $id): JsonResponse
    {
        $row = DB::table('personal_access_tokens')->where('id', $id)->first() ?? throw DomainException::notFound('Token');
        DB::table('personal_access_tokens')->where('id', $id)->delete();
        SecurityEvent::log(SecurityEventType::TOKEN_REVOKED, (string) $row->tokenable_id, null, ['token_id' => $id]);
        $this->audit->record($this->actor($request), 'identity.api_token.revoked', 'user_accounts', (string) $row->tokenable_id, null, ['token_id' => $id]);

        return response()->json(['data' => ['status' => 'revoked']]);
    }

    // ---- Platform -----------------------------------------------------------------------------

    public function company(): JsonResponse
    {
        return response()->json(['data' => (array) (DB::table('company_profile')->first() ?? [])]);
    }

    public function updateCompany(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = SchemaValidated::from($request);
        $row = DB::table('company_profile')->first();
        $values = CompanyProfileSchema::columns($data);
        foreach (['registered_address', 'operational_address'] as $json) {
            if (array_key_exists($json, $values)) {
                $values[$json] = $values[$json] === null ? null : json_encode($values[$json]);
            }
        }
        DB::table('company_profile')->where('id', $row?->id)->update($values + ['updated_at' => now()]);
        $this->audit->record($actor, 'platform.company.updated', 'company_profile', (string) $row?->id, null, array_keys($values));

        return $this->company();
    }

    public function sequences(): JsonResponse
    {
        return response()->json(['data' => DB::table('document_sequences')->where('period_key', '')->orderBy('document_type')->get()->map(fn ($r) => (array) $r)->all()]);
    }

    public function updateSequence(Request $request, string $id): JsonResponse
    {
        $actor = $this->actor($request);
        $pattern = (string) $request->input('pattern');
        $prefix = (string) $request->input('prefix');
        if (! preg_match('/\{SEQ(:\d+)?\}/', $pattern) || ! preg_match('/^[A-Z0-9_-]{0,12}$/', $prefix)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The pattern must contain {SEQ}; prefixes are up to 12 upper-case letters/digits.', ['field' => 'pattern']);
        }
        $before = (array) DB::table('document_sequences')->where('id', $id)->first(['prefix', 'pattern', 'sequence_padding']);
        DB::table('document_sequences')->where('id', $id)->update(['prefix' => $prefix, 'pattern' => $pattern,
            'sequence_padding' => max(1, min(12, (int) $request->input('sequence_padding', 6))), 'updated_at' => now()]);
        $this->audit->record($actor, 'platform.numbering.updated', 'document_sequences', $id, $before, ['prefix' => $prefix, 'pattern' => $pattern]);

        return $this->sequences();
    }

    public function translations(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('translation_overrides')->when($request->filled('locale'), fn ($q) => $q->where('locale', (string) $request->query('locale')))
            ->orderBy('locale')->orderBy('key')->get()->map(fn ($r) => (array) $r)->all()]);
    }

    public function setTranslation(Request $request): JsonResponse
    {
        $locale = (string) $request->input('locale');
        $key = (string) $request->input('key');
        if (! preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $locale) || ! preg_match('/^[a-z0-9_.]{1,190}$/', $key)) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Use a locale like en or am-ET and a dotted lowercase key.', ['field' => 'key']);
        }
        $id = DB::table('translation_overrides')->where('locale', $locale)->where('key', $key)->value('id') ?? (string) Str::uuid7();
        DB::table('translation_overrides')->updateOrInsert(['locale' => $locale, 'key' => $key], ['id' => $id, 'value' => mb_substr((string) $request->input('value'), 0, 2000),
            'updated_by' => $this->actor($request)->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => ['locale' => $locale, 'key' => $key]]);
    }

    public function inventoryLocations(): JsonResponse
    {
        return response()->json(['data' => DB::table('inventory_locations')->whereNull('storage_location_id')->orderBy('location_type')->orderBy('code')
            ->get()->map(fn ($r) => (array) $r)->all()]);
    }

    public function reopenSetup(Request $request, SetupService $setup, SetupState $state): JsonResponse
    {
        $setup->reopen($this->actor($request));
        $state->refresh();

        return response()->json(['data' => ['setup_state' => $state->state()->value]]);
    }
}
