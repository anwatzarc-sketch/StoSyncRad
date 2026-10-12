<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Support\Eloquent\BaseModel;

/**
 * Client-level override of one translation string (Chapter 03 §3.7).
 *
 * @property string $locale
 * @property string $key
 * @property string $value
 */
final class TranslationOverride extends BaseModel
{
    protected $table = 'translation_overrides';

    protected $fillable = ['locale', 'key', 'value', 'updated_by'];
}
