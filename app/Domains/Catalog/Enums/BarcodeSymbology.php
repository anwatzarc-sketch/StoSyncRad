<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Barcode symbology. */
enum BarcodeSymbology: string implements SystemEnum
{
    use HasSystemEnum;

    case EAN13 = 'ean13';
    case EAN8 = 'ean8';
    case UPC_A = 'upc_a';
    case CODE128 = 'code128';
    case CODE39 = 'code39';
    case QR = 'qr';
    case DATAMATRIX = 'datamatrix';
    case ITF14 = 'itf14';
    case CUSTOM = 'custom';
}
