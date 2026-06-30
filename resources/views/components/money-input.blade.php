{{-- A money input: a number control prefixed/suffixed with the currency symbol, posting the MAJOR amount
     (what a human types — "15.00" / "15000"). The model's MoneyCast turns that into exact minor units on save.
     The step + symbol come from the currency's config (admin-core.money.currencies), so a 2-decimal currency
     gets step="0.01" and a 0-decimal one (KHR) step="1". Usage:
       <x-admin-core::money-input name="price" :value="old('price', $object?->price?->major())" />
       <x-admin-core::money-input name="price" currency="KHR" :value="old('price', $object?->price?->major())" />

     `name`     field name (drives id, label and the @error message)
     `label`    row label (defaults to the headline of `name`)
     `value`    current MAJOR amount as a plain string ("15.00"); use $object?->price?->major()
     `currency` override the configured default for this field
     `readonly` lock the control
     `hint`     muted help text below the control --}}
@props(['name', 'label' => null, 'value' => null, 'currency' => null, 'readonly' => false, 'hint' => null])
@php
    $label ??= \Illuminate\Support\Str::headline($name);
    // A per-record currency may arrive as a BackedEnum (the row's enum column) — use its backing code.
    $currency = $currency instanceof \BackedEnum ? $currency->value : $currency;
    $cfg = \Ngos\AdminCore\Support\Money::config($currency);
    $step = $cfg['decimals'] > 0 ? '0.' . str_repeat('0', $cfg['decimals'] - 1) . '1' : '1';
    $errorKey = rtrim(str_replace(['[', ']'], ['.', ''], $name), '.');
@endphp
<x-admin-core::form-row :name="$name" :label="$label" :hint="$hint">
    <div class="input-group @error($errorKey) has-validation @enderror">
        @if ($cfg['position'] !== 'after')
            <span class="input-group-text">{{ $cfg['symbol'] }}</span>
        @endif
        <input type="number" inputmode="decimal" step="{{ $step }}" name="{{ $name }}" id="{{ $name }}"
            value="{{ $value }}" @readonly($readonly)
            {{ $attributes->class(['form-control', 'is-invalid' => $errors->has($errorKey)]) }}>
        @if ($cfg['position'] === 'after')
            <span class="input-group-text">{{ $cfg['symbol'] }}</span>
        @endif
    </div>
</x-admin-core::form-row>
