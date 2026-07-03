<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade an existing dashboard_layouts table (created before the per-guard fix) to be guard-aware: add the
 * `guard` column and move the unique key from (user_id) to (user_id, guard), so a portal user (a non-default
 * guard) gets their own row instead of colliding with the web user of the same id. A fresh install already
 * creates the column in the create migration; this no-ops there. Auto-loaded — no publish step.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dashboard_layouts') || Schema::hasColumn('dashboard_layouts', 'guard')) {
            return; // fresh install (already has it) or the table doesn't exist yet
        }

        Schema::table('dashboard_layouts', function (Blueprint $table) {
            $table->string('guard')->nullable()->after('user_id');
        });

        // Swap the single-column unique for the composite one. dropUnique before adding (SQLite order).
        Schema::table('dashboard_layouts', function (Blueprint $table) {
            try {
                $table->dropUnique('dashboard_layouts_user_id_unique');
            } catch (\Throwable) {
                // some drivers/older installs may not have the named index — ignore
            }
            $table->unique(['user_id', 'guard']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dashboard_layouts') || ! Schema::hasColumn('dashboard_layouts', 'guard')) {
            return;
        }

        Schema::table('dashboard_layouts', function (Blueprint $table) {
            try {
                $table->dropUnique(['user_id', 'guard']);
            } catch (\Throwable) {
            }
            $table->dropColumn('guard');
        });
    }
};
