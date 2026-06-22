{{-- A labelled textarea field row (label + control + validation error). Usage:
       <x-admin-core::textarea name="description" :value="old('description', $object?->description)" rows="4" />

     `name` field name · `label` row label (defaults to headline of name) · `value` current text
     `rows` height (default 3) · `readonly` lock. Extra attributes pass through. --}}
@props(['name', 'label' => null, 'value' => null, 'rows' => 3, 'readonly' => false])
@php
    $label ??= \Illuminate\Support\Str::headline($name);
    $errorKey = rtrim(str_replace(['[', ']'], ['.', ''], $name), '.'); // settings[about] -> settings.about
@endphp
<x-admin-core::form-row :name="$name" :label="$label">
    <textarea name="{{ $name }}" id="{{ $name }}" rows="{{ $rows }}"
        @readonly($readonly) {{ $attributes->class(['form-control', 'is-invalid' => $errors->has($errorKey)]) }}>{{ $value }}</textarea>
</x-admin-core::form-row>
