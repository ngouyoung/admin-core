{{-- A date / datetime field wired to the bundled AirDatepicker (the .js-datepicker enhancer, which also
     re-inits on repeater rows via ac:repeater:added). Saves a string the model's date cast + the `date`
     validation rule both accept, so edits round-trip. Usage:
       <x-admin-core::date-input name="received_date" :value="old('received_date', $object?->received_date)" />
       <x-admin-core::date-input name="starts_at" mode="datetime" :value="old('starts_at', $object?->starts_at)" />

     `mode`  date (default, value yyyy-MM-dd) | datetime (value yyyy-MM-dd HH:mm)
     `value` a Carbon/DateTime (formatted for you) or a plain string (echoed as-is, so a bad re-submitted
             value can't throw). Any extra attribute (required, readonly, hint, placeholder…) passes through. --}}
@props(['name', 'label' => null, 'value' => null, 'mode' => 'date', 'hint' => null])
@php
    $label ??= \Illuminate\Support\Str::headline($name);
    $format = $mode === 'datetime' ? 'Y-m-d H:i' : 'Y-m-d';
    // Format a Carbon/DateTime; echo a scalar (string/number) as-is; anything else → empty (never throw).
    $display = match (true) {
        $value instanceof \DateTimeInterface => $value->format($format),
        is_scalar($value) => (string) $value,
        default => '',
    };
@endphp
<x-admin-core::input :name="$name" :label="$label" type="text" :value="$display" :hint="$hint"
    autocomplete="off" data-adp="{{ $mode === 'datetime' ? 'datetime' : 'date' }}"
    {{ $attributes->class('js-datepicker') }} />
