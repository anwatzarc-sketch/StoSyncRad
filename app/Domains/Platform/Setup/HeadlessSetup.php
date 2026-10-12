<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Platform\Enums\BrandAssetKind;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Models\PlatformSetup;
use App\Domains\Platform\Services\BrandKitService;
use App\Domains\Platform\Services\ConfigBundleService;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Headless Platform Setup from a Config Bundle (Chapter 02 §2.3, Chapter 16 §16.4): runs every step
 * in order through SetupService — the same handlers, validation and audit as the wizard — mapping
 * bundle sections onto step payloads, then locks. Re-running after a failure resumes: completed
 * steps are idempotent; the Super Admin is created once. The bundle never carries the Super Admin
 * password; MFA enrolment is required at that account's first sign-in.
 */
final class HeadlessSetup
{
    /** Configuration prefixes owned by each step (mirrors the step handlers). */
    private const STEP_PREFIXES = [
        'localization' => ['locale.', 'platform.timezone_display_mode'],
        'identity_security' => ['identity.', 'security.'],
        'catalog_foundations' => ['catalog.', 'inventory.'],
        'stock_approval_policies' => ['stock.', 'procurement.', 'receiving.', 'transfers.', 'fulfillment.', 'stocktaking.', 'approval.'],
        'reporting' => ['reporting.'],
        'notifications_integrations' => ['messaging.', 'integration.', 'feature.', 'api.'],
    ];

    /** Sections applied through the bundle importer once the Super Admin exists. */
    private const EXTRA_SECTIONS = ['lookups', 'enum_labels', 'translation_overrides', 'templates', 'report_definitions', 'integrations'];

