<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup;

use App\Domains\Platform\Enums\PlatformSetupStep;

/**
 * One Platform Setup step (Chapter 02 §2.5). Handlers are idempotent: re-submitting a step before
 * lock re-validates and re-applies it without duplicating seeded data.
 */
interface SetupStepHandler
{
    public function step(): PlatformSetupStep;

    /**
     * Validate and apply the step payload. Returns data shown back to the operator (e.g. recovery
     * codes, created ids). Throws ValidationException / DomainException on invalid input.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload, SetupContext $context): array;

    /**
     * Current values to pre-fill the wizard form.
     *
     * @return array<string, mixed>
     */
    public function current(): array;
}
