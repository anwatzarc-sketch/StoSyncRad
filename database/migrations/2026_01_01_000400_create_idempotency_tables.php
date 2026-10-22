<?php

use App\Support\Database\Ddl;
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
            Ddl::uuidPrimary($t);
            Ddl::enumColumn($t, 'scope_type');
            $t->string('scope_id', 64);
            $t->string('key', 128);
            $t->string('operation', 120);
            $t->string('request_hash', 64);
            Ddl::enumColumn($t, 'status', default: IdempotencyStatus::IN_PROGRESS->value);
            $t->smallInteger('response_code')->nullable();
            $t->json('response_body')->nullable();
            $t->string('result_document_type', 64)->nullable();
            $t->uuid('result_document_id')->nullable();
            $t->dateTime('expires_at', 6);
            Ddl::timestamps($t);
            $t->unique(['scope_type', 'scope_id', 'key'], 'idempotency_keys_scope_key_uq');
            $t->index('expires_at');
        });
        Ddl::enumCheck('idempotency_keys', 'scope_type', IdempotencyScopeType::class);
        Ddl::enumCheck('idempotency_keys', 'status', IdempotencyStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
