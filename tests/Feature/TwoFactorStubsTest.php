<?php

// The login + challenge controllers ship as App\-namespace stubs that only run in a host app. We pin
// their security-critical shipped content so a regression (e.g. dropping Auth::logout in the 2FA
// handoff, or the single-use recovery-code burn) fails the build. Mirrors the stub-content assertion
// style already used for migration/route stubs in InstallCommandTest.

function tfStub(string $rel): string
{
    return (string) file_get_contents(__DIR__ . '/../../stubs/' . $rel);
}

it('LoginController hands off to the 2FA challenge without completing the login', function () {
    expect(tfStub('access/Auth/LoginController.php.stub'))
        ->toContain("config('admin-core.two_factor.enabled')")
        ->toContain('hasConfirmedTwoFactorAuthentication()')
        ->toContain('Auth::logout()')                       // must NOT stay authenticated past the password step
        ->toContain("session()->put('login.id'")
        ->toContain("redirect()->route('two-factor.login')");
});

it('TwoFactorChallengeController verifies, burns recovery codes single-use, throttles, and clears the pending session', function () {
    expect(tfStub('access/Auth/TwoFactorChallengeController.php.stub'))
        ->toContain('verifyTwoFactorCode(')
        ->toContain('replaceRecoveryCode(')                 // single-use burn
        ->toContain('RateLimiter::tooManyAttempts')         // dedicated challenge throttle
        ->toContain('RateLimiter::hit')
        ->toContain("forget(['login.id', 'login.remember'])")
        ->toContain('loginUsingId(');
});

it('the auth + account route stubs wire the 2FA route names the controllers/views reference', function () {
    expect(tfStub('access/routes/auth.php.stub'))->toContain("'two-factor.login'");

    expect(tfStub('access/routes/account.php.stub'))
        ->toContain("'enable'")
        ->toContain("'confirm'")
        ->toContain("'disable'")
        ->toContain("'recovery-codes'");

    expect(tfStub('frontend/views/auth/two-factor-challenge.blade.php.stub'))
        ->toContain("route('two-factor.login')");
});

it('the install command publishes the two-factor challenge view', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../src/Console/AdminCoreInstallCommand.php');
    expect($src)
        ->toContain('two-factor-challenge.blade.php.stub')
        ->toContain("resource_path('views/auth/two-factor-challenge.blade.php')");
});
