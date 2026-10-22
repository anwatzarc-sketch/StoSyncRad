<?php

use App\Domains\Messaging\Enums\OutboxStatus;
use App\Support\Database\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $t) {
            Ddl::uuidPrimary($t);
            $t->string('event_type', 120);
            $t->string('aggregate_type', 80);
            $t->uuid('aggregate_id');
            $t->json('payload');
            $t->uuid('correlation_id');
            Ddl::enumColumn($t, 'status', default: OutboxStatus::PENDING->value);
            $t->integer('attempts')->default(0);
            $t->dateTime('available_at', 6);
            $t->dateTime('processed_at', 6)->nullable();
            $t->text('last_error')->nullable();
            Ddl::timestamps($t);
            $t->index(['status', 'available_at']);
            $t->index(['aggregate_type', 'aggregate_id']);
            $t->index('correlation_id');
        });
        Ddl::enumCheck('outbox_events', 'status', OutboxStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
