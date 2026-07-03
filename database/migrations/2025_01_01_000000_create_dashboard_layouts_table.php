<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user-per-guard dashboard layout for the widget framework: the order the user arranged their widgets in
 * and the ones they hid. user_id is a plain column (not an FK) so it works across any auth guard / user model;
 * `guard` namespaces it so id 5 on a portal guard never collides with id 5 on the web guard. Auto-loaded by the
 * package (loadMigrationsFrom) — no publish step. (An older install is upgraded by the sibling add_guard migration.)
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
            $table->unsignedBigInteger('user_id');
            $table->string('guard')->nullable();
            $table->json('layout'); // { "order": ["key", ...], "hidden": ["key", ...] }
            $table->timestamps();
            $table->unique(['user_id', 'guard']); // one row per user per guard
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_layouts');
    }
};
