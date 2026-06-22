{{-- A key/value detail table for show / detail pages. Put <x-admin-core::detail-row> rows in the slot.
     Usage:
       <x-admin-core::detail-list>
           <x-admin-core::detail-row label="Name">{{ $object->name }}</x-admin-core::detail-row>
           <x-admin-core::detail-row label="Email">{{ $object->email }}</x-admin-core::detail-row>
       </x-admin-core::detail-list>
     Extra attributes merge onto the table (e.g. class="mb-0"). Pair with <x-admin-core::card> for the panel. --}}
<table {{ $attributes->merge(['class' => 'table table-bordered mb-0']) }}>
    <tbody>
    {{ $slot }}
    </tbody>
</table>
