<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Organization\Enums\DocumentType;
use App\Domains\Organization\Enums\SequenceResetPolicy;
use App\Domains\Organization\Enums\SequenceScope;
use App\Support\Eloquent\BaseModel;

/**
 * Document numbering sequence (Chapter 08 §8.13). Allocated under SELECT … FOR UPDATE.
 *
 * @property DocumentType $document_type
 * @property SequenceScope $scope
 * @property string|null $scope_id
 * @property string $period_key
 * @property string $prefix
 * @property string $pattern
 * @property SequenceResetPolicy $reset_policy
 * @property int $next_number
 * @property int $sequence_padding
 */
final class DocumentSequence extends BaseModel
{
    protected $table = 'document_sequences';

    protected $fillable = ['document_type', 'scope', 'scope_id', 'period_key', 'prefix', 'pattern', 'reset_policy', 'next_number', 'sequence_padding'];

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'scope' => SequenceScope::class,
            'reset_policy' => SequenceResetPolicy::class,
            'next_number' => 'integer',
            'sequence_padding' => 'integer',
        ];
    }
}
