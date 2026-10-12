<?php

declare(strict_types=1);

namespace App\Support\Testing;

use RuntimeException;

/**
 * Deterministic fault injection for atomicity tests (Chapter 09 §9.12, Chapter 15 §15.6).
 *
 * Production code calls FaultInjector::point('name') at each critical posting step. The call is
 * a no-op unless a test has armed that point, in which case it throws InjectedFault — proving
 * that a failure at that step leaves no partial committed state. Arming is refused outside the
 * testing environment.
 */
final class FaultInjector
{
    public const POINTS = [
        'after_document_validation',
        'after_position_locks',
        'after_first_ledger_line',
        'after_balance_update',
        'after_reservation_change',
        'after_audit',
        'after_outbox',
        'before_commit',
    ];

    /** @var array<string, int> point → remaining hits before throwing (1 = next call) */
    private static array $armed = [];

    public static function arm(string $point, int $onHit = 1): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Fault injection is only available in the testing environment.');
        }
        self::$armed[$point] = $onHit;
    }

    public static function reset(): void
    {
        self::$armed = [];
    }

    public static function point(string $point): void
    {
        if (self::$armed === [] || ! isset(self::$armed[$point])) {
            return;
        }
        if (--self::$armed[$point] <= 0) {
            unset(self::$armed[$point]);
            throw new InjectedFault("Injected fault at `$point`.");
        }
    }
}
