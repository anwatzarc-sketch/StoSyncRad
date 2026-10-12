<?php

declare(strict_types=1);

namespace App\Support\Transactions;

use App\Support\Eloquent\BaseModel;

/**
 * Idempotency record (Chapter 09 §9.5).
 *
 * @property string $id
 * @property IdempotencyScopeType $scope_type
 * @property string $scope_id
 * @property string $key
 * @property string $operation
 * @property string $request_hash
 * @property IdempotencyStatus $status
 * @property int|null $response_code
 * @property array<string,mixed>|null $response_body
 * @property string|null $result_document_type
 * @property string|null $result_document_id
 * @property \Carbon\CarbonImmutable $expires_at
 */
final class IdempotencyKey extends BaseModel
{
    protected $table = 'idempotency_keys';

    protected $fillable = [
        'scope_type', 'scope_id', 'key', 'operation', 'request_hash', 'status', 'response_code', 'response_body',
        'result_document_type', 'result_document_id', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => IdempotencyScopeType::class,
            'status' => IdempotencyStatus::class,
            'response_code' => 'integer',
            'response_body' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
