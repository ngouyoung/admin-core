{{-- A rich-text (WYSIWYG) field row backed by CKEditor 5. Stores HTML in a normal text column. Usage:
       <x-admin-core::editor name="description" :value="old('description', $object?->description)" />

     `name` field name · `label` row label (defaults to the headline of name) · `value` current HTML.
     CKEditor's classic build is loaded once from the CDN only on pages that use the editor (self-host by
     swapping the script src if you need offline). Extra attributes pass through to the textarea. --}}
@props(['name', 'label' => null, 'value' => null])
@php $label ??= \Illuminate\Support\Str::headline($name); @endphp
<x-admin-core::form-row :name="$name" :label="$label">
    <textarea name="{{ $name }}" id="{{ $name }}"
        {{ $attributes->class(['form-control', 'js-editor', 'is-invalid' => $errors->has($name)]) }}>{{ $value }}</textarea>
</x-admin-core::form-row>
@once
    @push('scripts')
        <script src="https://cdn.ckeditor.com/ckeditor5/41.4.2/classic/ckeditor.js"></script>
        <script>
            document.querySelectorAll('textarea.js-editor').forEach(function (el) {
                if (window.ClassicEditor && !el.dataset.ckInit) {
                    el.dataset.ckInit = '1';
                    window.ClassicEditor.create(el).catch(function (e) { console.error(e); });
                }
            });
        </script>
    @endpush
@endonce
