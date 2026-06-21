<?php

namespace Ngos\AdminCore\Translation;

use InvalidArgumentException;

/**
 * Resolves the configured translation driver. The provider binds {@see Translator} to
 * `(new TranslationManager)->driver()`, so the rest of the app depends on the interface and
 * config('admin-core.translation.driver') is the only switch. Auto-translate disabled
 * (`translation.enabled` = false) collapses to the {@see NullTranslator}.
 */
class TranslationManager
{
    /** @var array<string, class-string<Translator>> */
    protected array $drivers = [
        'mymemory' => MyMemoryTranslator::class,
        'libretranslate' => LibreTranslateTranslator::class,
        'null' => NullTranslator::class,
    ];

    public function driver(?string $name = null): Translator
    {
        if (! config('admin-core.translation.enabled', true)) {
            return new NullTranslator;
        }

        return $this->make($name ?: (string) config('admin-core.translation.driver', 'mymemory'));
    }

    /** Build a driver by name, bypassing the `enabled` gate (used by the admin-core:translate command). */
    public function make(string $name): Translator
    {
        if (! isset($this->drivers[$name])) {
            throw new InvalidArgumentException("Unknown admin-core translation driver [{$name}].");
        }

        return new $this->drivers[$name];
    }

    /** Register or override a driver (e.g. a host's DeepL/Google driver). */
    public function extend(string $name, string $class): self
    {
        $this->drivers[$name] = $class;

        return $this;
    }
}
