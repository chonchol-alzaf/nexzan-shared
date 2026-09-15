<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('event_id')->unique();
            $table->string('event_type')->index();
            $table->string('exchange');
            $table->string('routing_key');
            $table->string('queue_name')->index();
            $table->string('producer')->nullable();
            $table->string('aggregate_type')->nullable();
            $table->string('aggregate_id')->nullable()->index();
            $table->unsignedBigInteger('aggregate_version')->nullable();
            $table->json('payload');
            $table->string('status', 20)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('dispatched_at')->nullable()->index();
            $table->timestamp('processing_started_at')->nullable()->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_events');
    }
};
