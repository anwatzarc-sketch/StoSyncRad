<?php

declare(strict_types=1);

namespace App\Support\Schema;

use Closure;

/**
 * Per-request context handed to the SchemaValidator: the operation, the actor's permission
 * checker and (for updates) the current state of the target record.
 */
final class ValidationContext
{
    /**
     * @param  'create'|'update'|'operation'  $operation
     * @param  Closure(string): bool  $can  permission checker for writableBy()
     */
    public function __construct(
        public readonly string $operation = 'create',
        public readonly ?Closure $can = null,
        public readonly ?string $currentState = null,
        public readonly bool $allowUnknown = false,
    ) {}

    public function can(string $permission): bool
    {
        return $this->can === null ? false : (bool) ($this->can)($permission);
    }

    public function withState(?string $state): self
    {
        return new self($this->operation, $this->can, $state, $this->allowUnknown);
    }
}
