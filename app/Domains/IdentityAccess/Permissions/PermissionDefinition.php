<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Permissions;

use App\Domains\IdentityAccess\Enums\PermissionScopeKind;
use App\Domains\IdentityAccess\Enums\PermissionSensitivity;
use InvalidArgumentException;

/**
 * One permission declared in code and bound to endpoints (Chapter 04 §4.4).
 */
final class PermissionDefinition
{
    public readonly string $module;

    public readonly string $resource;

    public readonly string $action;

    /**
     * @param  array<int, string>  $implies  permission codes automatically granted with this one
     */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $description,
        public readonly PermissionScopeKind $scopeKind = PermissionScopeKind::GLOBAL,
        public readonly PermissionSensitivity $sensitivity = PermissionSensitivity::NORMAL,
        public readonly array $implies = [],
        public readonly bool $virtual = false,
        public readonly ?string $featureFlag = null,
    ) {
        if (preg_match('/^[a-z_]+\.[a-z_]+\.[a-z_]+$/', $code) !== 1) {
            throw new InvalidArgumentException("Permission code `$code` must be module.resource.action");
        }
        [$this->module, $this->resource, $this->action] = explode('.', $code, 3);
    }

    /** Shorthand for a global permission. */
    public static function global(string $code, string $label, string $description = '', PermissionSensitivity $sensitivity = PermissionSensitivity::NORMAL, array $implies = [], bool $virtual = false, ?string $feature = null): self
    {
        return new self($code, $label, $description ?: $label, PermissionScopeKind::GLOBAL, $sensitivity, $implies, $virtual, $feature);
    }

    /** Shorthand for a warehouse-scoped permission. */
    public static function warehouse(string $code, string $label, string $description = '', PermissionSensitivity $sensitivity = PermissionSensitivity::NORMAL, array $implies = [], ?string $feature = null): self
    {
        return new self($code, $label, $description ?: $label, PermissionScopeKind::WAREHOUSE, $sensitivity, $implies, false, $feature);
    }

    /** Shorthand for a branch-scoped permission. */
    public static function branch(string $code, string $label, string $description = '', PermissionSensitivity $sensitivity = PermissionSensitivity::NORMAL, array $implies = []): self
    {
        return new self($code, $label, $description ?: $label, PermissionScopeKind::BRANCH, $sensitivity, $implies);
    }

    /** Shorthand for an own-records permission. */
    public static function own(string $code, string $label, string $description = ''): self
    {
        return new self($code, $label, $description ?: $label, PermissionScopeKind::OWN_RECORDS);
    }

    /**
     * Expand "module.resource.read|create|update" style shorthand into definitions.
     *
     * @param  array<string, array{label?: string, sensitivity?: PermissionSensitivity, implies?: array<int,string>}>  $overrides  action → overrides
     * @return array<int, self>
     */
    public static function crud(string $prefix, string $resourceLabel, array $actions, PermissionScopeKind $scope = PermissionScopeKind::GLOBAL, array $overrides = [], ?string $feature = null): array
    {
        $out = [];
        foreach ($actions as $action) {
            $o = $overrides[$action] ?? [];
            $implies = $o['implies'] ?? ($action === 'read' ? [] : ["$prefix.read"]);
            $out[] = new self(
                "$prefix.$action",
                $o['label'] ?? (ucfirst(str_replace('_', ' ', $action)).' '.$resourceLabel),
                $o['description'] ?? (ucfirst(str_replace('_', ' ', $action)).' '.$resourceLabel.'.'),
                $scope,
                $o['sensitivity'] ?? PermissionSensitivity::NORMAL,
                $implies,
                false,
                $feature,
            );
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'module' => $this->module,
            'resource' => $this->resource,
            'action' => $this->action,
            'label' => $this->label,
            'description' => $this->description,
            'scope_kind' => $this->scopeKind->value,
            'sensitivity' => $this->sensitivity->value,
            'implies' => $this->implies,
            'is_virtual' => $this->virtual,
            'feature_flag' => $this->featureFlag,
        ];
    }
}
