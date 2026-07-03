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

it('rolls back cleanly (drops uuid unique before the column) on SQLite', function () {
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('email');
    });
    $m = avatarMigration();
    $m->up();

    $m->down(); // the crash path — a plain dropColumn on the indexed uuid errors on SQLite

    expect(Schema::hasColumn('users', 'avatar'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'uuid'))->toBeFalse();
});
