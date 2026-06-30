<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The counters behind `sequence` fields — one row per (key, period). Auto-loaded by the package
 * (loadMigrationsFrom); run `php artisan migrate` after upgrading. unique(key, period) makes the
 * atomic increment safe (and is what the first-create race recovers against via firstOrCreate).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('number_sequences')) {
            return;
        }

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key');                          // e.g. "invoices.invoice_no"
            $table->string('period')->default('');          // '' | '2026' | '202601' (reset bucket)
            $table->unsignedBigInteger('value')->default(0);
            $table->unique(['key', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
