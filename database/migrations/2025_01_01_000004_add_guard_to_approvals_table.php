<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the filing route group's auth guard to approval requests, so each portal's inbox lists (and can
 * decide) only its OWN portal's requests. Upgrade path for installs whose approvals table predates the
 * column (the create migration now includes it for fresh installs). Existing rows keep a NULL guard and
 * surface only on the DEFAULT guard's inbox — never on a portal's.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('approvals') || Schema::hasColumn('approvals', 'guard')) {
            return;
        }

        Schema::table('approvals', function (Blueprint $table) {
            $table->string('guard')->nullable()->index()->after('status');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('approvals') && Schema::hasColumn('approvals', 'guard')) {
            Schema::table('approvals', fn (Blueprint $table) => $table->dropColumn('guard'));
        }
    }
};
