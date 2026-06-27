<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user dashboard layout for the widget framework: the order the user arranged their widgets in and the
 * ones they hid. user_id is a plain indexed column (not an FK) so it works across any auth guard / user model.
 * Auto-loaded by the package (loadMigrationsFrom) — no publish step.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dashboard_layouts')) {
            return;
        }

        Schema::create('dashboard_layouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->json('layout'); // { "order": ["key", ...], "hidden": ["key", ...] }
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_layouts');
    }
};
