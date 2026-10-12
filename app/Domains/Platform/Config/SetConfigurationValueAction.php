<?php

declare(strict_types=1);

namespace App\Domains\Platform\Config;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Messaging\Services\Outbox;
use App\Domains\Platform\Models\ConfigurationEntry;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * The only write path for configuration values (Chapter 03 §3.2):
 * validate → compare-and-set on version → history row → audit → cache invalidation → outbox.
 */
final class SetConfigurationValueAction
{
    public function __construct(
        private readonly ConfigRegistry $registry,
        private readonly PlatformConfig $config,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
    ) {}

    public function execute(
        string $key,
        mixed $value,
        ?UserAccount $actor,
        ConfigScopeType $scope = ConfigScopeType::GLOBAL,
        ?string $scopeId = null,
        ?int $expectedVersion = null,
        ?string $reason = null,
    ): ConfigurationEntry {
        $definition = $this->registry->get($key);

        if (! $definition->allowsScope($scope)) {
            throw DomainException::of(ErrorCode::CONFIG_SCOPE_NOT_ALLOWED,
                "`{$key}` cannot be overridden at the {$scope->value} scope.", ['key' => $key, 'scope' => $scope->value]);
        }
        if ($scope !== ConfigScopeType::GLOBAL && $scopeId === null) {
            throw DomainException::of(ErrorCode::CONFIG_SCOPE_NOT_ALLOWED, 'A scope id is required for non-global scopes.');
        }

        $normalized = $definition->normalize($value);
        $stored = $definition->sensitive && is_string($normalized) ? encrypt($normalized) : $normalized;
        $encoded = json_encode($stored, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $entry = DB::transaction(function () use ($key, $scope, $scopeId, $encoded, $definition, $expectedVersion, $actor, $reason, $normalized) {
            /** @var ConfigurationEntry|null $entry */
            $entry = ConfigurationEntry::query()
                ->where('key', $key)->where('scope_type', $scope->value)
                ->when($scopeId === null, fn ($q) => $q->whereNull('scope_id'), fn ($q) => $q->where('scope_id', $scopeId))
                ->lockForUpdate()->first();

            $previous = $entry?->value;

            if ($entry === null) {
                $entry = new ConfigurationEntry([
                    'key' => $key, 'scope_type' => $scope, 'scope_id' => $scopeId,
                    'value' => $encoded, 'value_type' => $definition->type, 'version' => 1, 'updated_by' => $actor?->id,
                ]);
                $entry->save();
            } else {
                if ($expectedVersion !== null && $entry->version !== $expectedVersion) {
                    throw DomainException::staleVersion($expectedVersion, $entry->version);
                }
                $entry->forceFill(['value' => $encoded, 'value_type' => $definition->type, 'version' => $entry->version + 1, 'updated_by' => $actor?->id])->save();
            }

            DB::table('configuration_entry_versions')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid7(),
                'configuration_entry_id' => $entry->id,
                'key' => $key,
                'scope_type' => $scope->value,
                'scope_id' => $scopeId,
                'value' => $encoded,
                'version' => $entry->version,
                'changed_by' => $actor?->id,
                'reason' => $reason,
                'created_at' => now(),
            ]);

            $this->audit->record(
                actor: $actor,
                eventType: 'platform.config.changed',
                subjectType: 'configuration_entry',
                subjectId: $entry->id,
                before: $definition->sensitive ? ['value' => '***'] : ['value' => $previous !== null ? json_decode($previous, true) : null],
                after: $definition->sensitive ? ['value' => '***'] : ['value' => $normalized],
                reason: $reason,
                context: ['key' => $key, 'scope' => $scope->value, 'scope_id' => $scopeId, 'version' => $entry->version],
            );

            $this->outbox->publish('platform.config.changed', 'configuration_entry', $entry->id, [
                'key' => $key, 'scope' => $scope->value, 'scope_id' => $scopeId, 'version' => $entry->version,
                'restart_required' => $definition->restartRequired,
            ]);

            return $entry;
        });

        $this->config->forget();

        return $entry;
    }
}
