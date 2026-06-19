{{-- The list-page shell: a card holding a toolbar, the <table> a DataTable binds
     to, and an optional area under it. Usage:
       <x-admin-core::data-table id="products_table" thead="backend.pages.products.partials.thead">
           <x-slot:toolbar>
               ...export-menu, import-modal, bulk-delete button...
           </x-slot:toolbar>
           ...default slot: anything rendered under the table, e.g. a sort panel...
       </x-admin-core::data-table>
     `id` is the table's DOM id (point your DataTables init at it); `thead` is the
     view rendering the header row(s). The toolbar slot is omitted entirely when not
     passed. `tableClass` overrides the default `config('class.table')`. --}}
@props(['id', 'thead', 'tableClass' => null])
<div {{ $attributes->merge(['class' => 'card']) }}>
    @isset($toolbar)
        <div class="card-header d-flex flex-wrap gap-1 align-items-center">
            {{ $toolbar }}
        </div>
    @endisset
    <div class="card-body">
        <table class="{{ $tableClass ?? config('class.table') }}" id="{{ $id }}">
            <thead>
            @include($thead)
            </thead>
        </table>
        {{ $slot }}
    </div>
</div>
