<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The Notification Platform's owned in-app store. Hybrid identity: a fast bigint `id` for the PK/joins and a public
// `uuid` for the Center handle. One row per recipient (fan-out). Written only by the InApp channel.
//
// WP-N2A created the store; WP-N2B makes this migration the one-time transition FROM Laravel's uuid-PK `notifications`
// table. It is idempotent across three entry states:
//   - table absent                                 → create the hybrid store fresh (new install / test)
//   - Laravel's schema (uuid PK, no `uuid` column) → archive the rows, drop the original, create the hybrid store,
//     copy the rows across (a fresh bigint `id` is generated; uuid, read-state, timestamps, data, type and the
//     notifiable morph are preserved; `guard` defaults to null — Laravel notifications carry no guard). The
//     `notifications_legacy` archive is KEPT for rollback and dropped later at the documented cleanup step.
//   - already hybrid (a `uuid` column is present)  → no-op
//
// Design notes:
//   - Archive-then-recreate (not rename): renaming a table does NOT rename its indexes or (on pgsql) its
//     `<table>_pkey` constraint, so recreating `notifications` afterwards would collide on those names. Dropping the
//     original first frees every canonical name, so the hybrid store is created identically to a fresh install.
//   - Portable only: Schema + Query-builder calls, no driver-specific SQL. The copy binds the JSON `data` string as
//     an untyped parameter (pgsql/mysql json columns accept it), and uses plain `insert` so a malformed row aborts
//     the migration loudly rather than being silently dropped (as MySQL `INSERT IGNORE` would).
//   - Resumable: DDL auto-commits on MySQL, so a run can die between stages. Each stage is safe to retry — the
//     archive is rebuilt from scratch, and the hybrid copy skips rows already present (dedup by `uuid`, without
//     relying on the unique index having been built yet), so a retry recovers every row with no duplicates.
//   - Fail-safe: a `notifications` table that is not Laravel's stock schema — different column set (customization or
//     an unrelated same-named table) OR a non-bigint `notifiable_id` (uuidMorphs) — aborts BEFORE any data is moved.
//     See assertKnownLaravelSchema().
return new class extends Migration
{
    public function up(): void
    {
        $exists = Schema::hasTable('notifications');
        // Laravel's notifications table has a uuid PRIMARY KEY named `id` and no separate `uuid` column; the hybrid
        // store always has one. That column's presence is the unambiguous discriminator between the two schemas.
        $isLegacy = $exists && ! Schema::hasColumn('notifications', 'uuid');

        // Stage 1: move a populated Laravel table aside into the archive, then drop it to free the `notifications`
        // name. An empty Laravel table (e.g. just published by `admin-core:install --access` and superseded here on
        // a fresh install) is simply dropped — nothing to preserve, so no archive is left behind.
        if ($isLegacy) {
            $this->assertKnownLaravelSchema('notifications'); // abort before touching data if the schema is unexpected

            if (DB::table('notifications')->exists()) {
                Schema::dropIfExists('notifications_legacy'); // rebuilt from scratch, so a partial prior run is fine
                $this->createLaravelSchema('notifications_legacy'); // distinct name → own index/PK names, no collision
                $this->copyLaravelRows('notifications', 'notifications_legacy');
            }
            Schema::drop('notifications'); // frees the `notifications` name + all its Laravel index/PK names
            $exists = false;
        }

        // Stage 2: ensure the hybrid store exists (fresh install, or after stage 1).
        if (! $exists) {
            $this->createHybrid(); // canonical schema, identical to a fresh install
        }

        // Stage 3: populate the hybrid store from the archive if one is present (skip-existing → resumable + no
        // duplicates). A completed transition leaves every row present, so this is a no-op on re-run.
        if (Schema::hasTable('notifications_legacy')) {
            $this->copyIntoHybrid('notifications_legacy', 'notifications');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');

        // Restore the pre-transition snapshot from the archive: recreate Laravel's table, copy the archived rows
        // back, then drop the archive. This is a break-glass rollback that returns notifications to their state at
        // cutover. Notifications written to the new store AFTER cutover are not representable in Laravel's uuid-PK,
        // guardless schema and are not carried back — take a database backup before cutover (see the deployment
        // guide). Leaves a clean Laravel table that a re-migration can transition again.
        if (Schema::hasTable('notifications_legacy')) {
            $this->createLaravelSchema('notifications');
            $this->copyLaravelRows('notifications_legacy', 'notifications');
            Schema::drop('notifications_legacy');
        }
    }

    private function createHybrid(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();                                   // internal identity
            $table->uuid('uuid')->unique();                 // public handle (Center API/URL)
            $table->morphs('notifiable');                   // notifiable_type + notifiable_id (bigint), indexed
            $table->string('guard')->nullable()->index();   // recipient guard (portal isolation)
            $table->string('type')->index();                // opaque, product-owned type key
            $table->json('data');                           // immutable presentation payload
            $table->timestamp('read_at')->nullable();       // read-state
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);    // unread count / list
            $table->index(['notifiable_type', 'notifiable_id', 'created_at']); // ordered inbox
        });
    }

    /** Laravel's own `notifications` schema — used for the rollback archive and for restoring on down(). */
    private function createLaravelSchema(string $table): void
    {
        Schema::create($table, function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->longText('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Refuse to transition anything but Laravel's stock notifications schema, so the fixed-shape copy below can
     * never silently mangle a customized or unrelated table. Two checks, both BEFORE the destructive archive+drop:
     *   (a) the column SET must be exactly Laravel's stock columns — catches extra/renamed/missing columns and an
     *       unrelated table that merely shares the name;
     *   (b) `notifiable_id` must hold a numeric (bigint) key — catches `uuidMorphs`, whose column NAMES are
     *       identical but whose uuid-string keys the archive's bigint column would coerce to 0 on a non-strict
     *       engine (a data-level probe, so it needs no per-driver type-name knowledge).
     */
    private function assertKnownLaravelSchema(string $table): void
    {
        $expected = ['created_at', 'data', 'id', 'notifiable_id', 'notifiable_type', 'read_at', 'type', 'updated_at'];
        $actual = Schema::getColumnListing($table);
        sort($actual);

        if ($actual !== $expected) {
            throw new \RuntimeException(sprintf(
                'admin-core notification transition: `%s` does not match the expected Laravel notifications schema '
                . '(columns: %s; found: %s). A customized or unrelated notifications table must be transitioned by '
                . 'hand — see the WP-N2B deployment guide. Aborting before any data is moved.',
                $table,
                implode(', ', $expected),
                implode(', ', $actual) ?: '(none)'
            ));
        }

        $sample = DB::table($table)->value('notifiable_id'); // null on an empty table (nothing to corrupt anyway)
        if ($sample !== null && ! is_numeric($sample)) {
            throw new \RuntimeException(sprintf(
                'admin-core notification transition: `%s.notifiable_id` holds a non-numeric key (e.g. `uuidMorphs`), '
                . 'but the transition targets a bigint morph. This table must be transitioned by hand — see the '
                . 'WP-N2B deployment guide. Aborting before any data is moved.',
                $table
            ));
        }
    }

    /** Copy between two Laravel-schema tables, column-for-column (archive out, restore back). Chunked + portable. */
    private function copyLaravelRows(string $from, string $to): void
    {
        DB::table($from)->orderBy('id')->chunk(500, function (Collection $rows) use ($to) {
            DB::table($to)->insert(
                $rows->map(fn ($row) => [
                    'id' => $row->id,
                    'type' => $row->type,
                    'notifiable_type' => $row->notifiable_type,
                    'notifiable_id' => $row->notifiable_id,
                    'data' => $row->data,
                    'read_at' => $row->read_at,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ])->all()
            );
        });
    }

    /**
     * Copy the archived Laravel rows into the hybrid store. Chunked over the (unchanging) archive; each chunk skips
     * uuids already present so a retry after an interrupted copy resumes without duplicating — dedup is done here,
     * not by the unique index, so it holds even if a prior run died before the index was built. Plain `insert` (not
     * insertOrIgnore) so a malformed row aborts loudly instead of being silently dropped; portable to every engine.
     * (`data` is preserved as content; a real json column — MySQL — may re-serialize the formatting, not the value.)
     */
    private function copyIntoHybrid(string $from, string $to): void
    {
        DB::table($from)->orderBy('id')->chunk(500, function (Collection $rows) use ($to) {
            $already = DB::table($to)->whereIn('uuid', $rows->pluck('id')->all())->pluck('uuid')->flip();

            $new = $rows->reject(fn ($row) => $already->has($row->id))->map(fn ($row) => [
                'uuid' => $row->id,                    // Laravel's uuid PK becomes the public uuid handle
                'notifiable_type' => $row->notifiable_type,
                'notifiable_id' => $row->notifiable_id,
                'guard' => null,                       // Laravel notifications have no guard dimension
                'type' => $row->type,
                'data' => $row->data,                  // presentation payload
                'read_at' => $row->read_at,            // read-state preserved
                'created_at' => $row->created_at,      // timestamps preserved
                'updated_at' => $row->updated_at,
            ])->all();

            if ($new !== []) {
                DB::table($to)->insert($new);
            }
        });
    }
};