    private const MAX_BRAND_ZIP_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly SetupService $setup,
        private readonly ConfigBundleService $bundles,
        private readonly BrandKitService $brands,
        private readonly ConfigApplier $applier,
    ) {}

    /**
     * @param  array{full_name: string, email: string, username?: ?string, password: string}  $admin
     * @param  (Closure(string): void)|null  $progress
     * @return array{steps: array<string, string>, super_admin: string, warnings: array<int, array{section: string, message: string}>, secrets: array<string, string>}
     */
    public function run(array $bundle, array $admin, ?string $brandZip = null, ?Closure $progress = null): array
    {
        $progress ??= static function (string $line): void {};
        $check = $this->bundles->validate($bundle);
        if ($check['errors'] !== []) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The configuration bundle is invalid; setup did not start.', ['errors' => $check['errors']]);
        }
        foreach (['company_profile', 'brand_kit', 'organization'] as $required) {
            if (empty($bundle[$required]) && ! ($required === 'brand_kit' && $brandZip !== null)) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Headless setup needs the `$required` section.", ['section' => $required]);
            }
        }

        $row = $this->setup->current();
        if ($row->state === PlatformSetupState::COMPLETED) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Platform Setup is already completed and locked.');
        }
        if ($row->state === PlatformSetupState::NOT_STARTED) {
            $this->setup->bootstrap();   // opens setup; a headless run needs no browser token
        }

        $configuration = (array) ($bundle['configuration'] ?? []);
        $consumed = [];
        $pick = function (array $prefixes) use ($configuration, &$consumed): array {
            $subset = array_filter($configuration, fn ($k) => Str::startsWith((string) $k, $prefixes), ARRAY_FILTER_USE_KEY);
            $consumed += array_fill_keys(array_keys($subset), true);

            return $subset;
        };
        $steps = [];
        $context = new SetupContext(null, headless: true);
        $do = function (PlatformSetupStep $step, ?array $payload) use (&$context, &$steps, $progress): array {
            if ($payload === null) {
                $this->setup->skip($step, $context);
                $steps[$step->value] = 'skipped';
                $progress("· {$step->label()} — skipped");

                return [];
            }
            $result = $this->setup->complete($step, $payload, $context);
            $steps[$step->value] = 'completed';
            $progress("✓ {$step->label()}");

            return $result;
        };

        $do(PlatformSetupStep::WELCOME, array_filter(['acknowledge' => true, 'deployment_label' => $bundle['platform']['deployment_label'] ?? null], fn ($v) => $v !== null));
        $consumed['platform.deployment_label'] = true;

        if (PlatformSetup::query()->firstOrFail()->isStepCompleted(PlatformSetupStep::SUPER_ADMIN)) {
            $actor = UserAccount::query()->where('is_super_admin', true)->orderBy('created_at')->firstOrFail();
            $steps['super_admin'] = 'already completed';
            $progress('✓ Super Admin — already created ('.$actor->username.')');
        } else {
            $created = $do(PlatformSetupStep::SUPER_ADMIN, $admin);
            $actor = UserAccount::query()->findOrFail($created['account_id']);
        }
        $context = new SetupContext($actor, headless: true);

        $do(PlatformSetupStep::COMPANY_PROFILE, (array) $bundle['company_profile']);
        $do(PlatformSetupStep::BRAND_KIT, $this->brandPayload((array) ($bundle['brand_kit'] ?? []), $brandZip));
        $do(PlatformSetupStep::LOCALIZATION, ['config' => $pick(self::STEP_PREFIXES['localization'])]);
        $do(PlatformSetupStep::IDENTITY_SECURITY, ['config' => $pick(self::STEP_PREFIXES['identity_security']), 'current_password' => $admin['password']]);
        $do(PlatformSetupStep::ORGANIZATION, (array) $bundle['organization']);
        $do(PlatformSetupStep::CATALOG_FOUNDATIONS, ['config' => $pick(self::STEP_PREFIXES['catalog_foundations']),
            'taxonomy' => $bundle['catalog_foundations']['taxonomy'] ?? 'all']);
        $do(PlatformSetupStep::PARTNERS, empty($bundle['partners']) ? null : ['partners' => (array) $bundle['partners']]);
        $do(PlatformSetupStep::DOCUMENT_NUMBERING, ['sequences' => (array) ($bundle['document_sequences'] ?? [])]);
        $do(PlatformSetupStep::STOCK_APPROVAL_POLICIES, ['config' => $pick(self::STEP_PREFIXES['stock_approval_policies']),
            'sod_rules' => (array) ($bundle['sod_rules'] ?? []), 'approval_tiers' => (array) ($bundle['approval_tiers'] ?? [])]);
        $do(PlatformSetupStep::ROLES_ACCESS, ['templates' => $bundle['role_templates'] ?? 'all', 'roles' => (array) ($bundle['roles'] ?? []),
            'accounts' => (array) ($bundle['accounts'] ?? [])]);
        $do(PlatformSetupStep::REPORTING, ['config' => $pick(self::STEP_PREFIXES['reporting']), 'seed_standard_reports' => true]);
        $do(PlatformSetupStep::NOTIFICATIONS_INTEGRATIONS, ['config' => $pick(self::STEP_PREFIXES['notifications_integrations'])]);
        $do(PlatformSetupStep::OPENING_DATA, null);

        // Keys no step owns (db.*, exchange.*, planning.*, platform.*) are applied as the Super Admin.
        $remaining = array_diff_key($configuration, $consumed);
        if ($remaining !== []) {
            $prefixes = array_values(array_unique(array_map(fn ($k) => Str::before((string) $k, '.').'.', array_keys($remaining))));
            $this->applier->apply($remaining, $prefixes, $actor, 'Headless Platform Setup');
            $progress('✓ Other configuration ('.count($remaining).' keys)');
        }
        $extras = $this->bundles->import($bundle, $actor, false, self::EXTRA_SECTIONS);
        $progress('✓ Lookups, labels, templates, reports and integrations');

        PlatformSetup::query()->update(['config_bundle_checksum' => ConfigBundleService::checksum($bundle)]);
        $do(PlatformSetupStep::REVIEW_LOCK, []);

        return ['steps' => $steps, 'super_admin' => $actor->username, 'warnings' => array_merge($check['warnings'], $extras['warnings']), 'secrets' => $extras['secrets']];
    }

    /**
     * Brand kit step payload: bundle fields, overridden by `brand.json` in the zip, plus uploaded assets
     * from `assets/<kind>.<ext>` (kinds: logo_light, logo_dark, logo_mark, favicon_source, pwa_icon_source, …).
     *
     * @return array<string, mixed>
     */
    private function brandPayload(array $fields, ?string $zipPath): array
    {
        if ($zipPath === null) {
            return $fields;
        }
        if (! is_file($zipPath) || filesize($zipPath) > self::MAX_BRAND_ZIP_BYTES) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The brand kit zip is missing or larger than 20 MB.');
        }
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'The brand kit is not a readable zip file.');
        }
        $dir = storage_path('app/private/tmp/brandkit-'.Str::uuid7());
        File::ensureDirectoryExists($dir);
        try {
            $assets = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $base = basename($name);
                if (str_contains($name, '..') || str_ends_with($name, '/') || $base === '') {
                    continue;
                }
                if (strtolower($base) === 'brand.json') {
                    $json = json_decode((string) $zip->getFromIndex($i), true);
                    if (! is_array($json)) {
                        throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'brand.json in the brand kit is not valid JSON.');
                    }
                    $fields = array_replace_recursive($fields, $json);

                    continue;
                }
                $kind = BrandAssetKind::tryFrom(strtolower(pathinfo($base, PATHINFO_FILENAME)));
                if ($kind === null || $kind === BrandAssetKind::DERIVED_ICON) {
                    continue;
                }
                $path = $dir.DIRECTORY_SEPARATOR.$kind->value.'.'.strtolower(pathinfo($base, PATHINFO_EXTENSION));
                file_put_contents($path, (string) $zip->getFromIndex($i));
                $assets[$kind->value] = $this->brands->storeUpload(new UploadedFile($path, $base, null, null, true), $kind);
            }

            return ['assets' => $assets + (array) ($fields['assets'] ?? [])] + $fields;
        } finally {
            $zip->close();
            File::deleteDirectory($dir);
        }
    }
}
