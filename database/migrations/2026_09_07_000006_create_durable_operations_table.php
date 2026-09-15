<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('durable_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('deduplication_key', 64)->unique();
            $table->ulid('event_id')->index();
            $table->string('operation_key');
            $table->longText('job_payload');
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('durable_operations');
    }
};
