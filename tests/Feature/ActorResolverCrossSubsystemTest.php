<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Dashboard\Dashboard;
use Ngos\AdminCore\Models\ActivityLog;
use Ngos\AdminCore\Models\DashboardLayout;
use Ngos\AdminCore\Support\ActorResolver;
use Ngos\AdminCore\Tests\Fixtures\AuditedWidget;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;

/*
 * AR-1 — cross-subsystem identical resolution. Under ONE authentication state, every locus that used to hand-roll
 * its own guard walk now resolves the SAME (user, guard) via ActorResolver. The release-blocking case is the DUAL
 * active session (default `web` user A AND a portal guard user B): the canonical order is portal-first, so all loci
 * attribute to B — where the five default-first loci previously attributed to A (the audit-integrity defect).
 */

beforeEach(function () {
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
        $t->timestamps();
    });
    Schema::dropIfExists('widgets');
    Schema::create('widgets', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('secret')->nullable();
        $t->softDeletes();
        $t->timestamps();
    });
    Schema::dropIfExists('activity_logs');
    Schema::create('activity_logs', function (Blueprint $t) {
        $t->id();
        $t->string('log_name')->nullable();
        $t->string('description');
        $t->string('subject_type')->nullable();
        $t->string('subject_id')->nullable();
        $t->string('causer_type')->nullable();
        $t->string('causer_id')->nullable();
        $t->json('properties')->nullable();
        $t->timestamps();
    });
    Schema::dropIfExists('dashboard_layouts');
    Schema::create('dashboard_layouts', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->string('guard')->nullable();
        $t->json('layout');
        $t->timestamps();
        $t->unique(['user_id', 'guard']);
    });

    // A configured 'merchant' portal guard over the same users table.
    config(['admin-core.permission.guards' => ['merchant' => ['super_role' => 'merchant-admin']]]);
    config(['auth.guards.merchant' => ['driver' => 'session', 'provider' => 'users']]);
    config(['auth.providers.users' => ['driver' => 'eloquent', 'model' => NotifiableUser::class]]);
});

afterEach(function () {
    foreach (['dashboard_layouts', 'activity_logs', 'widgets', 'users'] as $t) {
        Schema::dropIfExists($t);
    }
});

it('resolves the PORTAL user across every subsystem under a dual active session — portal-first (AC-1, AC-3)', function () {
    $web = NotifiableUser::create(['name' => 'Web-A']);
    $merchant = NotifiableUser::create(['name' => 'Merchant-B']);
    $this->actingAs($web);                         // default 'web' guard — user A
    auth()->guard('merchant')->setUser($merchant); // portal guard — user B; BOTH now active

    // Pin the precondition: BOTH guards are genuinely authenticated (else this degrades to a single-session test
    // that a default-first resolver would also pass — the precedence proof is load-bearing on this).
    expect(auth()->guard('web')->check())->toBeTrue()
        ->and(auth()->guard('merchant')->check())->toBeTrue()
        ->and($web->getKey())->not->toBe($merchant->getKey()); // the two identities are distinguishable

    // The resolver: portal-first → B on the merchant guard, NOT the default-guard A.
    expect(ActorResolver::actor())->toBe([$merchant->getKey(), 'merchant']);

    // LogsActivity (audit causer) — a model change attributes to B, not the default-guard A.
    AuditedWidget::create(['name' => 'Alpha']);
    $log = ActivityLog::where('description', 'created')->firstOrFail();
    expect($log->causer_id)->toBe((string) $merchant->getKey())
        ->and($log->causer_type)->toBe($merchant->getMorphClass());

    // Dashboard (layout persistence) — the saved layout is keyed to B/merchant, not A/web.
    app(Dashboard::class)->saveLayout(['b', 'a'], ['c']);
    expect(DashboardLayout::where('user_id', $merchant->getKey())->where('guard', 'merchant')->exists())->toBeTrue()
        ->and(DashboardLayout::where('user_id', $web->getKey())->where('guard', 'web')->exists())->toBeFalse();
});

it('resolves the single signed-in user identically whether on the default or a portal guard (AC-2, parity)', function () {
    // Default-guard-only session → A/web everywhere (unchanged single-session behavior).
    $web = NotifiableUser::create(['name' => 'Web']);
    $this->actingAs($web);
    expect(ActorResolver::actor())->toBe([$web->getKey(), 'web']);
    app(Dashboard::class)->saveLayout(['x'], []);
    expect(DashboardLayout::where('user_id', $web->getKey())->where('guard', 'web')->exists())->toBeTrue();

    // Portal-guard-only session (default guard NOT authenticated) → B/merchant everywhere.
    auth()->guard('web')->logout();
    expect(auth()->guard('web')->check())->toBeFalse(); // the default guard really is logged out
    $merchant = NotifiableUser::create(['name' => 'Merchant']);
    auth()->guard('merchant')->setUser($merchant);
    expect(ActorResolver::actor())->toBe([$merchant->getKey(), 'merchant']);
    AuditedWidget::create(['name' => 'Beta']);
    $log = ActivityLog::where('description', 'created')->firstOrFail();
    expect($log->causer_id)->toBe((string) $merchant->getKey());
});

it('leaves no residual hand-rolled guard-resolution walk — ActorResolver is the single owner (AC-5)', function () {
    $resolver = dirname(__DIR__, 2).'/src/Support/ActorResolver.php';
    expect(file_exists($resolver))->toBeTrue(); // the single owner must exist

    // A walk = reads the WHOLE portal-guards list (to iterate it) AND resolves a user per guard. Matching the
    // COMBINATION (not one exact idiom) catches a new walk written differently, while the `…guards.{$guard}.super_role`
    // config lookups (list-read only) and the route/controller single-guard resolvers (per-guard only) each trip
    // just one half → not flagged. Only ActorResolver may hold both.
    $offenders = [];
    foreach (File::allFiles(dirname(__DIR__, 2).'/src') as $file) {
        if ($file->getExtension() !== 'php' || realpath($file->getRealPath()) === realpath($resolver)) {
            continue;
        }
        $src = $file->getContents();
        $readsGuardList = (bool) preg_match('/config\((["\'])admin-core\.permission\.guards\1/', $src);
        $resolvesPerGuard = str_contains($src, 'auth()->guard($');
        if ($readsGuardList && $resolvesPerGuard) {
            $offenders[] = $file->getRelativePathname();
        }
    }
    expect($offenders)->toBe([]);
});
