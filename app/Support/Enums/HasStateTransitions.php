<?php

declare(strict_types=1);

namespace App\Support\Enums;

use App\Support\Exceptions\DomainException;

/**
 * Default StateEnum behaviour. Implementing enums define static transitions().
 */
trait HasStateTransitions
{
    public function canTransitionTo(StateEnum $to): bool
    {
        $allowed = static::transitions()[$this->value] ?? [];

        return in_array($to->value, $allowed, true);
    }

    public function isTerminal(): bool
    {
        return (static::transitions()[$this->value] ?? []) === [];
    }

    /** @return array<int, string> */
    public function allowedTransitions(): array
    {
        return static::transitions()[$this->value] ?? [];
    }

    /**
     * Guard used by actions before changing a document's state.
     *
     * @throws DomainException INVALID_STATE_TRANSITION
     */
    public function assertCanTransitionTo(StateEnum $to, string $subject = 'document'): void
    {
        if (! $this->canTransitionTo($to)) {
            throw DomainException::invalidStateTransition($subject, $this->value, $to->value, $this->allowedTransitions());
        }
    }
}
