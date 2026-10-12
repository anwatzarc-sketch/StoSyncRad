<?php

declare(strict_types=1);

namespace App\Domains\Platform\Seeds;

use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\SequenceResetPolicy;
use App\Domains\Organization\Enums\SequenceScope;
use App\Support\Sentinel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Numbering templates for every document type (Setup Step 10). */
final class DocumentSequencesSeed extends Seed
{
    public function name(): string
    {
        return 'document_sequences';
    }

    public function version(): string
    {
        return '2.0.0';
    }

    public function run(array $options = []): array
    {
        $rows = [];
        foreach (DocumentType::cases() as $type) {
            $rows[] = [
                'id' => (string) Str::uuid7(), 'document_type' => $type->value, 'scope' => SequenceScope::GLOBAL->value, 'scope_id' => Sentinel::UUID,
                'period_key' => '', 'prefix' => $type->defaultPrefix(),
                'pattern' => $type === DocumentType::STOCK_MOVEMENT ? '{PREFIX}-{YYYY}-{SEQ:8}' : '{PREFIX}-{YYYY}-{SEQ:6}',
                'reset_policy' => SequenceResetPolicy::YEARLY->value, 'next_number' => 1,
                'sequence_padding' => $type === DocumentType::STOCK_MOVEMENT ? 8 : 6, 'created_at' => now(), 'updated_at' => now(),
            ];
        }

        return ['inserted' => DB::table('document_sequences')->insertOrIgnore($rows)];
    }
}
