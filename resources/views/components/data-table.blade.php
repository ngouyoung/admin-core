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
     passed. `tableClass` overrides the default `config('class.table')`.

     Pass `:columns` (+ `:ajax`, `:bulk-delete`, `:order`) to drive the table from the
     shared datatable.js module — no per-resource init script needed:
       <x-admin-core::data-table id="products_table" thead="…"
           :ajax="route('admin.products.getData')"
           :bulk-delete="…can('delete-product') ? route('admin.products.bulkDelete') : null"
           :columns="[['type'=>'check','data'=>'uuid'], ['data'=>'name','name'=>'name'],
                      ['data'=>'actions','orderable'=>false,'searchable'=>false]]" />
     Without `:columns` it renders exactly as before (you init the DataTable yourself). --}}
@props(['id', 'thead', 'tableClass' => null, 'ajax' => null, 'columns' => null, 'bulkDelete' => null, 'order' => null])
@php
    // With :columns, emit a config the shared datatable.js reads (init + select-all + bulk/single
    // delete). i18n is resolved here so the confirm/toast strings are locale-aware and ship with the
    // package. The data still loads server-side from :ajax (getData) — this is only column metadata.
    $acConfig = null;
    if (! empty($columns)) {
        $acConfig = [
            'ajax' => $ajax,
            'pageLength' => (int) config('admin-core.pagination', 10),
            'columns' => $columns,
            'i18n' => [
                'confirmDelete' => __('admin-core::admin-core.confirm.title'),
                'recoverOne' => __('admin-core::admin-core.confirm.recover_one'),
                'yesDelete' => __('admin-core::admin-core.confirm.yes_delete'),
                'noKeep' => __('admin-core::admin-core.confirm.no_keep'),
                'deleted' => __('admin-core::admin-core.toast.deleted'),
                'confirmDeleteMany' => __('admin-core::admin-core.confirm.delete_count'),
                'recoverMany' => __('admin-core::admin-core.confirm.recover_many'),
                'yesDeleteMany' => __('admin-core::admin-core.confirm.yes_delete_many'),
                'cancel' => __('admin-core::admin-core.actions.cancel'),
                'deletedMany' => __('admin-core::admin-core.toast.deleted_count'),
            ],
        ];
        if ($order !== null) {
            $acConfig['order'] = $order;
        }
        if ($bulkDelete !== null) {
            $acConfig['bulk'] = ['url' => $bulkDelete];
        }
    }
@endphp
<div {{ $attributes->merge(['class' => 'card']) }}>
    @isset($toolbar)
        <div class="card-header d-flex flex-wrap gap-1 align-items-center">
            {{ $toolbar }}
        </div>
    @endisset
    <div class="card-body">
        <table class="{{ $tableClass ?? config('class.table') }}" id="{{ $id }}" @if ($acConfig) data-ac-datatable="{{ json_encode($acConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" @endif>
            <thead>
            @include($thead)
            </thead>
        </table>
        {{ $slot }}
    </div>
</div>
