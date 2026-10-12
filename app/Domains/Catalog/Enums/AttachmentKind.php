<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Item attachment kind. */
enum AttachmentKind: string implements SystemEnum
{
    use HasSystemEnum;

    case DATASHEET = 'datasheet';
    case MSDS = 'msds';
    case CERTIFICATE = 'certificate';
    case IMAGE = 'image';
    case OTHER = 'other';
    case PAYMENT_EVIDENCE = 'payment_evidence';
    case DELIVERY_PHOTO = 'delivery_photo';
}
