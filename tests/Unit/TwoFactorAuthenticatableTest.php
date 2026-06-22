<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Tests\Fixtures\TwoFactorUser;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    Schema::dropIfExists('users');
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name')->default('Admin');
        $table->string('email')->unique();
        $table->string('password')->nullable();
        $table->text('two_factor_secret')->nullable();
        $table->text('two_factor_recovery_codes')->nullable();
        $table->timestamp('two_factor_confirmed_at')->nullable();
        $table->unsignedBigInteger('two_factor_last_used_timestamp')->nullable();
        $table->timestamps();
    });
});

function tfUser(): TwoFactorUser
{
    return TwoFactorUser::create(['name' => 'Admin', 'email' => 'admin@example.com']);
}

it('enables 2FA with an encrypted secret + recovery codes, unconfirmed', function () {
    $u = tfUser();
    $u->enableTwoFactorAuthentication();

    expect($u->hasEnabledTwoFactorAuthentication())->toBeTrue();
    expect($u->hasConfirmedTwoFactorAuthentication())->toBeFalse();

    // Stored encrypted: the raw column value is not the plaintext, but it decrypts back.
    $raw = $u->getAttribute('two_factor_secret');
    expect($raw)->toBeString();
    expect(Crypt::decryptString($raw))->not->toBe($raw);

    expect($u->recoveryCodes())->toHaveCount(config('admin-core.two_factor.recovery_codes'));
});

it('builds an otpauth url + an inline SVG QR', function () {
    $u = tfUser();
    $u->enableTwoFactorAuthentication();

    expect($u->otpauthUrl())
        ->toStartWith('otpauth://totp/')
        ->toContain(rawurlencode('admin@example.com')) // email is URL-encoded in the otpauth label
        ->toContain('secret=');
    expect($u->twoFactorQrCodeSvg())->toContain('<svg');
});

it('verifies a valid TOTP code and rejects a wrong one', function () {
    $u = tfUser();
    $u->enableTwoFactorAuthentication();

    $secret = Crypt::decryptString($u->getAttribute('two_factor_secret'));
    $code = (new Google2FA())->getCurrentOtp($secret);

    expect($u->verifyTwoFactorCode($code))->toBeTrue();
    expect($u->verifyTwoFactorCode('000000'))->toBeFalse();
});

it('confirms 2FA on a valid code and disables it cleanly', function () {
    $u = tfUser();
    $u->enableTwoFactorAuthentication();
    $secret = Crypt::decryptString($u->getAttribute('two_factor_secret'));

    expect($u->confirmTwoFactor('000000'))->toBeFalse();
    expect($u->confirmTwoFactor((new Google2FA())->getCurrentOtp($secret)))->toBeTrue();
    expect($u->fresh()->hasConfirmedTwoFactorAuthentication())->toBeTrue();

    $u->disableTwoFactor();
    expect($u->hasEnabledTwoFactorAuthentication())->toBeFalse();
    expect($u->fresh()->getAttribute('two_factor_secret'))->toBeNull();
});

it('burns a used recovery code (single-use) and keeps the set size constant', function () {
    $u = tfUser();
    $u->enableTwoFactorAuthentication();
    $codes = $u->recoveryCodes();
    $used = $codes[0];

    $u->replaceRecoveryCode($used);
    $after = $u->recoveryCodes();

    expect($after)->toHaveCount(count($codes));
    expect($after)->not->toContain($used);
});

it('regenerates the whole recovery-code set', function () {
    $u = tfUser();
    $u->enableTwoFactorAuthentication();
    $before = $u->recoveryCodes();

    $u->regenerateRecoveryCodes();

    expect($u->recoveryCodes())->not->toBe($before)->toHaveCount(count($before));
});

it('degrades to not-configured when the stored secret cannot be decrypted', function () {
    $u = tfUser();
    $u->forceFill(['two_factor_secret' => 'not-valid-ciphertext'])->save();

    expect($u->verifyTwoFactorCode('123456'))->toBeFalse();
    expect($u->recoveryCodes())->toBe([]);
});

it('rejects replay of a code already used within its window', function () {
    config(['admin-core.two_factor.window' => 1]);
    $u = tfUser();
    $u->enableTwoFactorAuthentication();
    $secret = Crypt::decryptString($u->getAttribute('two_factor_secret'));
    $code = (new Google2FA())->getCurrentOtp($secret);

    expect($u->verifyTwoFactorCode($code))->toBeTrue();   // first use accepted
    expect($u->verifyTwoFactorCode($code))->toBeFalse();  // same code replayed → rejected
});

it('honors the drift window (previous-step code accepted only when window >= 1)', function () {
    $g = new Google2FA();

    config(['admin-core.two_factor.window' => 0]);
    $a = TwoFactorUser::create(['name' => 'A', 'email' => 'win0@example.com']);
    $a->enableTwoFactorAuthentication();
    $prevA = $g->oathTotp(Crypt::decryptString($a->getAttribute('two_factor_secret')), $g->getTimestamp() - 1);
    expect($a->verifyTwoFactorCode($prevA))->toBeFalse();

    config(['admin-core.two_factor.window' => 1]);
    $b = TwoFactorUser::create(['name' => 'B', 'email' => 'win1@example.com']);
    $b->enableTwoFactorAuthentication();
    $prevB = $g->oathTotp(Crypt::decryptString($b->getAttribute('two_factor_secret')), $g->getTimestamp() - 1);
    expect($b->verifyTwoFactorCode($prevB))->toBeTrue();
});
