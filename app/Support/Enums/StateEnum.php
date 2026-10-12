<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * A SystemEnum describing a document/record lifecycle with code-defined transitions.
 */
interface StateEnum extends SystemEnum
{
    /**
     * Allowed transitions: [ 'from_case_value' => ['to_case_value', ...], ... ].
     *
     * @return array<string, array<int, string>>
     */
    public static function transitions(): array;

    public function canTransitionTo(self $to): bool;

    public function isTerminal(): bool;
}
