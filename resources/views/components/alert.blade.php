{{-- An inline contextual alert with an icon (and optional dismiss). Usage:
       <x-admin-core::alert type="warning" dismissible>
           This action can't be undone.
       </x-admin-core::alert>
     `type` is info | success | warning | danger (error → danger). Pass `icon` to
     override the leading Bootstrap-Icon, or `:icon="false"` to drop it. This is for
     contextual messages in a page; one-off flash messages are handled by the layout. --}}
@props(['type' => 'info', 'dismissible' => false, 'icon' => null])
@php
    $acVariant = ['info' => 'info', 'success' => 'success', 'warning' => 'warning', 'danger' => 'danger', 'error' => 'danger'][$type] ?? 'info';
    $acIcon = $icon === false ? null : ($icon ?? [
        'info' => 'bi-info-circle', 'success' => 'bi-check-circle',
        'warning' => 'bi-exclamation-triangle', 'danger' => 'bi-x-circle',
    ][$acVariant]);
@endphp
<div {{ $attributes->merge(['class' => 'alert alert-' . $acVariant . ' d-flex align-items-start gap-2' . ($dismissible ? ' alert-dismissible fade show' : '')]) }} role="alert">
    @if ($acIcon)<i class="bi {{ $acIcon }} mt-1"></i>@endif
    <div class="flex-grow-1">{{ $slot }}</div>
    @if ($dismissible)<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>@endif
</div>
