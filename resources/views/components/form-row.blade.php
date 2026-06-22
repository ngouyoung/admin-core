{{-- One labelled field row in a Bootstrap horizontal form. Put the input control
     in the slot; the label, responsive columns and the validation-error message
     are wired for you. Usage:
       <x-admin-core::form-row name="price" label="Price">
           <input type="number" name="price" id="price" class="form-control @error('price') is-invalid @enderror" value="{{ old('price') }}">
       </x-admin-core::form-row>
     `name` drives the <label for> and the @error message; add `is-invalid` to the
     control yourself so the field itself highlights. `hint` renders muted help text below the control. --}}
@props(['name', 'label', 'hint' => null])
{{-- Normalise bracket names (settings[logo], name[en]) to Laravel's dot error key (settings.logo). --}}
@php($errorKey = rtrim(str_replace(['[', ']'], ['.', ''], $name), '.'))
<div class="row mb-3">
    <label for="{{ $name }}" class="col-md-2 col-sm-3 col-4 col-form-label text-end">{{ $label }}:</label>
    <div class="col-md-8 col-sm-8 col-8">
        {{ $slot }}
        @if ($hint)<div class="form-text">{{ $hint }}</div>@endif
        @if ($errors->has($errorKey))<div class="invalid-feedback d-block">{{ $errors->first($errorKey) }}</div>@endif
    </div>
</div>
