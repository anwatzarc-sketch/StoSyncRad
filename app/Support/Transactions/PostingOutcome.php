<?php

declare(strict_types=1);

namespace App\Support\Transactions;

/**
 * Result of a committed (or replayed) posting operation.
 */
final class PostingOutcome
{
    /** @param  array<string,mixed>  $body */
    public function __construct(
        public readonly array $body,
        public readonly int $status = 201,
        public readonly ?string $documentType = null,
        public readonly ?string $documentId = null,
        public readonly bool $replayed = false,
        public readonly int $retries = 0,
    ) {}

    public function asReplay(): self
    {
        return new self($this->body, 200, $this->documentType, $this->documentId, true);
    }
}
