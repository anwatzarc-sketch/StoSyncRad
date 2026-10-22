<?php

declare(strict_types=1);

namespace App\Domains\Platform\Services;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Actions\SaveRoleAction;
use App\Domains\IdentityAccess\Enums\SodRuleType;
use App\Domains\IdentityAccess\Models\Role;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Permissions\PermissionRegistry;
use App\Domains\IdentityAccess\Schemas\ApprovalTierLineSchema;
use App\Domains\Messaging\Services\WebhookService;
use App\Domains\Organization\Schemas\BranchSchema;
use App\Domains\Organization\Schemas\CompanyProfileSchema;
use App\Domains\Organization\Schemas\WarehouseSchema;
use App\Domains\Platform\Config\ConfigRegistry;
use App\Domains\Platform\Config\ConfigScopeType;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Models\BrandKit;
use App\Domains\Platform\Setup\ConfigApplier;
use App\Domains\Platform\Templates\SafeTemplateRenderer;
use App\Domains\ReportingEngine\Enums\ReportVisibility;
use App\Domains\ReportingEngine\Models\ReportDefinition;
use App\Domains\ReportingEngine\Services\ReportDefinitionService;
use App\Support\Enums\EnumLabelResolver;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Schema\PropertyType;
use App\Support\Schema\SchemaValidator;
use App\Support\Schema\TableSchema;
use App\Support\Schema\ValidationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Config Bundle (Chapter 03 §3.8): the portable configuration of a deployment as one JSON document.
 *
 * Sections (all optional): platform, company_profile, brand_kit, configuration, lookups, enum_labels,
 * translation_overrides, templates, organization, catalog_foundations, document_sequences, sod_rules,
 * approval_tiers, roles, report_definitions, integrations. Secrets and ids are never exported —
 * everything is keyed by natural codes so a bundle moves between deployments.
 *
 * export()  — this deployment's bundle; `checksum` covers the content (cloning and editing a
 *             reference bundle for a new client is expected, so a mismatch is a warning).
 * import()  — validated as a whole, then applied section by section in one transaction; `dryRun`
 *             rolls back and returns the same per-section report (the diff). organization,
 *             catalog_foundations, partners and accounts are applied by Platform Setup only.
 */
final class ConfigBundleService
{
    public const FORMAT = 'stosyncrad.config-bundle';

    public const VERSION = 1;

    public const IMPORT_SECTIONS = ['configuration', 'company_profile', 'lookups', 'enum_labels', 'translation_overrides', 'templates', 'document_sequences',
        'sod_rules', 'approval_tiers', 'roles', 'report_definitions', 'integrations', 'brand_kit'];

    public const SETUP_ONLY_SECTIONS = ['organization', 'catalog_foundations', 'partners', 'accounts', 'role_templates'];

    public function __construct(
        private readonly ConfigRegistry $registry,
        private readonly PlatformConfig $config,
        private readonly AuditRecorder $audit,
        private readonly SchemaValidator $validator,
        private readonly PermissionRegistry $permissions,
    ) {}

