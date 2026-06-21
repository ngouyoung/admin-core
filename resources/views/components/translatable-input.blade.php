{{-- A multi-language input: one field per configured locale, plus a hidden marker so the AutoTranslate
     middleware fills the locales left blank on save (you type one language, the rest come back filled —
     no endpoint, nothing overwritten). Works for any number of locales (config admin-core.translation.locales).

     Usage (the model stores per-locale values, e.g. a JSON/translatable `name`):
       <x-admin-core::translatable-input name="name" label="Name" :value="old('name', $product->name ?? [])" />

     `name`  the field name (submits as name[en], name[km], …)
     `label` the row label
     `value` array|null of locale => current value
     `type`  'text' (default) or 'textarea' --}}
@props(['name', 'label' => null, 'value' => [], 'type' => 'text'])

@php
    $locales = (array) config('admin-core.translation.locales', ['en' => 'English']);
    $current = is_array($value) ? $value : [];
    $label ??= \Illuminate\Support\Str::headline($name);
@endphp

<div class="row mb-3" data-ac-translatable="{{ $name }}">
    <label class="col-md-2 col-sm-3 col-4 col-form-label text-end">{{ $label }}:</label>
    <div class="col-md-8 col-sm-8 col-8">
        {{-- tells the middleware: fill empty locales of this field from whichever one is filled --}}
        <input type="hidden" name="_translate[]" value="{{ $name }}">

        @foreach ($locales as $code => $native)
            @php $field = $name . '[' . $code . ']'; $val = old($name . '.' . $code, $current[$code] ?? ''); @endphp
            <div class="input-group mb-2">
                <span class="input-group-text text-uppercase" style="min-width:3.25rem">{{ $code }}</span>
                @if ($type === 'textarea')
                    <textarea name="{{ $field }}" class="form-control @error($name.'.'.$code) is-invalid @enderror"
                              placeholder="{{ $native }}" rows="2">{{ $val }}</textarea>
                @else
                    <input type="text" name="{{ $field }}" value="{{ $val }}"
                           class="form-control @error($name.'.'.$code) is-invalid @enderror" placeholder="{{ $native }}">
                @endif
                @error($name.'.'.$code)<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endforeach

        <div class="form-text">
            <i class="bi bi-translate me-1"></i>{{ __('admin-core::admin-core.actions.translate') }} —
            {{ __('Fill one language; the rest are filled automatically on save.') }}
        </div>
    </div>
</div>
