<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup\Steps;

use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Services\Preflight;
use App\Domains\Platform\Setup\ConfigApplier;
use App\Domains\Platform\Setup\SetupContext;
use App\Domains\Platform\Setup\SetupStepHandler;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;

/** Step 1 — acknowledge, deployment label, clean preflight. */
final class WelcomeStep implements SetupStepHandler
{
    public function __construct(private readonly Preflight $preflight, private readonly ConfigApplier $applier) {}

    public function step(): PlatformSetupStep
    {
        return PlatformSetupStep::WELCOME;
    }

    public function handle(array $payload, SetupContext $context): array
    {
        if (($payload['acknowledge'] ?? false) !== true) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Please acknowledge the licence terms to continue.', ['field' => 'acknowledge']);
        }
        $results = $this->preflight->run();
        if (Preflight::hasFailures($results)) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Environment preflight has failures.', ['preflight' => $results]);
        }
        if (isset($payload['deployment_label'])) {
            $this->applier->apply(['platform.deployment_label' => (string) $payload['deployment_label']], ['platform.'], $context->actor);
        }

        return ['preflight' => $results];
    }

    public function current(): array
    {
        return ['preflight' => $this->preflight->run(), 'version' => config('platform.version')];
    }
}
