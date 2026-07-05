<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user saved list views for the advanced-filter bar: a named set of filter values for one resource.
 * user_id is a plain indexed column (not an FK) so it works across any auth guard / user model. Auto-loaded
 * by the package (loadMigrationsFrom) — no publish step; run `php artisan migrate` after upgrading.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('saved_views')) {
            return;
        }

        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('guard')->nullable();  // the auth guard the user_id belongs to (multi-portal): so
            $table->string('resource');           // id 5 on 'web' never collides with id 5 on 'merchant'
            $table->string('name');
            $table->json('filters');              // { "col": "value", "dateCol": { "from": …, "to": … } }
            $table->timestamps();
            $table->index(['user_id', 'guard', 'resource']);
            $table->unique(['user_id', 'guard', 'resource', 'name']); // saving an existing name overwrites
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
