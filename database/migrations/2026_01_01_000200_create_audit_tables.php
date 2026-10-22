<?php

use App\Domains\Audit\Enums\AuditActorType;
use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            Ddl::enumColumn($t, 'actor_type', default: AuditActorType::SYSTEM->value);
            $t->uuid('actor_account_id')->nullable();
            $t->string('event_type', 120);
            $t->string('subject_type', 80)->nullable();
            $t->uuid('subject_id')->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->json('context')->nullable();
            $t->text('reason')->nullable();
            $t->uuid('correlation_id');
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->boolean('is_failed_attempt')->default(false);
            $t->dateTime('occurred_at', 6);

            $t->index(['subject_type', 'subject_id', 'occurred_at']);
            $t->index(['actor_account_id', 'occurred_at']);
            $t->index(['event_type', 'occurred_at']);
            $t->index('correlation_id');
            $t->index('occurred_at');
        });
        Ddl::enumCheck('audit_events', 'actor_type', AuditActorType::class);
        Ddl::appendOnly('audit_events');

        Schema::create('security_events', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('event_type', 80);
            $t->uuid('account_id')->nullable();
            $t->string('username_attempted', 254)->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->json('context')->nullable();
            $t->uuid('correlation_id');
            $t->dateTime('occurred_at', 6);
            $t->index(['account_id', 'occurred_at']);
            $t->index(['event_type', 'occurred_at']);
            $t->index(['ip_address', 'occurred_at']);
        });
        Ddl::appendOnly('security_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('audit_events');
    }
};
