<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** When attribute values create variants. */
enum VariantCreationMode: string implements SystemEnum
{
    use HasSystemEnum;

    case ALWAYS = 'always';
    case DYNAMIC = 'dynamic';
    case NEVER = 'never';
}
