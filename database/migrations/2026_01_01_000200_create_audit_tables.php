<?php

use App\Domains\Audit\Enums\AuditActorType;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            Pg::enumColumn($t, 'actor_type', default: AuditActorType::SYSTEM->value);
            $t->uuid('actor_account_id')->nullable();
            $t->string('event_type', 120);
            $t->string('subject_type', 80)->nullable();
            $t->uuid('subject_id')->nullable();
            $t->jsonb('before')->nullable();
            $t->jsonb('after')->nullable();
            $t->jsonb('context')->nullable();
            $t->text('reason')->nullable();
            $t->uuid('correlation_id');
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->boolean('is_failed_attempt')->default(false);
            $t->timestampTz('occurred_at');

            $t->index(['subject_type', 'subject_id', 'occurred_at']);
            $t->index(['actor_account_id', 'occurred_at']);
            $t->index(['event_type', 'occurred_at']);
            $t->index('correlation_id');
            $t->index('occurred_at');
        });
        Pg::enumCheck('audit_events', 'actor_type', AuditActorType::class);
        Pg::appendOnly('audit_events');

        Schema::create('security_events', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('event_type', 80);
            $t->uuid('account_id')->nullable();
            $t->string('username_attempted', 254)->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->jsonb('context')->nullable();
            $t->uuid('correlation_id');
            $t->timestampTz('occurred_at');
            $t->index(['account_id', 'occurred_at']);
            $t->index(['event_type', 'occurred_at']);
            $t->index(['ip_address', 'occurred_at']);
        });
        Pg::appendOnly('security_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('audit_events');
    }
};