    // ---- Export ---------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function export(?UserAccount $actor): array
    {
        $configuration = [];
        foreach (DB::table('configuration_entries')->where('scope_type', ConfigScopeType::GLOBAL->value)->orderBy('key')->get(['key', 'value']) as $row) {
            if ($this->registry->has($row->key) && ! $this->registry->get($row->key)->sensitive) {
                $configuration[$row->key] = json_decode((string) $row->value, true);
            }
        }
        $company = DB::table('company_profile')->first();
        $brand = BrandKit::query()->where('is_active', true)->first();
        $roleCodes = DB::table('roles')->pluck('code', 'id');

        $bundle = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'platform' => ['version' => (string) config('platform.version', '2.0.0'), 'deployment_label' => $this->config->get('platform.deployment_label'),
                'exported_at' => now()->toIso8601String()],
            'company_profile' => $company === null ? null : self::portable(CompanyProfileSchema::class, (array) $company),
            'brand_kit' => $brand === null ? null : ['app_name' => $brand->app_name, 'app_short_name' => $brand->app_short_name, 'tagline' => $brand->tagline,
                'legal_footer' => $brand->legal_footer, 'support_email' => $brand->support_email, 'support_phone' => $brand->support_phone,
                'support_url' => $brand->support_url, 'theme' => $brand->theme, 'locale_defaults' => $brand->locale_defaults],
            'configuration' => $configuration,
            'lookups' => DB::table('lookups')->orderBy('code')->get(['id', 'code', 'label', 'description'])->map(fn ($l) => ['code' => $l->code, 'label' => $l->label,
                'description' => $l->description, 'values' => DB::table('lookup_values')->where('lookup_id', $l->id)->orderBy('sort_order')->orderBy('code')
                    ->get(['code', 'label', 'sort_order', 'is_active'])->map(fn ($v) => (array) $v)->all()])->all(),
            'enum_labels' => DB::table('enum_labels')->orderBy('enum_code')->orderBy('case_value')->orderBy('locale')
                ->get(['enum_code', 'case_value', 'locale', 'label', 'description'])->map(fn ($r) => (array) $r)->all(),
            'translation_overrides' => DB::table('translation_overrides')->orderBy('locale')->orderBy('key')->get(['locale', 'key', 'value'])->map(fn ($r) => (array) $r)->all(),
            'templates' => DB::table('templates')->where('is_system_default', false)->orderBy('code')->get(['code', 'name', 'engine', 'body'])->map(fn ($r) => (array) $r)->all(),
            'organization' => ['branches' => DB::table('branches')->where('status', '<>', 'closed')->orderBy('code')->get()
                ->map(fn ($b) => self::portable(BranchSchema::class, (array) $b) + ['warehouses' => DB::table('warehouses')->where('branch_id', $b->id)
                    ->where('status', '<>', 'closed')->orderBy('code')->get()->map(fn ($w) => self::portable(WarehouseSchema::class, (array) $w))->all()])->all()],
            'catalog_foundations' => ['taxonomy' => DB::table('item_categories')->whereNull('parent_id')->orderBy('code')->pluck('code')->all()],
            'document_sequences' => DB::table('document_sequences')->whereNull('scope_id')->where('period_key', '')->orderBy('document_type')
                ->get(['document_type', 'scope', 'prefix', 'pattern', 'reset_policy', 'sequence_padding'])
                ->mapWithKeys(fn ($r) => [$r->document_type => ['scope' => $r->scope, 'prefix' => $r->prefix, 'pattern' => $r->pattern,
                    'reset_policy' => $r->reset_policy, 'sequence_padding' => (int) $r->sequence_padding]])->all(),
            'sod_rules' => DB::table('sod_rules')->orderBy('code')->pluck('rule', 'code')->all(),
            'approval_tiers' => DB::table('approval_policies')->orderBy('document_type')->get(['document_type', 'tiers'])
                ->mapWithKeys(fn ($r) => [$r->document_type => json_decode((string) $r->tiers, true)])->all(),
            'roles' => Role::query()->where('is_system', false)->orderBy('code')->get()->map(fn (Role $r) => ['code' => $r->code, 'name' => $r->name,
                'description' => $r->description, 'permissions' => DB::table('role_permissions')->where('role_id', $r->id)->orderBy('permission_code')->pluck('permission_code')->all()])->all(),
            'report_definitions' => ReportDefinition::query()->where('is_system_seed', false)->where('is_active', true)
                ->where('visibility', '<>', ReportVisibility::PRIVATE->value)->orderBy('code')->get()
                ->map(fn (ReportDefinition $d) => ['code' => $d->code, 'name' => $d->name, 'description' => $d->description, 'dataset_code' => $d->dataset_code,
                    'definition' => $d->definition, 'tags' => array_values((array) $d->tags), 'visibility' => $d->visibility->value,
                    'shared_role_codes' => array_values(array_filter(array_map(fn ($id) => $roleCodes[$id] ?? null, (array) $d->shared_role_ids)))])->all(),
            'integrations' => ['webhooks' => DB::table('webhook_endpoints')->orderBy('name')->get(['name', 'url', 'event_types', 'is_active'])
                ->map(fn ($w) => ['name' => $w->name, 'url' => $w->url, 'event_types' => self::jsonList($w->event_types), 'is_active' => (bool) $w->is_active])->all()],
        ];
        $bundle['checksum'] = self::checksum($bundle);
        $this->audit->record($actor, 'platform.bundle.exported', 'platform_setup', (string) Str::uuid7(), null, ['checksum' => $bundle['checksum']]);

