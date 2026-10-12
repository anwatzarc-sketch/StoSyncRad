<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Posting;

use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Organization\Enums\DocumentType;
use InvalidArgumentException;

/**
 * A complete posting event: one ledger movement header with its lines (Chapter 07 §7.4).
 * posting_event_id is unique — the id of the record that represents this event (receipt,
 * dispatch, issue…) — so the same event can never be posted twice.
 */
final class MovementRequest
{
    /**
     * @param  array<int, MovementLine>  $lines
     * @param  array<int, string>  $permittedFrozenStocktakeIds  stocktakes whose frozen positions this posting may touch
     */
    public function __construct(
        public readonly MovementType $type,
        public readonly DocumentType $documentType,
        public readonly string $documentId,
        public readonly string $documentNumber,
        public readonly string $postingEventId,
        public readonly array $lines,
        public readonly ?string $businessDate = null,
        public readonly ?string $warehouseId = null,
        public readonly ?string $counterpartyWarehouseId = null,
        public readonly ?string $partnerId = null,
        public readonly ?string $reversalOfMovementId = null,
        public readonly ?string $reasonLookupValueId = null,
        public readonly ?string $reasonText = null,
        public readonly array $permittedFrozenStocktakeIds = [],
        public readonly bool $allowLockedLots = false,
        public readonly bool $allowExpired = false,
    ) {
        if ($lines === []) {
            throw new InvalidArgumentException('A movement needs at least one line.');
        }
    }
}
