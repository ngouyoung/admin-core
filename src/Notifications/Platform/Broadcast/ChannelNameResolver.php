<?php

namespace Ngos\AdminCore\Notifications\Platform\Broadcast;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Ngos\AdminCore\Notifications\Platform\Recipient;

/**
 * The SINGLE source of truth for a recipient's private broadcast channel name. Both sides call it, so the publish
 * side and the authorization side derive byte-identical names — the invariant that makes realtime delivery + isolation
 * correct (a mismatch would silently break delivery or authorization).
 *
 * The name is derived from the recipient's MORPH IDENTITY only — `getMorphClass()` + `getKey()`. There is deliberately
 * NO guard-specific logic: the platform's send() surface writes guard=null and the read side scopes by morph identity,
 * so isolation granularity is the morph identity (two portals sharing one user model on different guards share a
 * stream — exactly as the in-app inbox behaves today). Each identity component is encoded to one dot-free channel
 * segment by {@see segment()}, which is INJECTIVE: two distinct identities can never map to the same channel name.
 * That is a security invariant, not cosmetics — the same function derives BOTH the publish name and the authorization
 * name, so a collision would authorize one identity onto another's realtime stream (a non-integer/string primary key
 * such as `a-b@x.com` vs `a.b@x.com` is exactly the case a lossy sanitiser would collapse).
 *
 * A bare-address recipient (email/phone, `user === null`) has no morph identity and therefore no private channel.
 *
 * API tier: INTERNAL.
 *
 * @internal
 */
final class ChannelNameResolver
{
    /** The publish-side name for a resolved recipient, or null when it has no morph identity (bare address). */
    public function for(Recipient $recipient): ?string
    {
        return $recipient->user instanceof Model
            ? $this->channel($recipient->user->getMorphClass(), (string) $recipient->user->getKey())
            : null;
    }

    /** The authorization-side name for a connecting user — the SAME algorithm as {@see for()}. */
    public function forUser(Authenticatable $user): ?string
    {
        return $user instanceof Model
            ? $this->channel($user->getMorphClass(), (string) $user->getKey())
            : null;
    }

    /** Build the channel name from a morph identity: `<prefix>.<segment(type)>.<segment(id)>`. */
    public function channel(string $morphType, string $morphId): string
    {
        return $this->prefix() . '.' . $this->segment($morphType) . '.' . $this->segment($morphId);
    }

    /**
     * Encode one identity component into a single dot-free channel segment (so the `{type}.{id}` route params parse).
     * INJECTIVE: alphanumerics pass through; every other byte — including `_` itself — becomes `_` + its two-hex code.
     * Because a literal `_` is escaped, the `_` prefix is unambiguous, so distinct inputs never collide (unlike a lossy
     * replace-with-dash, which mapped `.`, `@`, `-`, `\` all onto the same `-`). Output stays within the Pusher/Reverb
     * channel-name charset and contains no `.`.
     */
    public function segment(string $value): string
    {
        return (string) preg_replace_callback(
            '/[^A-Za-z0-9]/',
            static fn (array $m): string => '_' . bin2hex($m[0]),
            $value,
        );
    }

    public function prefix(): string
    {
        return (string) config('admin-core.notifications.broadcast.channel_prefix', 'admin-core.notifications');
    }
}
