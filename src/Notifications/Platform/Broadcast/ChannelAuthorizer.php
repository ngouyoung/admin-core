<?php

namespace Ngos\AdminCore\Notifications\Platform\Broadcast;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Authorizes a connecting user to subscribe to a private notification channel. It grants access ONLY to the channel's
 * owner, decided purely by morph identity via the SAME {@see ChannelNameResolver} the publisher uses — so the name a
 * publisher targets and the name a subscriber is authorized for can never diverge. No cross-user (or cross-portal)
 * subscription is possible: a user can only ever be authorized for the channel their own identity resolves to.
 *
 * API tier: INTERNAL.
 *
 * @internal
 */
final class ChannelAuthorizer
{
    public function __construct(private readonly ChannelNameResolver $channels)
    {
    }

    /** True only if `<prefix>.{type}.{id}` is exactly the channel the connecting user's own identity resolves to. */
    public function authorize(Authenticatable $user, string $type, string $id): bool
    {
        $owned = $this->channels->forUser($user);

        return $owned !== null && $owned === $this->channels->prefix() . '.' . $type . '.' . $id;
    }
}
