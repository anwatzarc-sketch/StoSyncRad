<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * Business-rule failure carrying a machine-readable ErrorCode, HTTP status and details.
 * Never retried automatically (Chapter 09 §9.8). Rendered by ApiErrorResponder.
 */
class DomainException extends RuntimeException
{
    /** @param  array<string, mixed>  $details */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly ?string $recoveryHint = null,
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->errorCode->httpStatus();
    }

    /** @param  array<string, mixed>  $details */
    public static function of(ErrorCode $code, string $message, array $details = [], ?string $hint = null): static
    {
        return new static($code, $message, $details, $hint);
    }

    /** @param  array<int, string>  $allowed */
    public static function invalidStateTransition(string $subject, string $from, string $to, array $allowed): static
    {
        return new static(ErrorCode::INVALID_STATE_TRANSITION,
            "The $subject cannot move from `$from` to `$to`.",
            ['from' => $from, 'to' => $to, 'allowed' => $allowed],
            'Refresh the record and choose one of the allowed actions.');
    }

    public static function insufficientStock(string $itemLabel, string $requested, string $available, array $position = []): static
    {
        return new static(ErrorCode::INSUFFICIENT_STOCK,
            "Insufficient stock for $itemLabel: requested $requested, available $available.",
            ['requested_quantity' => $requested, 'available_quantity' => $available] + $position,
            'Reduce the quantity or choose another stock position.');
    }

    public static function permissionDenied(string $permission, ?string $warehouseId = null): static
    {
        return new static(ErrorCode::PERMISSION_DENIED,
            'You do not have the required permission for this operation.',
            array_filter(['permission' => $permission, 'warehouse_id' => $warehouseId]));
    }

    public static function featureDisabled(string $feature): static
    {
        return new static(ErrorCode::FEATURE_DISABLED, 'This feature is disabled for this deployment.', ['feature' => $feature]);
    }

    public static function alreadyPosted(string $subject, string $documentNumber): static
    {
        return new static(ErrorCode::ALREADY_POSTED, "$subject $documentNumber has already been posted.", ['document_number' => $documentNumber]);
    }

    public static function staleVersion(int $expected, int $actual): static
    {
        return new static(ErrorCode::STALE_VERSION, 'The record was modified by someone else.',
            ['expected_version' => $expected, 'current_version' => $actual], 'Reload the record and reapply your changes.');
    }

    public static function notFound(string $subject): static
    {
        return new static(ErrorCode::NOT_FOUND, "$subject was not found.");
    }

    public static function sodViolation(string $rule, string $explanation): static
    {
        return new static(ErrorCode::SOD_VIOLATION, $explanation, ['rule' => $rule],
            'A different authorized user must perform this step.');
    }

    public static function lockTimeout(): static
    {
        return new static(ErrorCode::LOCK_TIMEOUT, 'The stock position is busy; please retry with the same Idempotency-Key.',
            [], 'Retry shortly with the same Idempotency-Key.');
    }

    public static function retriesExhausted(): static
    {
        return new static(ErrorCode::CONCURRENCY_RETRIES_EXHAUSTED,
            'The operation conflicted repeatedly with concurrent transactions and was not applied.',
            [], 'Retry with the same Idempotency-Key.');
    }
}
