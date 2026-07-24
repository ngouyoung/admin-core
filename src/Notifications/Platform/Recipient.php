<?php

namespace Ngos\AdminCore\Notifications\Platform;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A resolved notification target: either an authenticated user on a guard, or a bare channel address (an email /
 * phone / channel handle for a non-user recipient). Constructed by the platform from the accepted `$to` inputs
 * (a Notifiable model, an email string, a phone string, or an explicit channel address) — never an interface a
 * host model implements, so products stay decoupled from the platform.
 *
 * API tier: PUBLIC · NEVER-EXTEND (final value object — build via the factories, read, never subclass).
 */
final class Recipient
{
    private function __construct(
        public readonly ?Authenticatable $user,
        public readonly ?string $guard,
        public readonly ?string $channel,
        public readonly ?string $address,
    ) {
    }

    /** A user recipient, resolved on the given guard (null = the application's default guard). */
    public static function forUser(Authenticatable $user, ?string $guard = null): self
    {
        return new self($user, $guard, null, null);
    }

    /** A bare-address recipient targeting one channel (e.g. an email with no user account). */
    public static function forAddress(string $channel, string $address): self
    {
        return new self(null, null, $channel, $address);
    }
}
