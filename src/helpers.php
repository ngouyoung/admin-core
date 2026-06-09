<?php

if (! function_exists('setting')) {
    /**
     * Read an admin-core setting (cached). Falls back to $default when the
     * Settings module isn't installed (e.g. a minimal admin-core:install).
     */
    function setting(string $key, $default = null)
    {
        return class_exists(\App\Models\Setting::class)
            ? \App\Models\Setting::get($key, $default)
            : $default;
    }
}
