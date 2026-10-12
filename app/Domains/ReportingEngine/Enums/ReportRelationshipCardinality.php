<?php

declare(strict_types=1);

namespace App\Domains\ReportingEngine\Enums;

use App\Support\Enums\HasSystemEnum;
use App\Support\Enums\SystemEnum;

/** Cardinality of a dataset relationship (joins to "many" multiply rows and are flagged in the builder). */
enum ReportRelationshipCardinality: string implements SystemEnum
{
    use HasSystemEnum;

    case MANY_TO_ONE = 'many_to_one';
    case ONE_TO_ONE = 'one_to_one';
    case ONE_TO_MANY = 'one_to_many';
}
