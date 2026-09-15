<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->uuid('publish_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', fn (Blueprint $table) => $table->dropColumn('publish_token'));
    }
};
