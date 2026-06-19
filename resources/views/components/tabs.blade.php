{{-- Bootstrap content tabs. Give the labels as an id => label map; drop one
     <x-admin-core::tab-pane> per id in the slot and mark the first one active.
     Usage:
       <x-admin-core::tabs :tabs="['profile' => 'Profile', 'security' => 'Security']">
           <x-admin-core::tab-pane id="profile" active>...</x-admin-core::tab-pane>
           <x-admin-core::tab-pane id="security">...</x-admin-core::tab-pane>
       </x-admin-core::tabs>
     Pass :pills="true" for the pill style. (To filter a DataTable column instead of
     switching content, use <x-admin-core::filter-tabs>.) --}}
@props(['tabs' => [], 'pills' => false])
<ul {{ $attributes->merge(['class' => 'nav ' . ($pills ? 'nav-pills' : 'nav-tabs') . ' mb-3']) }} role="tablist">
    @foreach ($tabs as $id => $label)
        <li class="nav-item" role="presentation">
            <button class="nav-link {{ $loop->first ? 'active' : '' }}" id="{{ $id }}-tab" data-bs-toggle="tab"
                    data-bs-target="#{{ $id }}" type="button" role="tab"
                    aria-controls="{{ $id }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">{{ $label }}</button>
        </li>
    @endforeach
</ul>
<div class="tab-content">
    {{ $slot }}
</div>
