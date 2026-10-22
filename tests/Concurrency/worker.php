<?php

/**
 * Concurrency test worker: boots the application in its own process (own MySQL session),
 * waits for a shared start instant, performs one posting and prints a JSON result.
 *
 * Usage: php worker.php '<json>'
 *   {"op":"issue","item_id":"…","warehouse_id":"…","qty":"70","lot_id":null,"start_at":1700000000.5}
 *   {"op":"dispatch","item_id":"…","warehouse_id":"…","to_warehouse_id":"…","qty":"70","start_at":…}
 */

declare(strict_types=1);

use App\Domains\Inventory\Enums\MovementType;
use App\Domains\Inventory\Enums\StockCondition;
use App\Domains\Inventory\Posting\InventoryPoster;
use App\Domains\Inventory\Posting\MovementLine;
use App\Domains\Inventory\Posting\MovementRequest;
use App\Domains\Inventory\Services\InventoryLocationResolver;
use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\InventoryLocationType;
use App\Domains\Organization\Enums\StorageLocationUsage;
use App\Domains\Organization\Models\Warehouse;
use App\Support\Exceptions\DomainException;
use App\Support\Transactions\PostingContext;
use App\Support\Transactions\PostingOutcome;
use App\Support\Transactions\PostingPipeline;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);

// Establish the DB session before the barrier so both workers race on the posting itself.
DB::select('select 1');
while (microtime(true) < (float) $args['start_at']) {
    usleep(500);
}

$resolver = app(InventoryLocationResolver::class);
$warehouse = Warehouse::query()->findOrFail($args['warehouse_id']);
$from = $resolver->warehouseDefault($warehouse, StorageLocationUsage::STORAGE)->id;
[$type, $docType, $to] = match ($args['op']) {
    'dispatch' => [MovementType::TRANSFER_DISPATCH, DocumentType::TRANSFER_DISPATCH, $resolver->inTransit($warehouse->id, $args['to_warehouse_id'])->id],
    default => [MovementType::ISSUE, DocumentType::STOCK_ISSUE, $resolver->virtual(InventoryLocationType::CUSTOMER)->id],
};

$docId = (string) Str::uuid7();
try {
    $outcome = app(PostingPipeline::class)->run(null, 'concurrency.'.$args['op'], (string) Str::uuid7(), ['doc' => $docId],
        function (PostingContext $ctx) use ($args, $type, $docType, $from, $to, $docId, $warehouse) {
            $movement = app(InventoryPoster::class)->post(new MovementRequest($type, $docType, $docId, 'CC-'.substr($docId, -6), $docId, [
                new MovementLine($args['item_id'], null, $from, StockCondition::AVAILABLE, $to, StockCondition::AVAILABLE, (string) $args['qty'],
                    lotId: $args['lot_id'] ?? null),
            ], warehouseId: $warehouse->id), $ctx);

            return new PostingOutcome(['movement_id' => $movement->id], 201);
        });
    echo json_encode(['ok' => true, 'movement_id' => $outcome->body['movement_id'], 'retries' => $outcome->retries]);
} catch (DomainException $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode->value]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'UNEXPECTED', 'error' => get_class($e).': '.$e->getMessage()]);
}
