<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The media library registry — one row per uploaded file, so files can be browsed + reused across resources.
 * The bytes live on the configured uploads disk (Support\Media); this table is the browsable index on top.
 * Auto-loaded by the package (loadMigrationsFrom) — no publish step.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_items')) {
            return;
        }

        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');                                  // original / display filename
            $table->string('path');                                  // stored path on the disk
            $table->string('disk')->default('public');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);          // bytes
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('collection')->default('default')->index(); // folder / group
            $table->string('alt')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_items');
    }
};
