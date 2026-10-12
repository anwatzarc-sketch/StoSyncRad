<?php

declare(strict_types=1);

namespace App\Domains\IdentityAccess\Models;

use App\Domains\IdentityAccess\Enums\ApprovalAction;
use App\Domains\Organization\Enums\DocumentType;
use App\Support\Eloquent\BaseModel;

/**
 * Append-only approval decision on a document (Chapter 13 §13.2).
 *
 * @property DocumentType $document_type
 * @property string $document_id
 * @property ApprovalAction $action
 * @property string $actor_account_id
 * @property string|null $comment
 * @property array<string,mixed>|null $limit_snapshot
 * @property int $tier
 * @property \Carbon\CarbonImmutable $acted_at
 */
final class ApprovalRecord extends BaseModel
{
    protected $table = 'approval_records';

    public $timestamps = false;

    protected $fillable = ['document_type', 'document_id', 'action', 'actor_account_id', 'comment', 'limit_snapshot', 'tier', 'acted_at', 'correlation_id'];

    protected function casts(): array
    {
        return ['document_type' => DocumentType::class, 'action' => ApprovalAction::class, 'limit_snapshot' => 'array', 'tier' => 'integer', 'acted_at' => 'immutable_datetime'];
    }
}
