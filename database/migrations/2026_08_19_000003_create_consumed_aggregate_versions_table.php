<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumed_aggregate_versions', function (Blueprint $table): void {
            $table->char('stream_key', 64)->primary();
            $table->string('producer');
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->unsignedBigInteger('last_version');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumed_aggregate_versions');
    }
};
