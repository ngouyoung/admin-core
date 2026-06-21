{{-- A labelled <select> field row. Give options as an [value => label] array, or render
     <option>s yourself in the slot. select2-enhanced by default (matches every other dropdown).
     Usage:
       <x-admin-core::select name="status" :options="['active' => 'Active', 'inactive' => 'Inactive']" :value="old('status', $object?->status)" />
       <x-admin-core::select name="category_id" :options="$categories" placeholder="— select —" :value="old('category_id', $object?->category_id)" />
       <x-admin-core::select name="tags" :options="$tags" :value="$selectedTagIds" multiple />

     `name` field name · `label` row label · `value` selected value (array when multiple)
     `options` [value => label] · `placeholder` empty first option (single only)
     `multiple` multi-select · `enhance` add the select2 class (default true). Extra attrs pass through. --}}
@props(['name', 'label' => null, 'value' => null, 'options' => [], 'placeholder' => null, 'multiple' => false, 'enhance' => true])
@php
    $label ??= \Illuminate\Support\Str::headline($name);
    $selected = array_map('strval', (array) $value);
@endphp
<x-admin-core::form-row :name="$name" :label="$label">
    <select name="{{ $name }}{{ $multiple ? '[]' : '' }}" id="{{ $name }}" @if ($multiple) multiple @endif
        {{ $attributes->class(['form-select', 'admin-core-select' => $enhance, 'is-invalid' => $errors->has($name)]) }}>
        @if ($placeholder !== null && ! $multiple)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $val => $text)
            <option value="{{ $val }}" @selected(in_array((string) $val, $selected, true))>{{ $text }}</option>
        @endforeach
        {{ $slot }}
    </select>
</x-admin-core::form-row>
