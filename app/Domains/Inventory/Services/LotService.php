<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Services;

use App\Domains\Catalog\Enums\ExpiryPolicy;
use App\Domains\Catalog\Enums\LotNumberPolicy;
use App\Domains\Catalog\Enums\LotUniquenessScope;
use App\Domains\Catalog\Enums\QcPolicy;
use App\Domains\Catalog\Enums\SerialUniquenessScope;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Services\CategoryPolicyResolver;
use App\Domains\Inventory\Enums\LotLockStatus;
use App\Domains\Inventory\Enums\LotQcStatus;
use App\Domains\Inventory\Enums\LotStatus;
use App\Domains\Inventory\Enums\SerialStatus;
use App\Domains\Inventory\Models\InventoryLot;
use App\Domains\Inventory\Models\ItemSerial;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lot and serial registration (Chapter 07 §7.7–7.8). Lot numbers come from the supplier or the
 * configured pattern; expiry-derived dates (best-before, removal, alert) are computed here.
 */
final class LotService
{
    public function __construct(private readonly PlatformConfig $config, private readonly CategoryPolicyResolver $policies) {}

    /**
     * Find or create a lot during receipt/opening balance.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function resolveForReceipt(
        Item $item,
        ?string $variantId,
        ?string $lotNumber,
        ?string $supplierId,
        ?string $manufactureDate,
        ?string $expiryDate,
        string $originDocumentType,
        string $originDocumentId,
        ?string $actorId,
        array $attributes = [],
    ): InventoryLot {
        $policy = $item->lot_number_policy;
        if ($lotNumber === null || $lotNumber === '') {
            if ($policy === LotNumberPolicy::SUPPLIER_PROVIDED) {
                throw DomainException::of(ErrorCode::TRACKING_MODE_REQUIRES_LOT, "{$item->label()} requires the supplier's lot number.");
            }
            $lotNumber = $this->generateNumber($item);
        }

        $expiryPolicy = $this->policies->expiryPolicy($item);
        if ($expiryPolicy === ExpiryPolicy::TRACKED_REQUIRED && $expiryDate === null) {
            if ($item->shelf_life_days !== null && $manufactureDate !== null) {
                $expiryDate = CarbonImmutable::parse($manufactureDate)->addDays($item->shelf_life_days)->toDateString();
            } else {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, "{$item->label()} requires an expiry date for lot {$lotNumber}.");
            }
        }

        $scope = LotUniquenessScope::from((string) $this->config->get('catalog.lot_uniqueness_scope'));
        $query = InventoryLot::query()->where('item_id', $item->id)->where('lot_number', $lotNumber);
        if ($scope === LotUniquenessScope::ITEM_SUPPLIER) {
            $supplierId === null ? $query->whereNull('supplier_id') : $query->where('supplier_id', $supplierId);
        }
        /** @var InventoryLot|null $existing */
        $existing = $query->lockForUpdate()->first();
        if ($existing !== null) {
            if ($expiryDate !== null && $existing->expiry_date !== null && $existing->expiry_date->toDateString() !== $expiryDate) {
                throw DomainException::of(ErrorCode::LOT_MISMATCH,
                    "Lot {$lotNumber} already exists with expiry {$existing->expiry_date->toDateString()}.", ['lot_id' => $existing->id]);
            }

            return $existing;
        }
        if ($scope === LotUniquenessScope::ITEM && $supplierId !== null
            && InventoryLot::query()->where('item_id', $item->id)->where('lot_number', $lotNumber)->exists()) {
            throw DomainException::of(ErrorCode::LOT_MISMATCH, "Lot number {$lotNumber} is already used for this item.");
        }

        $qcPolicy = $this->policies->qcPolicy($item);
        $dates = $this->derivedDates($item, $expiryDate);

