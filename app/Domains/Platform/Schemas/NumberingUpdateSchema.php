<?php

declare(strict_types=1);

namespace App\Domains\Platform\Schemas;

use App\Support\Schema\Property;
use App\Support\Schema\TableSchema;

/** Document numbering pattern. */
final class NumberingUpdateSchema extends TableSchema
{
    public static function table(): string
    {
        return 'document_sequences';
    }

    protected static function define(): array
    {
        return [
            'prefix' => Property::string(12)->nullable()->pattern('/^[A-Z0-9_-]{0,12}$/')->virtual()->label('Prefix'),
            'pattern' => Property::string(120)->required()->pattern('/\{SEQ(:\d+)?\}/')->virtual()->label('Pattern', 'Tokens: {PREFIX} {WH} {BR} {YYYY} {YY} {MM} {SEQ}'),
            'sequence_padding' => Property::integer()->nullable()->min(1)->max(12)->virtual()->label('Digits'),
        ];
    }
}
