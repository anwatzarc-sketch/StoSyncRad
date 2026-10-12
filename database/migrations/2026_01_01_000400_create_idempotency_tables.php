<?php

use App\Support\Database\Pg;
use App\Support\Transactions\IdempotencyScopeType;
use App\Support\Transactions\IdempotencyStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            Pg::enumColumn($t, 'scope_type');
            $t->string('scope_id', 64);
            $t->string('key', 128);
            $t->string('operation', 120);
            $t->string('request_hash', 64);
            Pg::enumColumn($t, 'status', default: IdempotencyStatus::IN_PROGRESS->value);
            $t->unsignedSmallInteger('response_code')->nullable();
            $t->jsonb('response_body')->nullable();
            $t->string('result_document_type', 64)->nullable();
            $t->uuid('result_document_id')->nullable();
            $t->timestampTz('expires_at');
            Pg::timestamps($t);
            $t->unique(['scope_type', 'scope_id', 'key'], 'idempotency_keys_scope_key_uq');
            $t->index('expires_at');
        });
        Pg::enumCheck('idempotency_keys', 'scope_type', IdempotencyScopeType::class);
        Pg::enumCheck('idempotency_keys', 'status', IdempotencyStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
