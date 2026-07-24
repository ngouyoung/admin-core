<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Support\ActorResolver;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;

/*
 * AR-1 — the single actor/guard resolver. Canonical order is PORTAL-FIRST (configured portal guards before the
 * default guard, de-duplicated), with the undefined-guard hardening in one place. These tests permute the four
 * auth states the spec names: single guard, dual session, undefined-guard-first, unauthenticated.
 */

beforeEach(function () {
    Schema::dropIfExists('users'); // defensive for persistent engines (TS-1)
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->timestamps();
    });
});

afterEach(fn () => Schema::dropIfExists('users'));

function configurePortalGuard(string $name = 'merchant'): void
{
    config(["admin-core.permission.guards.{$name}" => ['super_role' => "{$name}-admin"]]);
    config(["auth.guards.{$name}" => ['driver' => 'session', 'provider' => 'users']]);
}

/** A configured portal guard whose user() throws a REAL fault (a DB/provider error), consulted first (portal-first). */
function configureFaultyGuard(string $name = 'faulty'): void
{
    config(["admin-core.permission.guards.{$name}" => ['super_role' => "{$name}-admin"]]);
    config(["auth.guards.{$name}" => ['driver' => "{$name}-driver", 'provider' => 'users']]);
    Auth::extend("{$name}-driver", fn () => new class implements \Illuminate\Contracts\Auth\Guard
    {
        public function check(): bool { throw new \RuntimeException('provider down'); }

        public function guest(): bool { return true; }

        public function user() { throw new \RuntimeException('provider down'); }

        public function id() { throw new \RuntimeException('provider down'); }

        public function validate(array $credentials = []): bool { return false; }

        public function hasUser(): bool { return false; }

        public function setUser(\Illuminate\Contracts\Auth\Authenticatable $user) {}
    });
}

it('builds the canonical portal-first guard order, de-duplicated', function () {
    config(['admin-core.permission.guards' => ['merchant' => [], 'vendor' => []]]);
    config(['auth.defaults.guard' => 'web']);

    expect(ActorResolver::guards())->toBe(['merchant', 'vendor', 'web']); // portal guards first, default last
});

it('does not duplicate the default guard when it is also a configured portal guard', function () {
    config(['admin-core.permission.guards' => ['web' => [], 'merchant' => []]]);
    config(['auth.defaults.guard' => 'web']);

    expect(ActorResolver::guards())->toBe(['web', 'merchant']); // web appears once
});

it('resolves the default-guard user when only the default guard is authenticated (single session)', function () {
    $user = NotifiableUser::create(['name' => 'Web']);
    $this->actingAs($user); // the default 'web' guard

    [$resolved, $guard] = ActorResolver::resolve();
    expect($resolved?->getAuthIdentifier())->toBe($user->getAuthIdentifier())
        ->and($guard)->toBe('web')
        ->and(ActorResolver::actor())->toBe([$user->getAuthIdentifier(), 'web'])
        ->and(ActorResolver::check())->toBeTrue();
});

it('resolves the PORTAL user when both the default and a portal guard are active (dual session, portal-first)', function () {
    configurePortalGuard('merchant');
    $web = NotifiableUser::create(['name' => 'Web']);
    $merchant = NotifiableUser::create(['name' => 'Merchant']);
    $this->actingAs($web);                         // default guard
    auth()->guard('merchant')->setUser($merchant); // portal guard — BOTH now active

    [$resolved, $guard] = ActorResolver::resolve();
    expect($resolved?->getAuthIdentifier())->toBe($merchant->getAuthIdentifier()) // portal wins, not $web
        ->and($guard)->toBe('merchant')
        ->and(ActorResolver::actor())->toBe([$merchant->getAuthIdentifier(), 'merchant']);
});

it('skips a guard named in admin-core config but absent from auth.php (undefined-guard hardening)', function () {
    config(['admin-core.permission.guards.ghost' => ['super_role' => 'ghost-admin']]); // never defined in auth.guards
    $web = NotifiableUser::create(['name' => 'Web']);
    $this->actingAs($web);

    // 'ghost' LEADS the canonical order (portal-first) but throws when resolved — so the try/catch is genuinely
    // exercised: it must be skipped, not fatal, and resolution falls through to the default 'web' guard.
    expect(ActorResolver::guards()[0])->toBe('ghost')
        ->and(ActorResolver::user()?->getAuthIdentifier())->toBe($web->getAuthIdentifier())
        ->and(ActorResolver::guard())->toBe('web');
});

it('returns a null pair when no configured guard is authenticated', function () {
    configurePortalGuard('merchant');

    expect(ActorResolver::resolve())->toBe([null, null])
        ->and(ActorResolver::user())->toBeNull()
        ->and(ActorResolver::guard())->toBeNull()
        ->and(ActorResolver::actor())->toBe([null, null])
        ->and(ActorResolver::check())->toBeFalse();
});

it('propagates a real fault during user resolution instead of swallowing it (WP-B13a)', function () {
    // A guard whose user() throws a NON-InvalidArgumentException fault (a DB/provider error) — the narrowed catch
    // must NOT swallow it. Every entry point funnels through resolve(), so all must surface the fault.
    configureFaultyGuard('faulty');

    expect(fn () => ActorResolver::resolve())->toThrow(RuntimeException::class, 'provider down');
    expect(fn () => ActorResolver::user())->toThrow(RuntimeException::class);
    expect(fn () => ActorResolver::check())->toThrow(RuntimeException::class);
});

it('does not mis-resolve to a later guard when an earlier guard faults (WP-B13a)', function () {
    // The faulting portal guard is consulted FIRST (portal-first). The default guard is authenticated as $web.
    // resolve() must THROW — it must NOT silently fall through and return ($web, 'web'), which would attribute the
    // request to the WRONG identity (the audit-integrity failure AR-1 exists to prevent).
    configureFaultyGuard('faulty');
    $web = NotifiableUser::create(['name' => 'Web']);
    $this->actingAs($web);

    expect(ActorResolver::guards()[0])->toBe('faulty')                       // faulty is consulted before web…
        ->and(fn () => ActorResolver::resolve())->toThrow(RuntimeException::class); // …so the fault surfaces, no (A, web)
});

it('still skips an undefined guard (InvalidArgumentException) after the catch is narrowed (WP-B13a regression)', function () {
    // The one documented skip reason — a guard in admin-core config but absent from auth.php — throws
    // InvalidArgumentException at construction and must STILL be skipped, not propagated.
    config(['admin-core.permission.guards.ghost' => ['super_role' => 'ghost-admin']]); // no auth.guards.ghost
    $web = NotifiableUser::create(['name' => 'Web']);
    $this->actingAs($web);

    expect(ActorResolver::guards()[0])->toBe('ghost')
        ->and(ActorResolver::guard())->toBe('web')               // ghost skipped, falls through to web
        ->and(ActorResolver::user()?->getAuthIdentifier())->toBe($web->getAuthIdentifier());
});
