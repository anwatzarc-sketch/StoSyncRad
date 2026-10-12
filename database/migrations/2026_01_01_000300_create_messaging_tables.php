<?php

use App\Domains\Messaging\Enums\OutboxStatus;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $t) {
            Pg::uuidPrimary($t);
            $t->string('event_type', 120);
            $t->string('aggregate_type', 80);
            $t->uuid('aggregate_id');
            $t->jsonb('payload');
            $t->uuid('correlation_id');
            Pg::enumColumn($t, 'status', default: OutboxStatus::PENDING->value);
            $t->unsignedInteger('attempts')->default(0);
            $t->timestampTz('available_at');
            $t->timestampTz('processed_at')->nullable();
            $t->text('last_error')->nullable();
            Pg::timestamps($t);
            $t->index(['status', 'available_at']);
            $t->index(['aggregate_type', 'aggregate_id']);
            $t->index('correlation_id');
        });
        Pg::enumCheck('outbox_events', 'status', OutboxStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
