<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Tests\Fixtures\TwoFactorUser;
use PragmaRX\Google2FA\Google2FA;

// Load the actual shipped stubs (App\ namespace) so we exercise the real published controller + requests.
require_once __DIR__ . '/../../stubs/access/Http/Requests/Profile/ConfirmTwoFactorRequest.php.stub';
require_once __DIR__ . '/../../stubs/access/Http/Requests/Profile/TwoFactorPasswordRequest.php.stub';
require_once __DIR__ . '/../../stubs/access/Http/Controllers/Backend/TwoFactorController.php.stub';

beforeEach(function () {
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name')->default('Admin');
        $table->string('email')->unique();
        $table->string('password');
        $table->text('two_factor_secret')->nullable();
        $table->text('two_factor_recovery_codes')->nullable();
        $table->timestamp('two_factor_confirmed_at')->nullable();
        $table->unsignedBigInteger('two_factor_last_used_timestamp')->nullable();
        $table->timestamps();
    });

    config(['admin-core.two_factor.enabled' => true]);

    Route::middleware('web')->prefix('admin/profile/two-factor')->name('admin.profile.two-factor.')->group(function () {
        Route::post('enable', [\App\Http\Controllers\Backend\TwoFactorController::class, 'enable'])->name('enable');
        Route::post('confirm', [\App\Http\Controllers\Backend\TwoFactorController::class, 'confirm'])->name('confirm');
        Route::delete('/', [\App\Http\Controllers\Backend\TwoFactorController::class, 'disable'])->name('disable');
        Route::post('recovery-codes', [\App\Http\Controllers\Backend\TwoFactorController::class, 'regenerateRecoveryCodes'])->name('recovery-codes');
    });
});

function mgmtUser(): TwoFactorUser
{
    return TwoFactorUser::create(['name' => 'A', 'email' => 'mgmt@example.com', 'password' => Hash::make('secret-pass')]);
}

it('enable() generates an unconfirmed secret + recovery codes', function () {
    $u = mgmtUser();
    $this->actingAs($u)->post(route('admin.profile.two-factor.enable'))->assertRedirect();

    $u->refresh();
    expect($u->hasEnabledTwoFactorAuthentication())->toBeTrue();
    expect($u->hasConfirmedTwoFactorAuthentication())->toBeFalse();
    expect($u->recoveryCodes())->not->toBeEmpty();
});

it('confirm() confirms on a valid code and errors on a bad one', function () {
    $u = mgmtUser();
    $u->enableTwoFactorAuthentication();
    $secret = Crypt::decryptString($u->getAttribute('two_factor_secret'));

    $this->actingAs($u)->post(route('admin.profile.two-factor.confirm'), ['code' => '000000'])
        ->assertSessionHasErrors('code');
    expect($u->fresh()->hasConfirmedTwoFactorAuthentication())->toBeFalse();

    $this->actingAs($u)->post(route('admin.profile.two-factor.confirm'), ['code' => (new Google2FA())->getCurrentOtp($secret)])
        ->assertSessionHasNoErrors();
    expect($u->fresh()->hasConfirmedTwoFactorAuthentication())->toBeTrue();
});

it('disable() requires the current password', function () {
    $u = mgmtUser();
    $u->enableTwoFactorAuthentication();

    $this->actingAs($u)->delete(route('admin.profile.two-factor.disable'), ['current_password' => 'wrong'])
        ->assertSessionHasErrors('current_password');
    expect($u->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();

    $this->actingAs($u)->delete(route('admin.profile.two-factor.disable'), ['current_password' => 'secret-pass'])
        ->assertSessionHasNoErrors();
    expect($u->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

it('regenerate() requires the current password and rotates the codes', function () {
    $u = mgmtUser();
    $u->enableTwoFactorAuthentication();
    $before = $u->recoveryCodes();

    $this->actingAs($u)->post(route('admin.profile.two-factor.recovery-codes'), ['current_password' => 'wrong'])
        ->assertSessionHasErrors('current_password');
    expect($u->fresh()->recoveryCodes())->toBe($before);

    $this->actingAs($u)->post(route('admin.profile.two-factor.recovery-codes'), ['current_password' => 'secret-pass'])
        ->assertSessionHasNoErrors();
    expect($u->fresh()->recoveryCodes())->not->toBe($before);
});

it('all 2FA management endpoints 404 when the feature is disabled', function () {
    config(['admin-core.two_factor.enabled' => false]);
    $u = mgmtUser();

    $this->actingAs($u)->post(route('admin.profile.two-factor.enable'))->assertNotFound();
    $this->actingAs($u)->post(route('admin.profile.two-factor.confirm'), ['code' => '123456'])->assertNotFound();
    $this->actingAs($u)->delete(route('admin.profile.two-factor.disable'), ['current_password' => 'secret-pass'])->assertNotFound();
    $this->actingAs($u)->post(route('admin.profile.two-factor.recovery-codes'), ['current_password' => 'secret-pass'])->assertNotFound();
});
