<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Http\Middleware\RequireTwoFactor;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;
use Ngos\AdminCore\Tests\Fixtures\TwoFactorUser;

beforeEach(function () {
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name')->default('Admin');
        $table->string('email')->unique();
        $table->text('two_factor_secret')->nullable();
        $table->text('two_factor_recovery_codes')->nullable();
        $table->timestamp('two_factor_confirmed_at')->nullable();
        $table->unsignedBigInteger('two_factor_last_used_timestamp')->nullable();
        $table->timestamps();
    });

    config([
        'admin-core.two_factor.enabled' => true,
        'admin-core.two_factor.enforce' => true,
    ]);

    // Apply the middleware explicitly (deterministic single run) and register the routes it references.
    Route::middleware(RequireTwoFactor::class)->group(function () {
        Route::get('admin/dashboard', fn () => 'dash')->name('admin.dashboard');
        Route::get('admin/profile', fn () => 'profile')->name('admin.profile.index');
        Route::get('logout', fn () => 'bye')->name('logout');
        Route::get('home', fn () => 'home')->name('home'); // a non-admin (front-end) route
    });
});

function unconfirmed2faUser(): TwoFactorUser
{
    return TwoFactorUser::create(['name' => 'A', 'email' => 'a@example.com']);
}

function confirmed2faUser(): TwoFactorUser
{
    $u = TwoFactorUser::create(['name' => 'B', 'email' => 'b@example.com']);
    $u->enableTwoFactorAuthentication();
    $u->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $u;
}

it('redirects an enforced admin without confirmed 2FA to the profile setup', function () {
    $this->actingAs(unconfirmed2faUser())
        ->get('admin/dashboard')
        ->assertRedirect(route('admin.profile.index'));
});

it('lets the profile route through (no redirect loop)', function () {
    $this->actingAs(unconfirmed2faUser())->get('admin/profile')->assertOk();
});

it('passes a confirmed admin through', function () {
    $this->actingAs(confirmed2faUser())->get('admin/dashboard')->assertOk();
});

it('is a no-op when enforce is off', function () {
    config(['admin-core.two_factor.enforce' => false]);
    $this->actingAs(unconfirmed2faUser())->get('admin/dashboard')->assertOk();
});

it('is a no-op when 2FA is disabled', function () {
    config(['admin-core.two_factor.enabled' => false]);
    $this->actingAs(unconfirmed2faUser())->get('admin/dashboard')->assertOk();
});

it('exempts logout so an enforced user can still sign out (no redirect loop)', function () {
    $this->actingAs(unconfirmed2faUser())->get('logout')->assertOk();
});

it('does not enforce on non-admin (front-end) routes', function () {
    $this->actingAs(unconfirmed2faUser())->get('home')->assertOk();
});

it('is a no-op for a user model without the 2FA trait', function () {
    $plain = NotifiableUser::create(['name' => 'Plain', 'email' => 'plain@example.com']);
    $this->actingAs($plain)->get('admin/dashboard')->assertOk();
});