        return $bundle;
    }

    public static function checksum(array $bundle): string
    {
        unset($bundle['checksum'], $bundle['platform']['exported_at']);

        return hash('sha256', json_encode(self::canonical($bundle), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    // ---- Validation --------------------------------------------------------------------------------

    /**
     * Whole-bundle validation before anything is written.
     *
     * @return array{errors: array<int, array{section: string, message: string}>, warnings: array<int, array{section: string, message: string}>}
     */
    public function validate(array $bundle): array
    {
        $errors = [];
        $warnings = [];
        if (($bundle['format'] ?? null) !== self::FORMAT || (int) ($bundle['version'] ?? 0) !== self::VERSION) {
            return ['errors' => [['section' => 'format', 'message' => 'This is not a version '.self::VERSION.' configuration bundle (`format` must be `'.self::FORMAT.'`).']],
                'warnings' => []];
        }
        if (isset($bundle['checksum']) && $bundle['checksum'] !== self::checksum($bundle)) {
            $warnings[] = ['section' => 'checksum', 'message' => 'The bundle was edited after export (checksum differs).'];
        }
        foreach ((array) ($bundle['configuration'] ?? []) as $key => $value) {
            if (! $this->registry->has((string) $key)) {
                $errors[] = ['section' => 'configuration', 'message' => "`$key` is not a declared configuration key."];
            } elseif ($this->registry->get((string) $key)->sensitive) {
                $errors[] = ['section' => 'configuration', 'message' => "`$key` is a secret; set it in Administration, never in a bundle."];
            } else {
                try {
                    $this->registry->get((string) $key)->normalize($value);
                } catch (DomainException $e) {
                    $errors[] = ['section' => 'configuration', 'message' => "`$key`: ".$e->getMessage()];
                }
            }
        }
        foreach ((array) ($bundle['roles'] ?? []) as $i => $role) {
            if (! is_array($role) || ! preg_match('/^[a-z][a-z0-9_]{1,63}$/', (string) ($role['code'] ?? '')) || trim((string) ($role['name'] ?? '')) === '') {
                $errors[] = ['section' => 'roles', 'message' => "Role #$i needs a lower-case `code` and a `name`."];

                continue;
            }
            foreach ((array) ($role['permissions'] ?? []) as $code) {
                if (! str_contains((string) $code, '*') && ! $this->permissions->has((string) $code)) {
                    $warnings[] = ['section' => 'roles', 'message' => "Role `{$role['code']}`: unknown permission `$code` is ignored."];
                }
            }
        }
        foreach ((array) ($bundle['sod_rules'] ?? []) as $code => $rule) {
            if (SodRuleType::tryFrom((string) $rule) === null) {
                $errors[] = ['section' => 'sod_rules', 'message' => "`$code`: rule must be one of ".implode(', ', SodRuleType::values()).'.'];
            }
        }
        foreach ((array) ($bundle['approval_tiers'] ?? []) as $type => $tiers) {
            foreach (array_values((array) $tiers) as $n => $tier) {
                try {
                    $this->validator->validate(ApprovalTierLineSchema::class, (array) $tier, new ValidationContext('create'));
                } catch (Throwable $e) {
                    $errors[] = ['section' => 'approval_tiers', 'message' => "`$type` tier ".($n + 1).': '.$e->getMessage()];
                }
            }
        }
        foreach ((array) ($bundle['templates'] ?? []) as $t) {
            try {
                (new SafeTemplateRenderer)->validate((string) ($t['body'] ?? ''));
            } catch (Throwable $e) {
                $errors[] = ['section' => 'templates', 'message' => '`'.($t['code'] ?? '?').'`: '.$e->getMessage()];
            }
        }
        if (is_array($bundle['company_profile'] ?? null)) {
            try {
                $this->validator->validate(CompanyProfileSchema::class, $bundle['company_profile'], new ValidationContext('update'));
            } catch (Throwable $e) {
                $errors[] = ['section' => 'company_profile', 'message' => $e->getMessage()];
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    // ---- Import ------------------------------------------------------------------------------------

    /**
     * @param  array<int, string>|null  $sections  limit to these sections (null = IMPORT_SECTIONS)
     * @return array{dry_run: bool, sections: array<string, array<string, int>>, warnings: array<int, array{section: string, message: string}>, secrets: array<string, string>}
     */
    public function import(array $bundle, ?UserAccount $actor, bool $dryRun = false, ?array $sections = null): array
    {
        $check = $this->validate($bundle);
        if ($check['errors'] !== []) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The configuration bundle is invalid; nothing was applied.', ['errors' => $check['errors']]);
        }
        $sections ??= self::IMPORT_SECTIONS;
        $report = ['dry_run' => $dryRun, 'sections' => [], 'warnings' => $check['warnings'], 'secrets' => []];
        foreach (self::SETUP_ONLY_SECTIONS as $s) {
            if (! empty(array_filter((array) ($bundle[$s] ?? [])))) {
                $report['warnings'][] = ['section' => $s, 'message' => 'Applied by Platform Setup only; on a running deployment use Administration or imports.'];
            }
        }

        DB::beginTransaction();
        try {
            foreach (self::IMPORT_SECTIONS as $section) {
                $content = $section === 'integrations' ? array_filter((array) ($bundle[$section] ?? [])) : ($bundle[$section] ?? null);
                if (in_array($section, $sections, true) && ! empty($content)) {
                    $report['sections'][$section] = $this->{'apply'.Str::studly($section)}((array) $bundle[$section], $actor, $report);
                }
            }
            $this->audit->record($actor, $dryRun ? 'platform.bundle.dry_run' : 'platform.bundle.imported', 'platform_setup', (string) Str::uuid7(), null,
                ['sections' => $report['sections'], 'checksum' => $bundle['checksum'] ?? null]);
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            app(EnumLabelResolver::class)->forget();
            $this->config->forget();
            app(BrandResolver::class)->forget();
        }
        if ($dryRun) {
            $report['secrets'] = [];   // rolled back: nothing was created
        }

        return $report;
    }

    // ---- Section appliers: each returns counts (created / updated / unchanged) -----------------------

    private function applyConfiguration(array $values, ?UserAccount $actor): array
    {
        $prefixes = $this->registry->all()->map(fn ($d) => $d->group().'.')->unique()->values()->all();
        $changed = 0;
        foreach ($values as $key => $value) {
            $changed += $this->config->get((string) $key) === $this->registry->get((string) $key)->normalize($value) ? 0 : 1;
        }
        app(ConfigApplier::class)->apply($values, $prefixes, $actor, 'Configuration bundle import');

        return ['updated' => $changed, 'unchanged' => count($values) - $changed];
    }

    private function applyCompanyProfile(array $profile, ?UserAccount $actor): array
    {
        $data = $this->validator->validate(CompanyProfileSchema::class, $profile, new ValidationContext('update'));
        $values = CompanyProfileSchema::columns($data);
        foreach (['registered_address', 'operational_address'] as $json) {
            if (array_key_exists($json, $values)) {
                $values[$json] = $values[$json] === null ? null : json_encode($values[$json]);
            }
        }
        $row = DB::table('company_profile')->first();
        if ($row === null) {
            DB::table('company_profile')->insert($values + ['id' => (string) Str::uuid7(), 'singleton' => true, 'created_by' => $actor?->id, 'created_at' => now(), 'updated_at' => now()]);

            return ['created' => 1];
        }
        DB::table('company_profile')->where('id', $row->id)->update($values + ['updated_by' => $actor?->id, 'updated_at' => now()]);

        return ['updated' => 1];
    }

    private function applyLookups(array $lookups): array
    {
        $out = ['created' => 0, 'updated' => 0];
        foreach ($lookups as $l) {
            $id = DB::table('lookups')->where('code', $l['code'])->value('id');
            if ($id === null) {
                $id = (string) Str::uuid7();
                DB::table('lookups')->insert(['id' => $id, 'code' => $l['code'], 'label' => $l['label'], 'description' => $l['description'] ?? null,
                    'is_system' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ((array) ($l['values'] ?? []) as $v) {
                $existing = DB::table('lookup_values')->where('lookup_id', $id)->where('code', $v['code'])->first();
                $row = ['label' => $v['label'], 'sort_order' => (int) ($v['sort_order'] ?? 0), 'is_active' => (bool) ($v['is_active'] ?? true), 'updated_at' => now()];
                if ($existing === null) {
                    DB::table('lookup_values')->insert($row + ['id' => (string) Str::uuid7(), 'lookup_id' => $id, 'code' => $v['code'], 'is_system' => false, 'created_at' => now()]);
                    $out['created']++;
                } elseif ($existing->label !== $row['label'] || (int) $existing->sort_order !== $row['sort_order'] || (bool) $existing->is_active !== $row['is_active']) {
                    DB::table('lookup_values')->where('id', $existing->id)->update($row);
                    $out['updated']++;
                }
            }
        }

        return $out;
    }

    private function applyEnumLabels(array $labels, ?UserAccount $actor): array
    {
        $out = ['created' => 0, 'updated' => 0];
        foreach ($labels as $e) {
            $existing = DB::table('enum_labels')->where('enum_code', $e['enum_code'])->where('case_value', $e['case_value'])->where('locale', $e['locale'])->first();
            if ($existing === null) {
                DB::table('enum_labels')->insert(['id' => (string) Str::uuid7(), 'enum_code' => $e['enum_code'], 'case_value' => $e['case_value'], 'locale' => $e['locale'],
                    'label' => $e['label'], 'description' => $e['description'] ?? null, 'updated_by' => $actor?->id, 'created_at' => now(), 'updated_at' => now()]);
                $out['created']++;
            } elseif ($existing->label !== $e['label'] || $existing->description !== ($e['description'] ?? null)) {
                DB::table('enum_labels')->where('id', $existing->id)->update(['label' => $e['label'], 'description' => $e['description'] ?? null,
                    'updated_by' => $actor?->id, 'updated_at' => now()]);
                $out['updated']++;
            }
        }

        return $out;
    }

    private function applyTranslationOverrides(array $rows, ?UserAccount $actor): array
    {
        $out = ['created' => 0, 'updated' => 0];
        foreach ($rows as $r) {
            $value = mb_substr((string) $r['value'], 0, 2000);
            $existing = DB::table('translation_overrides')->where('locale', $r['locale'])->where('key', $r['key'])->first();
            if ($existing === null) {
                DB::table('translation_overrides')->insert(['id' => (string) Str::uuid7(), 'locale' => $r['locale'], 'key' => $r['key'], 'value' => $value,
                    'updated_by' => $actor?->id, 'created_at' => now(), 'updated_at' => now()]);
                $out['created']++;
            } elseif ($existing->value !== $value) {
                DB::table('translation_overrides')->where('id', $existing->id)->update(['value' => $value, 'updated_by' => $actor?->id, 'updated_at' => now()]);
                $out['updated']++;
            }
        }

        return $out;
    }

    private function applyTemplates(array $templates, ?UserAccount $actor): array
    {
        $out = ['created' => 0, 'updated' => 0];
        foreach ($templates as $t) {
            $existing = DB::table('templates')->where('code', $t['code'])->first();
            if ($existing === null) {
                DB::table('templates')->insert(['id' => (string) Str::uuid7(), 'code' => $t['code'], 'name' => $t['name'], 'engine' => $t['engine'], 'body' => $t['body'],
                    'version' => 1, 'is_system_default' => false, 'is_active' => true, 'created_by' => $actor?->id, 'created_at' => now(), 'updated_at' => now()]);
                $out['created']++;
            } elseif ($existing->body !== $t['body']) {
                DB::table('template_versions')->insert(['id' => (string) Str::uuid7(), 'template_id' => $existing->id, 'version' => $existing->version, 'body' => $existing->body,
                    'changed_by' => $actor?->id, 'created_at' => now()]);
                DB::table('templates')->where('id', $existing->id)->update(['body' => $t['body'], 'name' => $t['name'], 'version' => $existing->version + 1,
                    'is_system_default' => false, 'updated_by' => $actor?->id, 'updated_at' => now()]);
                $out['updated']++;
            }
        }

        return $out;
    }

    private function applyDocumentSequences(array $sequences): array
    {
        $out = ['updated' => 0, 'unchanged' => 0];
        foreach ($sequences as $type => $s) {
            $pattern = (string) ($s['pattern'] ?? '');
            $prefix = (string) ($s['prefix'] ?? '');
            if (! preg_match('/\{SEQ(:\d+)?\}/', $pattern) || ! preg_match('/^[A-Z0-9_-]{0,12}$/', $prefix)) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Numbering for `$type`: the pattern must contain {SEQ}; prefixes are up to 12 upper-case letters/digits.");
            }
            $padding = max(1, min(12, (int) ($s['sequence_padding'] ?? 6)));
            $n = DB::table('document_sequences')->where('document_type', $type)->whereNull('scope_id')->where('period_key', '')
                ->where(fn ($q) => $q->where('prefix', '<>', $prefix)->orWhere('pattern', '<>', $pattern)->orWhere('sequence_padding', '<>', $padding))
                ->update(['prefix' => $prefix, 'pattern' => $pattern, 'sequence_padding' => $padding, 'updated_at' => now()]);
            $n > 0 ? $out['updated']++ : $out['unchanged']++;
        }

        return $out;
    }

    private function applySodRules(array $rules, ?UserAccount $actor): array
    {
        $out = ['updated' => 0, 'unchanged' => 0];
        foreach ($rules as $code => $rule) {
            $n = DB::table('sod_rules')->where('code', $code)->where('rule', '<>', $rule)->update(['rule' => $rule, 'updated_by' => $actor?->id, 'updated_at' => now()]);
            $n > 0 ? $out['updated']++ : $out['unchanged']++;
        }

        return $out;
    }

    private function applyApprovalTiers(array $policies, ?UserAccount $actor): array
    {
        $out = ['updated' => 0, 'unchanged' => 0];
        foreach ($policies as $type => $tiers) {
            $current = DB::table('approval_policies')->where('document_type', $type)->first();
            if ($current === null || json_decode((string) $current->tiers, true) == $tiers) {
                $out['unchanged']++;

                continue;
            }
            DB::table('approval_policies')->where('id', $current->id)->update(['tiers' => json_encode(array_values((array) $tiers)), 'updated_by' => $actor?->id, 'updated_at' => now()]);
            $out['updated']++;
        }

        return $out;
    }

    private function applyRoles(array $roles, ?UserAccount $actor): array
    {
        $out = ['created' => 0, 'updated' => 0];
        $save = app(SaveRoleAction::class);
        foreach ($roles as $r) {
            $existing = Role::query()->where('code', $r['code'])->first();
            if ($existing?->is_system) {
                continue;
            }
            $permissions = array_values(array_filter((array) $r['permissions'], fn ($c) => str_contains((string) $c, '*') || $this->permissions->has((string) $c)));
            $save->execute(['code' => $r['code'], 'name' => $r['name'], 'description' => $r['description'] ?? null, 'permissions' => $permissions], $actor, $existing);
            $existing === null ? $out['created']++ : $out['updated']++;
        }

        return $out;
    }

    private function applyReportDefinitions(array $definitions, ?UserAccount $actor): array
    {
        $actor ??= $this->owner();
        $service = app(ReportDefinitionService::class);
        $out = ['created' => 0, 'updated' => 0];
        foreach ($definitions as $d) {
            $data = array_filter(['code' => $d['code'] ?? null, 'name' => $d['name'], 'description' => $d['description'] ?? null, 'dataset_code' => $d['dataset_code'],
                'definition' => (array) $d['definition'], 'tags' => (array) ($d['tags'] ?? [])], fn ($v) => $v !== null);
            $existing = isset($d['code']) ? ReportDefinition::query()->where('code', $d['code'])->first() : null;
            $def = $existing === null ? $service->create($data, $actor) : $service->update($existing->id, $data, $actor);
            $existing === null ? $out['created']++ : $out['updated']++;
            $visibility = ReportVisibility::tryFrom((string) ($d['visibility'] ?? ''));
            if ($visibility !== null && $visibility !== $def->visibility) {
                $service->share($def->id, $visibility, DB::table('roles')->whereIn('code', (array) ($d['shared_role_codes'] ?? []))->pluck('id')->all(), $actor);
            }
        }

        return $out;
    }

    private function applyIntegrations(array $integrations, ?UserAccount $actor, array &$report): array
    {
        $out = ['created' => 0, 'updated' => 0];
        foreach ((array) ($integrations['webhooks'] ?? []) as $w) {
            $existing = DB::table('webhook_endpoints')->where('url', $w['url'])->first();
            if ($existing === null) {
                $created = app(WebhookService::class)->register((string) $w['name'], (string) $w['url'], (array) $w['event_types'], $actor ?? $this->owner());
                $report['secrets']['webhook:'.$w['url']] = $created['secret'];
                $out['created']++;
            } else {
                $events = json_encode(array_values(array_unique(array_map('strval', (array) $w['event_types']))), JSON_THROW_ON_ERROR);
                DB::table('webhook_endpoints')->where('id', $existing->id)->update(['name' => $w['name'], 'event_types' => $events,
                    'is_active' => (bool) ($w['is_active'] ?? true), 'updated_at' => now()]);
                $out['updated']++;
            }
        }

        return $out;
    }

    private function applyBrandKit(array $brand, ?UserAccount $actor): array
    {
        $fields = ['app_name', 'app_short_name', 'tagline', 'legal_footer', 'support_email', 'support_phone', 'support_url', 'theme', 'locale_defaults'];
        $active = BrandKit::query()->where('is_active', true)->first();
        if ($active !== null && collect($fields)->every(fn ($f) => $active->{$f} == ($brand[$f] ?? null))) {
            return ['unchanged' => 1];
        }
        app(BrandKitService::class)->createAndActivate(array_intersect_key($brand, array_flip($fields)) + ['imported_from' => 'bundle',
            'contrast_override_reason' => $brand['contrast_override_reason'] ?? null], $actor);

        return ['created' => 1];
    }

    // ---- Helpers ---------------------------------------------------------------------------------

    /**
     * Portable view of a row through its schema: writable, non-reference properties only (ids differ per deployment).
     *
     * @param  class-string<TableSchema>  $schema
     * @return array<string, mixed>
     */
    public static function portable(string $schema, array $row): array
    {
        $out = [];
        foreach ($schema::properties() as $name => $p) {
            if ($p->serverControlled || $p->virtual || $p->type === PropertyType::UUID || ! array_key_exists($name, $row) || $row[$name] === null) {
                continue;
            }
            $value = $row[$name];
            if ($p->type === PropertyType::ARRAY && is_string($value)) {
                $value = self::jsonList($value);
            } elseif ($p->type === PropertyType::JSON && is_string($value)) {
                $value = json_decode($value, true);
            } elseif ($p->type === PropertyType::BOOLEAN) {
                $value = (bool) $value;
            }
            $out[$name] = $value;
        }

        return $out;
    }

    private function owner(): UserAccount
    {
        return UserAccount::query()->where('is_super_admin', true)->orderBy('created_at')->first()
            ?? throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'This section needs an owning account; create the Super Admin first.');
    }

    /** A JSON array column as read through the query builder (a JSON string). @return array<int, string> */
    private static function jsonList(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return $value === null || $value === '' ? [] : array_values((array) json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
