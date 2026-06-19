{{-- Status badge — the coloured pill used for enum columns. Pass a backed-enum
     instance or a plain string. Usage:
       <x-admin-core::status :value="$object->status" />
     Renders <span class="ac-status" data-status="draft">Draft</span>; the colour
     per status is mapped in app.scss (data-status). A blank value renders nothing,
     so it's safe to drop straight into a table cell. --}}
@props(['value' => null])
@php($acStatus = $value instanceof \BackedEnum ? $value->value : $value)
@if (filled($acStatus))
    <span {{ $attributes->merge(['class' => 'ac-status']) }} data-status="{{ $acStatus }}">{{ \Illuminate\Support\Str::headline((string) $acStatus) }}</span>
@endif
