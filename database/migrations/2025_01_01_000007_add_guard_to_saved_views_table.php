<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade an existing saved_views table (created before the per-guard fix) to be guard-aware: add the `guard`
 * column and move the index/unique from (user_id, …) to (user_id, guard, …), so a portal user (a non-default
 * guard) gets their own views instead of colliding with the web user of the same id — a cross-portal leak /
 * overwrite / delete. A fresh install already creates the column; this no-ops there. Auto-loaded — no publish.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('saved_views') || Schema::hasColumn('saved_views', 'guard')) {
            return; // fresh install (already has it) or the table doesn't exist yet
        }

        Schema::table('saved_views', function (Blueprint $table) {
            $table->string('guard')->nullable()->after('user_id');
        });

        // Backfill existing rows to the DEFAULT guard — they were all saved on it before the split. Without
        // this they keep guard=NULL and the controller's `where('guard', 'web')` can never match them again,
        // silently orphaning every pre-existing user's saved views.
        \Illuminate\Support\Facades\DB::table('saved_views')
            ->whereNull('guard')
            ->update(['guard' => (string) config('auth.defaults.guard', 'web')]);

        // Swap the (user_id, resource, name) unique for the guard-aware one, and widen the index. dropUnique
        // before adding (SQLite ordering).
        Schema::table('saved_views', function (Blueprint $table) {
            try {
                $table->dropUnique('saved_views_user_id_resource_name_unique');
            } catch (\Throwable) {
                // some drivers/older installs may not have the named index — ignore
            }
            try {
                $table->dropIndex('saved_views_user_id_resource_index');
            } catch (\Throwable) {
            }
            $table->index(['user_id', 'guard', 'resource']);
            $table->unique(['user_id', 'guard', 'resource', 'name']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('saved_views') || ! Schema::hasColumn('saved_views', 'guard')) {
            return;
        }

        Schema::table('saved_views', function (Blueprint $table) {
            try {
                $table->dropUnique(['user_id', 'guard', 'resource', 'name']);
                $table->dropIndex(['user_id', 'guard', 'resource']);
            } catch (\Throwable) {
            }
            $table->dropColumn('guard');
        });
    }
};
