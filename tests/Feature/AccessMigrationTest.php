<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The --access add-avatar migration must be safe to (re)run on a host whose users table already has an
 * `avatar` and/or `uuid` column — a plain add would crash with "duplicate column name".
 */

afterEach(fn () => Schema::dropIfExists('users'));

function avatarMigration(): object
{
    return require dirname(__DIR__, 2) . '/stubs/access/database/migrations/0001_01_01_000013_add_avatar_to_users_table.php.stub';
}

it('adds avatar + uuid on a plain users table', function () {
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('email');
    });

    avatarMigration()->up();

    expect(Schema::hasColumn('users', 'avatar'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'uuid'))->toBeTrue();
});

it('backfills a uuid onto pre-existing rows (a legacy user is not left uuid=NULL → no broken Users list)', function () {
    // HasPublicUuid fills uuid only on `creating`, so rows that existed before this migration would keep
    // uuid=NULL — and since getRouteKeyName() is now 'uuid', route('…edit', null) 500s the whole list.
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('email');
    });
    \Illuminate\Support\Facades\DB::table('users')->insert([
        ['email' => 'legacy1@x.co'],
        ['email' => 'legacy2@x.co'],
    ]);

    avatarMigration()->up();

    $rows = \Illuminate\Support\Facades\DB::table('users')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->whereNull('uuid')->count())->toBe(0)               // every legacy row got a uuid
        ->and($rows->pluck('uuid')->unique()->count())->toBe(2);        // and they're distinct (unique index safe)
});

it('does not crash when the users table already has an avatar column (guarded re-run)', function () {
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('email');
        $t->string('avatar')->nullable(); // a host that already stores an avatar under that name
    });

    avatarMigration()->up(); // must NOT throw "duplicate column name: avatar"

    expect(Schema::hasColumn('users', 'avatar'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'uuid'))->toBeTrue(); // uuid still added

    // Idempotent: a second run is a clean no-op too.
    avatarMigration()->up();
    expect(Schema::hasColumn('users', 'uuid'))->toBeTrue();
});

it('down() never drops a PRE-EXISTING avatar/uuid column or its data (no rollback data loss)', function () {
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('email');
        $t->string('avatar')->nullable(); // a host that already stores an avatar path under that name
    });
    \Illuminate\Support\Facades\DB::table('users')->insert(['email' => 'a@b.c', 'avatar' => 'avatars/real-photo.png']);

    $m = avatarMigration();
    $m->up();   // guarded: does NOT touch the pre-existing avatar, adds uuid
    $m->down(); // must NOT drop the host's avatar column (up() never created it) — the crash the old code caused

    expect(Schema::hasColumn('users', 'avatar'))->toBeTrue()                                          // column survives
        ->and(\Illuminate\Support\Facades\DB::table('users')->value('avatar'))->toBe('avatars/real-photo.png'); // data survives
});
