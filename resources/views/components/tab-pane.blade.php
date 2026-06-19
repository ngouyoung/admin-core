{{-- One pane inside <x-admin-core::tabs>. `id` must match a key in the tabs map;
     mark the first pane `active` so it shows on load. Usage: see <x-admin-core::tabs>. --}}
@props(['id', 'active' => false])
<div {{ $attributes->merge(['class' => 'tab-pane fade' . ($active ? ' show active' : '')]) }}
     id="{{ $id }}" role="tabpanel" aria-labelledby="{{ $id }}-tab" tabindex="0">
    {{ $slot }}
</div>
