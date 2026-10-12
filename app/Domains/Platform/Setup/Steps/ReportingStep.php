<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup\Steps;

use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Setup\ConfigApplier;
use App\Domains\Platform\Setup\SetupContext;
use App\Domains\ReportingEngine\Services\ReportingSetup;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;

/**
 * Step 13 — reporting engine: limits/renderers, read-only role probe, dataset catalog sync and
 * the seeded standard report definitions.
 * Payload: {config: {reporting.*}, seed_standard_reports: bool}
 */
final class ReportingStep extends ConfigOnlyStep
{
    public function __construct(ConfigApplier $applier, private readonly ReportingSetup $reporting)
    {
        parent::__construct($applier);
    }

    public function step(): PlatformSetupStep
    {
        return PlatformSetupStep::REPORTING;
    }

    protected function prefixes(): array
    {
        return ['reporting.'];
    }

    protected function after(array $payload, SetupContext $context): void
    {
        $probe = $this->reporting->probeReadOnly();
        if (! $probe['read_only']) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'The reporting database role is not read-only.', $probe);
        }
        $this->reporting->syncDatasets();
        if (($payload['seed_standard_reports'] ?? true) === true) {
            $this->reporting->seedStandardReports();
        }
    }

    public function current(): array
    {
        return parent::current() + ['probe' => $this->reporting->probeReadOnly(), 'datasets' => $this->reporting->datasetSummary()];
    }
}
