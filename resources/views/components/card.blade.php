{{-- A Bootstrap card with optional header/footer slots. Usage:
       <x-admin-core::card>
           <x-slot:header>Overview</x-slot>
           ...body content...
           <x-slot:footer>...</x-slot>
       </x-admin-core::card>
     Attributes merge onto the card (e.g. class="h-100"). The body is wrapped in
     `card-body` by default; pass :body-class="'card-body p-0'" for a flush table,
     or :body-class="''" to drop the wrapper entirely. --}}
@props(['bodyClass' => 'card-body'])
<div {{ $attributes->merge(['class' => 'card']) }}>
    @isset($header)<div class="card-header">{{ $header }}</div>@endisset
    @if ($bodyClass !== '')
        <div class="{{ $bodyClass }}">{{ $slot }}</div>
    @else
        {{ $slot }}
    @endif
    @isset($footer)<div class="card-footer">{{ $footer }}</div>@endisset
</div>
