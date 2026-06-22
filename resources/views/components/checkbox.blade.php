{{-- A boolean checkbox field row. A hidden 0 is submitted before the box, so an unchecked box still
     posts the field (the 1 wins when checked). Usage:
       <x-admin-core::checkbox name="is_active" :label="__('Active')" :checked="old('is_active', $object?->is_active)" />
       <x-admin-core::checkbox name="featured" :checked="$on" switch />
     `checked` the current value · `switch` render as a Bootstrap switch. Wraps form-row for the label/error. --}}
@props(['name', 'label' => null, 'checked' => false, 'switch' => false])
@php $label ??= \Illuminate\Support\Str::headline($name); @endphp
<x-admin-core::form-row :name="$name" :label="$label">
    <input type="hidden" name="{{ $name }}" value="0">
    <div class="form-check{{ $switch ? ' form-switch' : '' }}">
        <input type="checkbox" name="{{ $name }}" id="{{ $name }}" value="1"
            {{ $attributes->class(['form-check-input', 'is-invalid' => $errors->has($name)]) }} @checked($checked)>
    </div>
</x-admin-core::form-row>
