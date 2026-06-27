<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polymorphic media attachments: links a media_items row to any model record under a named collection (with an
 * order), so a model using HasMedia can own multiple library files per collection — and one library file can be
 * reused across many records. Auto-loaded by the package (loadMigrationsFrom).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mediables')) {
            return;
        }

        Schema::create('mediables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained('media_items')->cascadeOnDelete();
            $table->morphs('mediable'); // mediable_type + mediable_id (indexed)
            $table->string('collection')->default('default');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mediables');
    }
};
