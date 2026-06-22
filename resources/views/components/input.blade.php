{{-- A labelled input field row (label + control + validation error), styled consistently.
     One component per field keeps every form looking the same. Usage:
       <x-admin-core::input name="price" type="number" step="0.01" :value="old('price', $object?->price)" />
       <x-admin-core::input name="email" type="email" label="Email address" :value="old('email')" placeholder="you@example.com" />

     `name`     field name (drives id, label and the @error message)
     `label`    row label (defaults to the headline of `name`)
     `value`    current value
     `type`     text (default) | email | number | url | password | date | time | …
     `readonly` lock the control
     `hint`     muted help text rendered below the control
     Any extra attribute (placeholder, step, required, min, autofocus, …) passes through. --}}
@props(['name', 'label' => null, 'value' => null, 'type' => 'text', 'readonly' => false, 'hint' => null])
@php
    $label ??= \Illuminate\Support\Str::headline($name);
    $errorKey = rtrim(str_replace(['[', ']'], ['.', ''], $name), '.'); // settings[logo] -> settings.logo
@endphp
<x-admin-core::form-row :name="$name" :label="$label" :hint="$hint">
    <input type="{{ $type }}" name="{{ $name }}" id="{{ $name }}" value="{{ $value }}"
        @readonly($readonly) {{ $attributes->class(['form-control', 'is-invalid' => $errors->has($errorKey)]) }}>
</x-admin-core::form-row>
