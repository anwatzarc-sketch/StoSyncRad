<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Actions;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Enums\PermissionGrantSource;
use App\Domains\IdentityAccess\Enums\RoleStatus;
use App\Domains\IdentityAccess\Models\Permission;
use App\Domains\IdentityAccess\Models\Role;
use App\Domains\IdentityAccess\Models\RolePermission;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Permissions\PermissionRegistry;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Messaging\Services\Outbox;
use App\Domains\Organization\Enums\DocumentType;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates an admin-defined role: a name plus a set of endpoint permissions
 * (Chapter 04 §4.5). Implied permissions are materialized with granted_via = implied.
 * Optional per-permission constraints (approval limits) are stored on the direct grant.
 */
final class SaveRoleAction
{
    /** Constraint keys accepted on role permissions. */
    public const CONSTRAINT_KEYS = ['max_value', 'max_quantity'];

    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly Access $access,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated against RoleSchema
     */
    public function execute(array $data, ?UserAccount $actor, ?Role $role = null, bool $fromTemplate = false): Role
    {
        return DB::transaction(function () use ($data, $actor, $role, $fromTemplate) {
            if ($role !== null) {
                $role = Role::query()->lockForUpdate()->findOrFail($role->id);
                if ($role->is_system) {
                    throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'System roles cannot be modified.');
                }
                if ($role->status === RoleStatus::ARCHIVED) {
                    throw DomainException::of(ErrorCode::INVALID_STATE_TRANSITION, 'Archived roles cannot be modified.');
                }
            }
            $before = $role === null ? null : ['name' => $role->name, 'permissions' => $role->permissions()->where('granted_via', 'direct')->pluck('permission_code')->sort()->values()->all()];

            if ($role === null) {
                $role = new Role(['code' => $data['code'], 'name' => $data['name'], 'description' => $data['description'] ?? null]);
                $role->forceFill(['status' => RoleStatus::ACTIVE, 'is_system' => false, 'is_template_origin' => $fromTemplate, 'created_by' => $actor?->id]);
            } else {
                $role->fill(array_intersect_key($data, array_flip(['name', 'description'])));
            }
            $role->forceFill(['updated_by' => $actor?->id])->save();

            if (array_key_exists('permissions', $data) && $data['permissions'] !== null) {
                $this->syncPermissions($role, (array) $data['permissions'], (array) ($data['permission_constraints'] ?? []));
            }

            $after = ['name' => $role->name, 'permissions' => $role->permissions()->where('granted_via', 'direct')->pluck('permission_code')->sort()->values()->all()];
            $this->audit->record($actor, $before === null ? 'identity.role.created' : 'identity.role.updated', 'role', $role->id, $before, $after);
            $this->outbox->publish('identity.role.changed', 'role', $role->id, ['code' => $role->code]);
            $this->access->forget();

            return $role;
        });
    }

    /**
     * @param  array<int, string>  $codes
     * @param  array<string, array<string, mixed>>  $constraints
     */
    private function syncPermissions(Role $role, array $codes, array $constraints): void
    {
        $codes = array_values(array_unique($codes));
        $unknown = array_values(array_filter($codes, fn (string $c) => ! $this->registry->has($c)));
        if ($unknown !== []) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Unknown permission codes.', ['unknown' => $unknown]);
        }
        foreach ($constraints as $code => $set) {
            if (! in_array($code, $codes, true) || ! is_array($set) || array_diff(array_keys($set), self::CONSTRAINT_KEYS) !== []) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Invalid constraints for `$code`.", ['allowed_keys' => self::CONSTRAINT_KEYS]);
            }
            foreach ($set as $value) {
                if (! is_numeric($value) || (float) $value < 0) {
                    throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Constraint values for `$code` must be non-negative numbers.");
                }
            }
        }

        $direct = array_fill_keys($codes, true);
        $all = $this->registry->expand($codes);
        $ids = Permission::query()->whereIn('code', $all)->pluck('id', 'code');
        $missing = array_diff($all, $ids->keys()->all());
        if ($missing !== []) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Permissions are not synced yet; run permissions:sync.', ['missing' => array_values($missing)]);
        }

        RolePermission::query()->where('role_id', $role->id)->delete();
        $rows = [];
        foreach ($all as $code) {
            $rows[] = [
                'id' => (string) \Illuminate\Support\Str::uuid7(),
                'role_id' => $role->id,
                'permission_id' => $ids[$code],
                'permission_code' => $code,
                'granted_via' => isset($direct[$code]) ? PermissionGrantSource::DIRECT->value : PermissionGrantSource::IMPLIED->value,
                'constraints' => isset($constraints[$code]) ? json_encode(array_map('strval', $constraints[$code])) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('role_permissions')->insert($chunk);
        }
    }

    /** Helper used by approval tiers to name the approve permission of a document type. */
    public static function approvePermissionFor(DocumentType $type): ?string
    {
        return match ($type) {
            DocumentType::PURCHASE_REQUEST => 'procurement.purchase_request.approve',
            DocumentType::PURCHASE_ORDER => 'procurement.purchase_order.approve',
            DocumentType::GOODS_RECEIPT => 'receiving.goods_receipt.approve',
            DocumentType::SUPPLIER_RETURN => 'procurement.supplier_return.approve',
            DocumentType::TRANSFER_ORDER => 'transfers.transfer.approve',
            DocumentType::STOCK_REQUEST => 'fulfillment.stock_request.approve',
            DocumentType::STOCK_ISSUE => 'fulfillment.stock_issue.approve',
            DocumentType::INTERNAL_RETURN => 'fulfillment.internal_return.approve',
            DocumentType::STOCKTAKE => 'stocktaking.stocktake.approve',
            DocumentType::STOCK_ADJUSTMENT => 'stocktaking.adjustment.approve',
            DocumentType::ALLOCATION_PLAN => 'planning.allocation_plan.approve',
            DocumentType::OPENING_BALANCE => 'inventory.opening_balance.approve',
            DocumentType::SCRAP => 'inventory.scrap.approve',
            DocumentType::CONDITION_CHANGE => 'inventory.condition.change',
            default => null,
        };
    }
}
