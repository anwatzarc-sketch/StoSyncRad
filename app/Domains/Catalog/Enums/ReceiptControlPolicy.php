<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Supplier billing control basis. */
enum ReceiptControlPolicy: string implements SystemEnum
{
    use HasSystemEnum;

    case ORDERED_QTY = 'ordered_qty';
    case RECEIVED_QTY = 'received_qty';
}
