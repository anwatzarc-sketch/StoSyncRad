<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Services;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\ReportingEngine\Catalog\DatasetCatalog;
use App\Domains\ReportingEngine\Enums\ReportVisibility;
use App\Domains\ReportingEngine\Models\ReportDefinition;
use App\Domains\ReportingEngine\Models\ReportDefinitionVersion;
use App\Domains\ReportingEngine\Query\QueryCompiler;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Report definitions (Chapter 10 §10.3): every change is a new version in the append-only history;
 * definitions are validated by compiling them for the author (so nobody can save a report over
 * fields they may not see); visibility private / shared with roles / company-wide; system seeds
 * are read-only but cloneable.
 */
final class ReportDefinitionService
{
    public function __construct(
        private readonly QueryCompiler $compiler,
        private readonly DatasetCatalog $catalog,
        private readonly Access $access,
        private readonly PlatformConfig $config,
        private readonly AuditRecorder $audit,
    ) {}

    /** Definitions the account can read: own, shared with one of its roles, company-wide, system seeds. */
    public function visibleTo(UserAccount $account): Builder
    {
        $roleIds = DB::table('role_assignments')->where('user_account_id', $account->id)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))->pluck('role_id')->all();

        return ReportDefinition::query()->where('is_active', true)->where(function (Builder $q) use ($account, $roleIds) {
            $q->where('owner_account_id', $account->id)->orWhere('is_system_seed', true)
                ->orWhere('visibility', ReportVisibility::SHARED_COMPANY->value);
            if ($roleIds !== []) {
                $q->orWhere(fn ($w) => $w->where('visibility', ReportVisibility::SHARED_ROLES->value)
                    ->whereRaw('JSON_OVERLAPS(shared_role_ids, ?)', [json_encode(array_values($roleIds), JSON_THROW_ON_ERROR)]));
            }
        });
    }

    public function assertCanRead(ReportDefinition $def, UserAccount $account): void
    {
        if ($account->is_super_admin || $def->owner_account_id === $account->id) {
            return;
        }
        if (! $this->visibleTo($account)->whereKey($def->id)->exists()) {
            throw DomainException::of(ErrorCode::PERMISSION_DENIED, 'This report is not shared with you.');
        }
    }

    /** @param  array<string, mixed>  $data  name, description, dataset_code, definition, tags, code? */
    public function create(array $data, UserAccount $actor, bool $systemSeed = false): ReportDefinition
    {
        if (! $systemSeed && ! $this->access->can($actor, 'reporting.definition.create')) {
            throw DomainException::permissionDenied('reporting.definition.create');
        }
        $this->validate($data['dataset_code'] ?? '', (array) ($data['definition'] ?? []), $actor);

        return DB::transaction(function () use ($data, $actor, $systemSeed) {
            $def = ReportDefinition::query()->forceCreate([
                'code' => $data['code'] ?? Str::slug($data['name'], '_').'_'.Str::lower(Str::random(6)), 'name' => $data['name'],
                'description' => $data['description'] ?? null, 'owner_account_id' => $systemSeed ? null : $actor->id,
                'visibility' => $systemSeed ? ReportVisibility::SHARED_COMPANY : ReportVisibility::PRIVATE, 'dataset_code' => $data['dataset_code'],
                'definition' => $data['definition'], 'definition_version' => 1, 'is_system_seed' => $systemSeed, 'is_active' => true,
                'shared_role_ids' => [], 'tags' => array_values((array) ($data['tags'] ?? [])), 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $this->version($def, $actor, 'Created');
            $this->audit->record($actor, 'reporting.definition.created', 'report_definitions', $def->id, null, ['name' => $def->name, 'dataset' => $def->dataset_code]);

            return $def;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(string $id, array $data, UserAccount $actor, ?int $expectedVersion = null): ReportDefinition
    {
        return DB::transaction(function () use ($id, $data, $actor, $expectedVersion) {
            $def = ReportDefinition::query()->lockForUpdate()->findOrFail($id);
            $this->assertCanEdit($def, $actor);
            if ($expectedVersion !== null && $expectedVersion !== $def->definition_version) {
                throw DomainException::staleVersion($expectedVersion, $def->definition_version);
            }
            if (isset($data['definition']) || isset($data['dataset_code'])) {
                $this->validate($data['dataset_code'] ?? $def->dataset_code, (array) ($data['definition'] ?? $def->definition), $actor);
            }
            $def->forceFill(array_intersect_key($data, array_flip(['name', 'description', 'dataset_code', 'definition', 'tags']))
                + ['definition_version' => $def->definition_version + 1, 'updated_by' => $actor->id])->save();
            $this->version($def, $actor, $data['change_note'] ?? null);
            $this->audit->record($actor, 'reporting.definition.updated', 'report_definitions', $def->id, null, ['version' => $def->definition_version]);

            return $def;
        });
    }

    public function clone(string $id, UserAccount $actor, ?string $name = null): ReportDefinition
    {
        if (! $this->access->can($actor, 'reporting.definition.clone')) {
            throw DomainException::permissionDenied('reporting.definition.clone');
        }
        $source = ReportDefinition::query()->findOrFail($id);
        $this->assertCanRead($source, $actor);

        return $this->create(['name' => $name ?? $source->name.' (copy)', 'description' => $source->description, 'dataset_code' => $source->dataset_code,
            'definition' => $source->definition, 'tags' => $source->tags], $actor);
    }

    /** @param  array<int, string>  $roleIds */
    public function share(string $id, ReportVisibility $visibility, array $roleIds, UserAccount $actor): ReportDefinition
    {
        if (! $this->access->can($actor, 'reporting.definition.share')) {
            throw DomainException::permissionDenied('reporting.definition.share');
        }
        if ($visibility === ReportVisibility::SHARED_COMPANY && ! $this->config->bool('reporting.sharing.allow_company_wide')) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Company-wide sharing is disabled.');
        }
        if ($visibility === ReportVisibility::SHARED_ROLES && $roleIds === []) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Select at least one role to share with.', ['field' => 'shared_role_ids']);
        }

        return DB::transaction(function () use ($id, $visibility, $roleIds, $actor) {
            $def = ReportDefinition::query()->lockForUpdate()->findOrFail($id);
            $this->assertCanEdit($def, $actor);
            $before = ['visibility' => $def->visibility->value, 'roles' => $def->shared_role_ids];
            $def->forceFill(['visibility' => $visibility, 'shared_role_ids' => $visibility === ReportVisibility::SHARED_ROLES ? array_values(array_unique($roleIds)) : [],
                'updated_by' => $actor->id])->save();
            $this->audit->record($actor, 'reporting.definition.shared', 'report_definitions', $def->id, $before,
                ['visibility' => $visibility->value, 'roles' => $def->shared_role_ids]);

            return $def;
        });
    }

    public function archive(string $id, UserAccount $actor): ReportDefinition
    {
        return DB::transaction(function () use ($id, $actor) {
            $def = ReportDefinition::query()->lockForUpdate()->findOrFail($id);
            $this->assertCanEdit($def, $actor);
            $def->forceFill(['is_active' => false, 'updated_by' => $actor->id])->save();
            $this->audit->record($actor, 'reporting.definition.archived', 'report_definitions', $def->id);

            return $def;
        });
    }

    /** Compile against the author (no execution) — rejects unknown fields, bad joins, forbidden fields, invalid expressions. */
    public function validate(string $dataset, array $definition, UserAccount $actor): void
    {
        $declared = array_column((array) ($definition['parameters'] ?? []), null, 'code');
        $sample = [];
        foreach ($declared as $code => $p) {
            $sample[$code] = $p['default'] ?? match ((string) ($p['type'] ?? 'string')) {
                'date' => now()->toDateString(), 'datetime' => now()->toIso8601String(), 'integer', 'decimal', 'money', 'quantity' => '0',
                'uuid' => (string) Str::uuid7(), 'boolean' => true, 'enum' => $p['sample'] ?? 'x', default => 'x',
            };
        }
        $this->compiler->compile($dataset, $definition, $actor, $sample, 1);
    }

    private function assertCanEdit(ReportDefinition $def, UserAccount $actor): void
    {
        if ($def->is_system_seed) {
            if (! $this->access->can($actor, 'reporting.definition.manage_system')) {
                throw DomainException::of(ErrorCode::PERMISSION_DENIED, 'System reports are read-only; clone it to make changes.');
            }

            return;
        }
        if ($def->owner_account_id !== $actor->id && ! $actor->is_super_admin) {
            throw DomainException::of(ErrorCode::PERMISSION_DENIED, 'Only the owner can change this report.');
        }
        if (! $this->access->can($actor, 'reporting.definition.update')) {
            throw DomainException::permissionDenied('reporting.definition.update');
        }
    }

    private function version(ReportDefinition $def, UserAccount $actor, ?string $note): void
    {
        ReportDefinitionVersion::query()->forceCreate(['report_definition_id' => $def->id, 'definition_version' => $def->definition_version,
            'name' => $def->name, 'definition' => $def->definition, 'changed_by' => $actor->id, 'change_note' => $note, 'created_at' => now()]);
    }
}
