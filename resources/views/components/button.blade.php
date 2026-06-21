{{-- A consistent button (or link when `href` is set). One component for every call-to-action,
     so colour, size and icon spacing stay uniform. Usage:
       <x-admin-core::button type="submit">Save</x-admin-core::button>
       <x-admin-core::button variant="danger" icon="bi bi-trash">Delete</x-admin-core::button>
       <x-admin-core::button :href="route('admin.products.index')" variant="secondary" outline>Back</x-admin-core::button>

     `variant` primary (default) | secondary | success | danger | warning | info | light | dark | link
     `type` button (default) | submit | reset    `href` render an <a> instead of <button>
     `size` sm | lg | null    `outline` outline style    `icon` bootstrap-icon class (e.g. "bi bi-plus")
     Extra attributes (disabled, data-*, @click, …) pass through. --}}
@props(['variant' => 'primary', 'type' => 'button', 'href' => null, 'size' => null, 'outline' => false, 'icon' => null])
@php
    $classes = collect([
        'btn',
        'btn-' . ($outline ? 'outline-' : '') . $variant,
        $size ? 'btn-' . $size : null,
    ])->filter()->implode(' ');
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<i class="{{ $icon }} me-1"></i>@endif{{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<i class="{{ $icon }} me-1"></i>@endif{{ $slot }}
    </button>
@endif
