<?php

namespace Ngos\AdminCore\Notifications\Platform\Contracts;

/**
 * A product-constructed render intent: WHAT a recipient should be shown, described channel-agnostically and
 * recipient-agnostically (one message → many recipients → many channels). It is NOT a data envelope: it carries
 * only the frozen render fields, never recipients, business entities, transport metadata, or anything the platform
 * would have to interpret. The type is an opaque, product-owned key the platform never parses.
 *
 * API tier: PUBLIC. Products implement it (or construct a provided implementation); the platform only consumes it.
 * New fields are added additively and optionally — the God-DTO firewall (see the Notification Platform spec §1)
 * rejects any field that would carry who/where/how rather than what-to-show.
 */
interface NotificationMessage
{
    /** The opaque, product-owned type key. The platform stores and routes it; it never parses or interprets it. */
    public function type(): string;

    /** The literal headline, or null when {@see translationKey()} supplies it. */
    public function title(): ?string;

    /** An i18n key for the headline (resolved per-recipient at render time), or null for a literal {@see title()}. */
    public function translationKey(): ?string;

    /** @return array<string, mixed> parameters for {@see translationKey()} */
    public function translationParams(): array;

    /** An optional supporting line, or null. */
    public function body(): ?string;

    /** A deep-link to the real resource — how a message points at business data WITHOUT carrying it — or null. */
    public function url(): ?string;

    /** A presentation glyph key (e.g. a Bootstrap Icons class), or null. */
    public function icon(): ?string;

    /**
     * A small, presentation-only map of already-rendered scalars a template may interpolate. Bounded — never a
     * generic dump: no business objects, no join keys, no transport metadata (see the spec §1/§3 rules).
     *
     * @return array<string, scalar|null>
     */
    public function data(): array;
}
