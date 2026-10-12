<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Platform\Enums\TemplateEngine;
use App\Support\Eloquent\BaseModel;

/**
 * Document / email / label template (Chapter 03 §3.6).
 *
 * @property string $code
 * @property string $name
 * @property TemplateEngine $engine
 * @property string $body
 * @property array<string,mixed>|null $variables_schema
 * @property int $version
 * @property bool $is_system_default
 * @property bool $is_active
 */
final class Template extends BaseModel
{
    protected $table = 'templates';

    protected $fillable = ['code', 'name', 'engine', 'body', 'variables_schema', 'version', 'is_system_default', 'is_active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'engine' => TemplateEngine::class,
            'variables_schema' => 'array',
            'version' => 'integer',
            'is_system_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
