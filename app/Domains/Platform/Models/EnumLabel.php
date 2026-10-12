<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Support\Eloquent\BaseModel;

/**
 * Client-editable, localized label for one enum case (Chapter 03 §3.7).
 *
 * @property string $enum_code
 * @property string $case_value
 * @property string $locale
 * @property string $label
 * @property string|null $description
 */
final class EnumLabel extends BaseModel
{
    protected $table = 'enum_labels';

    protected $fillable = ['enum_code', 'case_value', 'locale', 'label', 'description', 'updated_by'];
}
