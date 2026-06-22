<?php

namespace Ngos\AdminCore\Concerns;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Opt-in TOTP two-factor authentication for the admin user model (Fortify-style).
 *
 * Adds three columns to `users` (see the add_two_factor_to_users_table migration):
 *   - two_factor_secret          encrypted TOTP secret
 *   - two_factor_recovery_codes  encrypted JSON list of single-use recovery codes
 *   - two_factor_confirmed_at    set once the user proves they scanned the QR
 *
 * The login flow only challenges users whose 2FA is *confirmed*, so an abandoned,
 * unconfirmed setup can never lock anyone out. The secret and recovery codes are
 * stored encrypted; a decrypt failure (e.g. APP_KEY rotation) degrades gracefully
 * to "not configured" instead of crashing the login.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait TwoFactorAuthenticatable
{
    /**
     * Hide the 2FA columns from array/JSON serialization on any model that uses this trait — so the
     * encrypted secret + recovery codes never leak through toArray()/toJson(), a CSV export, or an API
     * resource. Eloquent calls initialize{TraitName}() per instance. (Matches Laravel Fortify.)
     */
    public function initializeTwoFactorAuthenticatable(): void
    {
        $this->hidden = array_values(array_unique(array_merge($this->getHidden(), [
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_last_used_timestamp',
        ])));
    }

    /** Generate a fresh secret + recovery codes. Unconfirmed until the user verifies a code. */
    public function enableTwoFactorAuthentication(): void
    {
        $this->forceFill([
            'two_factor_secret' => Crypt::encryptString($this->google2fa()->generateSecretKey()),
            'two_factor_recovery_codes' => Crypt::encryptString((string) json_encode($this->generateRecoveryCodes())),
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_timestamp' => null,
        ])->save();
    }

    /** Confirm setup: the user proves they scanned the QR by entering a valid code. */
    public function confirmTwoFactor(string $code): bool
    {
        if (! $this->verifyTwoFactorCode($code)) {
            return false;
        }

        $this->forceFill(['two_factor_confirmed_at' => $this->freshTimestamp()])->save();

        return true;
    }

    /** Turn 2FA off entirely (clears the secret + recovery codes). */
    public function disableTwoFactor(): void
    {
        $this->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_timestamp' => null,
        ])->save();
    }

    /** @return list<string> */
    public function recoveryCodes(): array
    {
        $stored = $this->getAttribute('two_factor_recovery_codes');
        if (! is_string($stored) || $stored === '') {
            return [];
        }

        try {
            $codes = json_decode(Crypt::decryptString($stored), true);
        } catch (DecryptException) {
            return [];
        }

        return is_array($codes) ? array_values(array_map('strval', $codes)) : [];
    }

    /** Burn a used recovery code (single-use) and top the set back up to its configured size. */
    public function replaceRecoveryCode(string $code): void
    {
        $codes = array_values(array_filter(
            $this->recoveryCodes(),
            static fn (string $c): bool => ! hash_equals($c, $code),
        ));
        $codes[] = $this->newRecoveryCode();

        $this->forceFill([
            'two_factor_recovery_codes' => Crypt::encryptString((string) json_encode($codes)),
        ])->save();
    }

    /** Replace the whole recovery-code set (the "regenerate" action). */
    public function regenerateRecoveryCodes(): void
    {
        $this->forceFill([
            'two_factor_recovery_codes' => Crypt::encryptString((string) json_encode($this->generateRecoveryCodes())),
        ])->save();
    }

    /** otpauth:// URL embedded in the QR code (and usable for manual entry). */
    public function otpauthUrl(): string
    {
        return $this->google2fa()->getQRCodeUrl(
            (string) config('app.name'),
            (string) $this->getAttribute('email'),
            $this->decryptedSecret(),
        );
    }

    /** Inline SVG QR code (Bacon SVG backend — needs only ext-dom, no gd/imagick). */
    public function twoFactorQrCodeSvg(): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd()));

        return $writer->writeString($this->otpauthUrl());
    }

    public function verifyTwoFactorCode(string $code): bool
    {
        $secret = $this->decryptedSecret();
        if ($secret === '') {
            return false;
        }

        $window = (int) config('admin-core.two_factor.window', 1);
        $lastUsed = (int) $this->getAttribute('two_factor_last_used_timestamp');

        // verifyKeyNewer rejects a code whose time-step is at/below the last one we accepted, so a still-
        // valid code can't be replayed within its window. Pass an int (not null) oldTimestamp so it returns
        // the matched time-step we persist (with null it returns a bare bool and replay can't be tracked).
        $timestamp = $this->google2fa()->verifyKeyNewer($secret, $code, $lastUsed, $window);

        if ($timestamp === false) {
            return false;
        }

        $this->forceFill(['two_factor_last_used_timestamp' => $timestamp])->save();

        return true;
    }

    public function hasEnabledTwoFactorAuthentication(): bool
    {
        return ! is_null($this->getAttribute('two_factor_secret'));
    }

    /** The login flow gates on this — never on hasEnabled — so an unconfirmed setup can't lock anyone out. */
    public function hasConfirmedTwoFactorAuthentication(): bool
    {
        return ! is_null($this->getAttribute('two_factor_confirmed_at'));
    }

    protected function decryptedSecret(): string
    {
        $stored = $this->getAttribute('two_factor_secret');
        if (! is_string($stored) || $stored === '') {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return '';
        }
    }

    /** @return list<string> */
    protected function generateRecoveryCodes(): array
    {
        $count = max(1, (int) config('admin-core.two_factor.recovery_codes', 8));

        return array_map(fn (): string => $this->newRecoveryCode(), range(1, $count));
    }

    protected function newRecoveryCode(): string
    {
        return Str::upper(Str::random(10) . '-' . Str::random(10));
    }

    protected function google2fa(): Google2FA
    {
        return new Google2FA();
    }
}
