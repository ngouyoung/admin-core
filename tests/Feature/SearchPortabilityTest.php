<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Support\Search;
use Ngos\AdminCore\Tests\Fixtures\Widget;

/**
 * A base query builder whose grammar is the requested driver's — so we can assert the SQL Search GENERATES for
 * every driver without a live server (toSql() compiles, never connects). grammarFor() reads the builder's
 * grammar, so this exercises the exact per-driver rendering the fix relies on.
 */
function acSearchBuilder(string $driver): Builder
{
    $conn = DB::connection();
    $grammar = match ($driver) {
        'pgsql' => new PostgresGrammar($conn),
        'mysql' => new MySqlGrammar($conn),
        default => new SQLiteGrammar($conn),
    };

    return (new Builder($conn, $grammar))->from('widgets');
}

// ───────────────────────── COMPILE tests (no server) — identifier quoting / JSON / escape ─────────────────

it('whereLike: never a backslash ESCAPE, always the "!" sentinel + LOWER() on every driver', function (string $driver) {
    $b = acSearchBuilder($driver);
    Search::whereLike($b, 'name', 'foo');
    $sql = strtolower($b->toSql());

    expect($sql)->toContain("escape '!'")   // portable sentinel — the fix for the MySQL/MariaDB 1064
        ->and($sql)->toContain('lower(')     // case-insensitive on both sides
        ->and($sql)->toContain('like')
        ->and($sql)->not->toContain("escape '\\'"); // the old, non-portable clause is gone
})->with(['sqlite', 'mysql', 'pgsql']);

it('whereLike: identifier is quoted by the GRAMMAR, never a hardcoded MySQL backtick on non-MySQL', function () {
    $pg = acSearchBuilder('pgsql');
    Search::whereLike($pg, 'name', 'foo');
    expect($pg->toSql())->toContain('"name"')->not->toContain('`');

    $sqlite = acSearchBuilder('sqlite');
    Search::whereLike($sqlite, 'name', 'foo');
    expect($sqlite->toSql())->toContain('"name"')->not->toContain('`');

    $mysql = acSearchBuilder('mysql');
    Search::whereLike($mysql, 'name', 'foo');
    expect($mysql->toSql())->toContain('`name`'); // MySQL's own quote is correct on MySQL
});

it('whereJsonLike: renders the driver-native JSON accessor, never json_extract() on PostgreSQL', function () {
    $pg = acSearchBuilder('pgsql');
    Search::whereJsonLike($pg, 'meta', 'en', 'foo');
    $pgSql = strtolower($pg->toSql());
    expect($pgSql)->toContain('->>')          // Postgres text extraction
        ->and($pgSql)->not->toContain('json_extract')
        ->and($pgSql)->not->toContain('`')
        ->and($pgSql)->toContain("escape '!'");

    $mysql = acSearchBuilder('mysql');
    Search::whereJsonLike($mysql, 'meta', 'en', 'foo');
    expect(strtolower($mysql->toSql()))->toContain('json_extract')->toContain('`meta`');

    $sqlite = acSearchBuilder('sqlite');
    Search::whereJsonLike($sqlite, 'meta', 'en', 'foo');
    expect(strtolower($sqlite->toSql()))->toContain('json_extract');
});

// ───────────────────────── likePattern sentinel (regression of the literal `_`/`%` fix) ───────────────────

it('likePattern escapes wildcards and the sentinel itself with "!"', function () {
    expect(Search::likePattern('a_b'))->toBe('%a!_b%')      // underscore made literal
        ->and(Search::likePattern('50%'))->toBe('%50!%%')   // percent made literal
        ->and(Search::likePattern('a!b'))->toBe('%a!!b%')   // a literal sentinel is escaped
        ->and(Search::likePattern('plain'))->toBe('%plain%');
});

// ───────────────────────── EXECUTION tests on the CI driver (SQLite) — behaviour is preserved ─────────────

describe('execution on the live connection', function () {
    beforeEach(function () {
        Schema::dropIfExists('widgets');
        Schema::create('widgets', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
    });

    it('whereLike matches case-insensitively', function () {
        Widget::create(['name' => 'Alpha']);

        $q = Widget::query();
        Search::whereLike($q, 'name', 'ALPHA');
        expect($q->count())->toBe(1);
    });

    it('whereLike treats an underscore / percent in the term as LITERAL', function () {
        Widget::create(['name' => 'a_c literal']);
        Widget::create(['name' => 'aXc wildcard']);

        $q = Widget::query();
        Search::whereLike($q, 'name', 'a_c');
        expect($q->pluck('name')->all())->toBe(['a_c literal']); // NOT the aXc collision
    });

    it('whereLike with boolean "or" builds a union across columns/terms', function () {
        Widget::create(['name' => 'Alpha']);
        Widget::create(['name' => 'Beta']);
        Widget::create(['name' => 'Gamma']);

        $q = Widget::query();
        Search::whereLike($q, 'name', 'alpha', 'or');
        Search::whereLike($q, 'name', 'beta', 'or');
        expect($q->count())->toBe(2);
    });

    it('whereJsonLike matches one locale value case-insensitively and ignores other locales', function () {
        Schema::create('translatables', function (Blueprint $t) {
            $t->id();
            $t->json('title');
        });
        DB::table('translatables')->insert(['title' => json_encode(['en' => 'Hello World', 'km' => 'Sวัสดี'])]);

        $hit = DB::table('translatables');
        Search::whereJsonLike($hit, 'title', 'en', 'WORLD');
        expect($hit->count())->toBe(1);

        $miss = DB::table('translatables');
        Search::whereJsonLike($miss, 'title', 'km', 'world'); // 'world' is in the en value, not km
        expect($miss->count())->toBe(0);
    });

    it('whereLike / whereJsonLike skip a non-identifier column (no SQL spliced, no clause added)', function () {
        Widget::create(['name' => 'Alpha']);

        $q = Widget::query();
        Search::whereLike($q, 'name; DROP TABLE widgets', 'x');
        expect($q->toSql())->not->toContain('drop table')
            ->and($q->count())->toBe(1); // no WHERE added -> all rows
    });
});
