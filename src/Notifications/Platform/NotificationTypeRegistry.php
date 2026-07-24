<?php

namespace Ngos\AdminCore\Notifications\Platform;

/**
 * The notification-type registry — presentation metadata ONLY. Products register an opaque type key with a
 * human label and its default channel set (via {@see NotificationPlatform::registerType()}); a later preference
 * UI reads this to render toggles and group notifications. It is NEVER consulted on the send path, so dispatch is
 * never coupled to a catalogue, and the platform ships zero built-in types or labels. The key is stored verbatim
 * and never parsed — the platform assigns it no business meaning.
 *
 * Bound as a singleton (app-scoped, reset per app boot). Mirrors {@see \Ngos\AdminCore\Dashboard\Dashboard::register()}
 * as a product-facing, boot()-time runtime registry.
 *
 * API tier: INTERNAL (the class). `NotificationPlatform::registerType()` is the PUBLIC surface.
 *
 * @internal
 */
final class NotificationTypeRegistry
{
    /** @var array<string, array{label: string, defaults: list<string>}> */
    private array $types = [];

    /**
     * Register (or override) an opaque type key's presentation metadata. No validation of business meaning — the
     * key is opaque; the label/defaults are product-supplied data passing through the kernel.
     *
     * @param  list<string>  $defaults  the type's default channel names
     */
    public function register(string $key, string $label, array $defaults = []): void
    {
        $this->types[$key] = ['label' => $label, 'defaults' => $defaults];
    }

    /** @return array<string, array{label: string, defaults: list<string>}> all registered types (for the future preference UI) */
    public function all(): array
    {
        return $this->types;
    }
}