        return InventoryLot::query()->create([
            'item_id' => $item->id,
            'item_variant_id' => $variantId,
            'lot_number' => $lotNumber,
            'supplier_id' => $supplierId,
            'supplier_lot_reference' => $attributes['supplier_lot_reference'] ?? null,
            'manufacture_date' => $manufactureDate,
            'expiry_date' => $expiryDate,
            'best_before_date' => $dates['best_before'],
            'removal_date' => $dates['removal'],
            'alert_date' => $dates['alert'],
            'origin_document_type' => $originDocumentType,
            'origin_document_id' => $originDocumentId,
            'qc_status' => $qcPolicy === QcPolicy::NONE ? LotQcStatus::RELEASED : LotQcStatus::PENDING,
            'qc_released_at' => $qcPolicy === QcPolicy::NONE ? now() : null,
            'lock_status' => LotLockStatus::UNLOCKED,
            'status' => LotStatus::ACTIVE,
            'attributes' => $attributes === [] ? null : $attributes,
            'created_by' => $actorId,
        ]);
    }

    /** @return array{best_before: ?string, removal: ?string, alert: ?string} */
    public function derivedDates(Item $item, ?string $expiryDate): array
    {
        if ($expiryDate === null) {
            return ['best_before' => null, 'removal' => null, 'alert' => null];
        }
        $expiry = CarbonImmutable::parse($expiryDate);

        return [
            'best_before' => $item->best_before_offset_days !== null ? $expiry->subDays($item->best_before_offset_days)->toDateString() : null,
            'removal' => $item->removal_offset_days !== null ? $expiry->subDays($item->removal_offset_days)->toDateString() : null,
            'alert' => $item->alert_offset_days !== null ? $expiry->subDays($item->alert_offset_days)->toDateString() : null,
        ];
    }

    public function generateNumber(Item $item): string
    {
        $pattern = (string) $this->config->get('catalog.lot_number.pattern', ['item' => $item->id, 'category' => $item->category_id]);
        $date = now();
        $prefix = strtr($pattern, ['{ITEM}' => $item->sku, '{YYMMDD}' => $date->format('ymd'), '{YYYY}' => $date->format('Y')]);
        $base = preg_replace('/\{SEQ(?::\d+)?\}.*$/', '', $prefix) ?? $prefix;
        $count = InventoryLot::query()->where('item_id', $item->id)->where('lot_number', 'like', addcslashes($base, '%_').'%')->count();

        for ($n = $count + 1; ; $n++) {
            $candidate = preg_replace_callback('/\{SEQ(?::(\d+))?\}/', static fn (array $m) => str_pad((string) $n, (int) ($m[1] ?? 4), '0', STR_PAD_LEFT), $prefix) ?? $prefix;
            if (! InventoryLot::query()->where('item_id', $item->id)->where('lot_number', $candidate)->exists()) {
                return $candidate;
            }
        }
    }

    /**
     * Register (or re-admit) a serial for an incoming movement.
     * A serial already in stock anywhere is rejected; sold/issued/lost serials may re-enter via returns.
     */
    public function resolveSerial(Item $item, ?string $variantId, string $serialNumber, ?string $lotId, bool $allowReentry = false): ItemSerial
    {
        $scope = SerialUniquenessScope::from((string) $this->config->get('catalog.serial_uniqueness_scope'));
        $query = ItemSerial::query()->where('serial_number', $serialNumber);
        if ($scope === SerialUniquenessScope::ITEM) {
            $query->where('item_id', $item->id);
        }
        /** @var ItemSerial|null $serial */
        $serial = $query->lockForUpdate()->first();
        if ($serial !== null) {
            if ($serial->item_id !== $item->id) {
                throw DomainException::of(ErrorCode::SERIAL_STATE_INVALID, "Serial {$serialNumber} belongs to another item.");
            }
            $reentry = [SerialStatus::SOLD, SerialStatus::ISSUED, SerialStatus::LOST, SerialStatus::REGISTERED];
            if ($serial->current_inventory_location_id !== null || ! ($serial->lifecycle_status === SerialStatus::REGISTERED || ($allowReentry && in_array($serial->lifecycle_status, $reentry, true)))) {
                throw DomainException::of(ErrorCode::SERIAL_STATE_INVALID, "Serial {$serialNumber} is already registered ({$serial->lifecycle_status->value}).",
                    ['serial_id' => $serial->id]);
            }

            return $serial;
        }

        $id = (string) Str::uuid7();
        DB::table('item_serials')->insert([
            'id' => $id, 'item_id' => $item->id, 'item_variant_id' => $variantId, 'serial_number' => $serialNumber,
            'inventory_lot_id' => $lotId, 'lifecycle_status' => SerialStatus::REGISTERED->value, 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ItemSerial::query()->findOrFail($id);
    }
}
