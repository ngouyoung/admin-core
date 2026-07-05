<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade an existing media_items table to be guard-aware: add the `guard` column so the opt-in 'own' media
 * scope walls a portal user off by (user_id, guard) — not user_id alone, where id 5 on 'web' and id 5 on
 * 'merchant' would collide. A fresh install already creates the column (this no-ops there). Auto-loaded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_items') || Schema::hasColumn('media_items', 'guard')) {
            return; // fresh install (already has it) or the table doesn't exist yet
        }

        Schema::table('media_items', function (Blueprint $table) {
            $table->string('guard')->nullable()->after('user_id');
        });

        // Backfill existing uploads to the DEFAULT guard — every upload predating portal media was on it. Without
        // this they keep guard=NULL and a `where('guard', 'web')` under the 'own' scope could never match them,
        // hiding a default-guard user's own pre-existing library.
        DB::table('media_items')
            ->whereNull('guard')
            ->whereNotNull('user_id')
            ->update(['guard' => (string) config('auth.defaults.guard', 'web')]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('media_items') || ! Schema::hasColumn('media_items', 'guard')) {
            return;
        }

        Schema::table('media_items', function (Blueprint $table) {
            $table->dropColumn('guard');
        });
    }
};
