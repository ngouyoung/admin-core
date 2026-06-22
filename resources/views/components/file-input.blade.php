{{-- A file/image upload field row with a preview of the current value. Usage:
       <x-admin-core::file-input name="avatar" image :value="$object?->avatar" />
       <x-admin-core::file-input name="brochure" :value="$object?->brochure" />
     `image` accept images + show a thumbnail of the current value; otherwise show a "current file" link.
     `value` the stored path (for the preview only — a file <input> never carries a value). Wraps form-row. --}}
@props(['name', 'label' => null, 'image' => false, 'value' => null])
@php $label ??= \Illuminate\Support\Str::headline($name); @endphp
<x-admin-core::form-row :name="$name" :label="$label">
    <input type="file" name="{{ $name }}" id="{{ $name }}"
        {{ $attributes->class(['form-control', 'is-invalid' => $errors->has($name)]) }} @if ($image) accept="image/*" @endif>
    @if ($value)
        @if ($image)
            <img src="{{ \Ngos\AdminCore\Support\Media::url($value) }}" class="mt-2 rounded" style="height:60px" alt="{{ $label }}">
        @else
            <a href="{{ \Ngos\AdminCore\Support\Media::url($value) }}" target="_blank" class="d-block mt-1 small">current file</a>
        @endif
    @endif
</x-admin-core::form-row>
