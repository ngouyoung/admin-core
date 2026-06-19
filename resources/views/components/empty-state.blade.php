{{-- A centered "nothing here yet" placeholder for empty lists, sections or panels.
     Usage:
       <x-admin-core::empty-state icon="bi-inbox" title="No products yet"
            message="Create your first product to get started.">
           <x-slot:action>
               <a href="..." class="btn btn-primary btn-sm">Add product</a>
           </x-slot:action>
       </x-admin-core::empty-state>
     `icon` is a Bootstrap-Icon name (or :icon="false" to drop it). The default slot
     and the optional `action` slot render below the message. --}}
@props(['icon' => 'bi-inbox', 'title' => null, 'message' => null])
<div {{ $attributes->merge(['class' => 'ac-empty']) }}>
    @if ($icon)<i class="bi {{ $icon }} ac-empty-icon"></i>@endif
    @if ($title)<p class="ac-empty-title">{{ $title }}</p>@endif
    @if ($message)<p class="ac-empty-message">{{ $message }}</p>@endif
    {{ $slot }}
    @isset($action)<div class="ac-empty-action">{{ $action }}</div>@endisset
</div>
