{{-- A small count / label badge. Usage:
       <x-admin-core::badge tone="danger" pill>3</x-admin-core::badge>
       <x-admin-core::badge tone="secondary">Draft</x-admin-core::badge>
     `tone` maps to Bootstrap's text-bg-* colours (primary, secondary, success,
     danger, warning, info, light, dark); `pill` rounds it. For an enum status pill
     use <x-admin-core::status> instead. --}}
@props(['tone' => 'secondary', 'pill' => false])
<span {{ $attributes->merge(['class' => 'badge text-bg-' . $tone . ($pill ? ' rounded-pill' : '')]) }}>{{ $slot }}</span>
