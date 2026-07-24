<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Models\Notification;

/*
 * WP-N2B — the one-time transition FROM Laravel's uuid-PK `notifications` table INTO the platform's hybrid store.
 * The transition is the notifications migration itself (idempotent: create-fresh / transform-and-copy / no-op).
 * These tests exercise the transform + copy + rollback against a real Laravel-schema source table.
 */

$migration = fn () => require dirname(__DIR__, 2) . '/database/migrations/2025_01_01_000008_create_notifications_table.php';

/** Recreate Laravel's exact `notifications` schema (uuid PRIMARY KEY named `id`, no `uuid` column, no `guard`). */
$seedLaravelTable = function (string $name = 'notifications') use (&$seedLaravelTable): void {
    Schema::dropIfExists($name);
    Schema::create($name, function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->morphs('notifiable');
        $table->longText('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });

    DB::table($name)->insert([
        [
            'id' => '11111111-1111-7111-8111-111111111111',
            'type' => 'App\\Notifications\\OrderShipped',
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id' => 1,
            'data' => json_encode(['title' => 'Shipped', 'order' => 42]),
            'read_at' => null,                       // unread
            'created_at' => '2025-01-01 10:00:00',
            'updated_at' => '2025-01-01 10:00:00',
        ],
        [
            'id' => '22222222-2222-7222-8222-222222222222',
            'type' => 'App\\Notifications\\OrderShipped',
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id' => 2,
            'data' => json_encode(['title' => 'Delivered']),
            'read_at' => '2025-02-02 09:30:00',      // read
            'created_at' => '2025-01-02 08:00:00',
            'updated_at' => '2025-02-02 09:30:00',
        ],
    ]);
};

beforeEach(function () {
    Schema::dropIfExists('notifications');
    Schema::dropIfExists('notifications_legacy');
});

afterEach(function () {
    Schema::dropIfExists('notifications');
    Schema::dropIfExists('notifications_legacy');
});

// ---- copy migration: Laravel notifications -> admin-core hybrid store -------------------------------------------

it('transforms Laravel notifications into the hybrid store and copies every row', function () use ($migration, $seedLaravelTable) {
    $seedLaravelTable();
    expect(Schema::hasColumn('notifications', 'uuid'))->toBeFalse(); // starts as Laravel's schema

    $migration()->up();

    // schema is now the hybrid store
    expect(Schema::hasTable('notifications'))->toBeTrue();
    foreach (['id', 'uuid', 'notifiable_type', 'notifiable_id', 'guard', 'type', 'data', 'read_at', 'created_at', 'updated_at'] as $col) {
        expect(Schema::hasColumn('notifications', $col))->toBeTrue("missing column {$col}");
    }
    // every row copied; the source is preserved (kept for rollback / cleanup)
    expect(Notification::count())->toBe(2);
    expect(Schema::hasTable('notifications_legacy'))->toBeTrue();
});

it('generates a fresh bigint id for each migrated row (id conversion)', function () use ($migration, $seedLaravelTable) {
    $seedLaravelTable();

    $migration()->up();

    $rows = Notification::orderBy('id')->get();
    expect($rows[0]->id)->toBeInt()->toBeGreaterThan(0)
        ->and($rows[1]->id)->toBeInt()
        ->and($rows[0]->id)->not->toBe($rows[1]->id); // distinct auto-increment identities
});

it('preserves the uuid: Laravel\'s uuid PK becomes the public uuid handle', function () use ($migration, $seedLaravelTable) {
    $seedLaravelTable();

    $migration()->up();

    expect(Notification::where('uuid', '11111111-1111-7111-8111-111111111111')->exists())->toBeTrue()
        ->and(Notification::where('uuid', '22222222-2222-7222-8222-222222222222')->exists())->toBeTrue();
    // and the uuid is the public route key, never the bigint id
    expect((new Notification)->getRouteKeyName())->toBe('uuid');
});

it('preserves read-state (read_at) across the transition', function () use ($migration, $seedLaravelTable) {
    $seedLaravelTable();

    $migration()->up();

    $unread = Notification::where('uuid', '11111111-1111-7111-8111-111111111111')->firstOrFail();
    $read = Notification::where('uuid', '22222222-2222-7222-8222-222222222222')->firstOrFail();
    expect($unread->read_at)->toBeNull()
        ->and($read->read_at)->not->toBeNull()
        ->and($read->read_at->format('Y-m-d H:i:s'))->toBe('2025-02-02 09:30:00');
});

it('preserves timestamps, the notifiable morph, the type and the data payload', function () use ($migration, $seedLaravelTable) {
    $seedLaravelTable();

    $migration()->up();

    $n = Notification::where('uuid', '11111111-1111-7111-8111-111111111111')->firstOrFail();
    expect($n->created_at->format('Y-m-d H:i:s'))->toBe('2025-01-01 10:00:00')
        ->and($n->updated_at->format('Y-m-d H:i:s'))->toBe('2025-01-01 10:00:00')
        ->and($n->notifiable_type)->toBe('App\\Models\\User')
        ->and((int) $n->notifiable_id)->toBe(1)
        ->and($n->type)->toBe('App\\Notifications\\OrderShipped')
        ->and($n->data['title'])->toBe('Shipped')  // json content preserved (key-order-insensitive: MySQL's json
        ->and($n->data['order'])->toBe(42)         // type re-serializes formatting, so assert content, not layout)
        ->and($n->guard)->toBeNull();              // Laravel notifications carry no guard
});

// ---- rollback ---------------------------------------------------------------------------------------------------

it('rolls back to the pre-transition snapshot: down() restores Laravel\'s table and rows intact', function () use ($migration, $seedLaravelTable) {
    $seedLaravelTable();
    $migration()->up();

    $migration()->down();

    // Laravel's schema is back (uuid PK named `id`, no `uuid`/`guard` columns), the archive is gone, both rows intact
    expect(Schema::hasTable('notifications'))->toBeTrue()
        ->and(Schema::hasColumn('notifications', 'uuid'))->toBeFalse()
        ->and(Schema::hasColumn('notifications', 'guard'))->toBeFalse()
        ->and(Schema::hasTable('notifications_legacy'))->toBeFalse(); // moved back, not left behind
    expect(DB::table('notifications')->count())->toBe(2);

    // the read, later row survived the round trip with its data / read-state / timestamps
    $restored = DB::table('notifications')->where('id', '22222222-2222-7222-8222-222222222222')->first();
    expect($restored)->not->toBeNull()
        ->and($restored->type)->toBe('App\\Notifications\\OrderShipped')
        ->and($restored->read_at)->not->toBeNull()
        ->and((string) $restored->created_at)->toContain('2025-01-02')  // driver-robust: avoid exact format compare
        ->and(json_decode($restored->data, true)['title'])->toBe('Delivered');
});

it('leaves a clean state after rollback that a re-migration can transition again (no name collisions)', function () use ($migration, $seedLaravelTable) {
    $seedLaravelTable();
    $migration()->up();
    $migration()->down();   // back to Laravel

    $migration()->up();     // transition again — must not collide on any leftover index / PK name

    expect(Schema::hasColumn('notifications', 'uuid'))->toBeTrue()
        ->and(Notification::count())->toBe(2);
});

it('copies a large table across chunk boundaries without loss or duplication (chunked bulk copy)', function () use ($migration) {
    Schema::dropIfExists('notifications');
    Schema::create('notifications', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->morphs('notifiable');
        $table->longText('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
    $rows = [];
    for ($i = 1; $i <= 600; $i++) { // > the 500 chunk size → the copy spans two chunks
        $rows[] = [
            'id' => sprintf('00000000-0000-7000-8000-%012d', $i),
            'type' => 'T', 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => $i,
            'data' => json_encode(['n' => $i]), 'read_at' => null,
            'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00',
        ];
    }
    DB::table('notifications')->insert($rows);

    $migration()->up();

    expect(Notification::count())->toBe(600)                              // every chunk copied
        ->and(Notification::distinct()->count('uuid'))->toBe(600);        // no loss / no duplication at the boundary
});

it('aborts loudly and moves no data when the notifications table is not the stock Laravel schema (fail-safe)', function () use ($migration) {
    Schema::dropIfExists('notifications');
    Schema::create('notifications', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->morphs('notifiable');
        $table->longText('data');
        $table->timestamp('read_at')->nullable();
        $table->string('channel'); // a host customization the transition must NOT silently drop
        $table->timestamps();
    });
    DB::table('notifications')->insert([[
        'id' => '33333333-3333-7333-8333-333333333333', 'type' => 'T',
        'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => 1, 'data' => '{}',
        'read_at' => null, 'channel' => 'mail', 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00',
    ]]);

    expect(fn () => $migration()->up())->toThrow(RuntimeException::class);

    // nothing was archived, dropped or replaced — the original (custom) table is untouched
    expect(Schema::hasColumn('notifications', 'channel'))->toBeTrue()
        ->and(Schema::hasColumn('notifications', 'uuid'))->toBeFalse()
        ->and(Schema::hasTable('notifications_legacy'))->toBeFalse()
        ->and(DB::table('notifications')->count())->toBe(1);
});

it('aborts (moves no data) on a uuidMorphs table — same column names, uuid-string notifiable_id', function () use ($migration) {
    Schema::dropIfExists('notifications');
    Schema::create('notifications', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->string('notifiable_type');
        $table->string('notifiable_id'); // uuidMorphs: identical column NAME, but a uuid-string key (not bigint)
        $table->longText('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
    DB::table('notifications')->insert([[
        'id' => '44444444-4444-7444-8444-444444444444', 'type' => 'T',
        'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => '99999999-9999-7999-8999-999999999999',
        'data' => '{}', 'read_at' => null, 'created_at' => '2025-01-01 00:00:00', 'updated_at' => '2025-01-01 00:00:00',
    ]]);

    expect(fn () => $migration()->up())->toThrow(RuntimeException::class);

    // the column names pass the set check, but the non-numeric key aborts before archiving → source intact
    expect(Schema::hasColumn('notifications', 'uuid'))->toBeFalse()
        ->and(Schema::hasTable('notifications_legacy'))->toBeFalse()
        ->and(DB::table('notifications')->count())->toBe(1);
});

it('down() on a fresh install simply drops the store (no archive to restore, no error)', function () use ($migration) {
    $migration()->up(); // fresh → hybrid store, no archive
    expect(Schema::hasTable('notifications_legacy'))->toBeFalse();

    $migration()->down();

    expect(Schema::hasTable('notifications'))->toBeFalse()
        ->and(Schema::hasTable('notifications_legacy'))->toBeFalse();
});

// ---- retry safety (MySQL auto-commits DDL — a run can be interrupted between stages) ----------------------------

it('resumes a transition interrupted after the drop: archive present, hybrid store empty', function () use ($migration, $seedLaravelTable) {
    // Reconstruct the exact mid-transition state (as stage 1 leaves it): the rows are in the `notifications_legacy`
    // archive and the original `notifications` was dropped, but the hybrid store + copy had not run yet.
    $seedLaravelTable('notifications_legacy');
    expect(Schema::hasTable('notifications'))->toBeFalse();

    $migration()->up(); // no `notifications` table → creates the hybrid store, then copies from the archive

    expect(Schema::hasColumn('notifications', 'uuid'))->toBeTrue()
        ->and(Notification::count())->toBe(2)                          // the archived rows were recovered
        ->and(Notification::where('uuid', '11111111-1111-7111-8111-111111111111')->exists())->toBeTrue();
});

it('resumes a partial copy without duplicating rows (skips uuids already present)', function () use ($migration, $seedLaravelTable) {
    $seedLaravelTable();
    $migration()->up();                       // full transition → hybrid has 2 rows, archive retained
    Notification::query()->orderBy('id')->limit(1)->delete(); // simulate a copy that only got 1 of 2 rows in
    expect(Notification::count())->toBe(1);

    $migration()->up();                       // re-run: hybrid.count(1) < archive.count(2) → resume the copy

    expect(Notification::count())->toBe(2)                            // the missing row was filled in
        ->and(Notification::pluck('uuid')->unique()->count())->toBe(2); // and nothing was duplicated
});

// ---- no regression: fresh install + idempotency -----------------------------------------------------------------

it('creates the hybrid store fresh when no notifications table exists (new install path)', function () use ($migration) {
    expect(Schema::hasTable('notifications'))->toBeFalse();

    $migration()->up();

    expect(Schema::hasTable('notifications'))->toBeTrue()
        ->and(Schema::hasColumn('notifications', 'uuid'))->toBeTrue()
        ->and(Schema::hasColumn('notifications', 'guard'))->toBeTrue()
        ->and(Notification::count())->toBe(0)                        // nothing to copy on a fresh install
        ->and(Schema::hasTable('notifications_legacy'))->toBeFalse(); // no legacy table was produced
});

it('replaces an EMPTY Laravel table with the hybrid store and leaves no archive (fresh-install path)', function () use ($migration, $seedLaravelTable) {
    $seedLaravelTable();
    DB::table('notifications')->delete(); // the install stub published the table, but it holds no rows yet

    $migration()->up();

    expect(Schema::hasColumn('notifications', 'uuid'))->toBeTrue()   // now the hybrid store
        ->and(Notification::count())->toBe(0)
        ->and(Schema::hasTable('notifications_legacy'))->toBeFalse(); // no empty archive left behind
});

it('is idempotent: running up() again on the hybrid store is a no-op (no error, no data loss)', function () use ($migration, $seedLaravelTable) {
    $seedLaravelTable();
    $migration()->up();
    expect(Notification::count())->toBe(2);

    $migration()->up(); // second run — the store is already hybrid

    expect(Notification::count())->toBe(2); // unchanged, not re-copied or dropped
});
