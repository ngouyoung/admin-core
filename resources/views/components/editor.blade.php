{{-- A rich-text (WYSIWYG) field row backed by CKEditor 5. Stores HTML in a normal text column. Usage:
       <x-admin-core::editor name="description" :value="old('description', $object?->description)" />

     `name` field name · `label` row label (defaults to the headline of name) · `value` current HTML.
     `min-height` the editable area height (default 250px — CKEditor 5 otherwise collapses to ~1 line).
     CKEditor's classic build is loaded once from the CDN only on pages that use the editor, pinned with an
     SRI integrity hash (a CDN tamper is rejected by the browser). For offline / air-gapped installs, download
     ckeditor.js, drop it in public/, and change the src below to a local path (remove the integrity attr).
     Extra attributes pass through to the textarea. --}}
@props(['name', 'label' => null, 'value' => null, 'minHeight' => '250px'])
@php $label ??= \Illuminate\Support\Str::headline($name); @endphp
<x-admin-core::form-row :name="$name" :label="$label">
    <textarea name="{{ $name }}" id="{{ $name }}" data-min-height="{{ $minHeight }}"
        {{ $attributes->class(['form-control', 'js-editor', 'is-invalid' => $errors->has($name)]) }}>{{ $value }}</textarea>
</x-admin-core::form-row>
@once
    @push('scripts')
        <script src="https://cdn.ckeditor.com/ckeditor5/41.4.2/classic/ckeditor.js"
                integrity="sha384-69SUO5s28dXCoNTUaA/KXhfDJu21xD394Gxk/S6d/YJZUxrz7Zagi+seruzXFTed"
                crossorigin="anonymous"></script>
        <script>
            document.querySelectorAll('textarea.js-editor').forEach(function (el) {
                if (window.ClassicEditor && !el.dataset.ckInit) {
                    el.dataset.ckInit = '1';
                    window.ClassicEditor.create(el).then(function (editor) {
                        // CKEditor 5 has no height config — set the editable's min-height via the view writer.
                        var h = el.dataset.minHeight || '250px';
                        editor.editing.view.change(function (writer) {
                            writer.setStyle('min-height', h, editor.editing.view.document.getRoot());
                        });
                    }).catch(function (e) { console.error(e); });
                }
            });
        </script>
    @endpush
@endonce
