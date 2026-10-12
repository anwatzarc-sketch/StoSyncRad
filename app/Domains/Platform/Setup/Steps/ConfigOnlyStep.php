<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup\Steps;

use App\Domains\Platform\Setup\ConfigApplier;
use App\Domains\Platform\Setup\SetupContext;
use App\Domains\Platform\Setup\SetupStepHandler;

/**
 * Base for steps that only set configuration keys under owned prefixes.
 * Payload: {"config": {"key": value, …}}
 */
abstract class ConfigOnlyStep implements SetupStepHandler
{
    public function __construct(protected readonly ConfigApplier $applier) {}

    /** @return array<int, string> */
    abstract protected function prefixes(): array;

    public function handle(array $payload, SetupContext $context): array
    {
        $applied = $this->applier->apply((array) ($payload['config'] ?? []), $this->prefixes(), $context->actor);
        $this->after($payload, $context);

        return ['applied' => array_keys($applied)];
    }

    /** @param  array<string, mixed>  $payload */
    protected function after(array $payload, SetupContext $context): void {}

    public function current(): array
    {
        return ['config' => $this->applier->current($this->prefixes()), 'definitions' => $this->applier->definitions($this->prefixes())];
    }
}
