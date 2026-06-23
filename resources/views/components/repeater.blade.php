{{-- Repeatable form rows for a has-many / master-detail form (e.g. a variant's units, an order's lines).
     You supply a ROW partial that renders one row's inputs named with the :index it's given — e.g.
     name="{{ $name }}[{{ $index }}][unit_id]" — and wraps the row in [data-ac-repeater-row] with a
     [data-ac-repeater-remove] button. The repeater renders that partial once per existing row, plus a
     hidden <template> (index "__ROW__") that the Add button clones with a fresh unique index.

       <x-admin-core::repeater name="units" :rows="old('units', $variant->units->toArray())"
           row="backend.pages.products.partials.unit-row" add-label="Add unit" />

     Posts name[0][...], name[<uid>][...], … (indexes need not be sequential — the controller re-indexes).
     No build step: the add/remove JS is inline. --}}
@props(['name', 'rows' => [], 'row', 'addLabel' => 'Add row'])
<div class="ac-repeater" data-ac-repeater>
    <div data-ac-repeater-rows>
        @foreach ($rows as $index => $rowData)
            @include($row, ['name' => $name, 'index' => $index, 'row' => (array) $rowData])
        @endforeach
    </div>
    <template data-ac-repeater-tpl>
        @include($row, ['name' => $name, 'index' => '__ROW__', 'row' => []])
    </template>
    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" data-ac-repeater-add>
        <i class="bi bi-plus-lg"></i> {{ $addLabel }}
    </button>
</div>
@once
    @push('scripts')
        <script>
            document.addEventListener('click', function (e) {
                var add = e.target.closest('[data-ac-repeater-add]');
                if (add) {
                    var wrap = add.closest('[data-ac-repeater]');
                    // a unique, collision-proof index for the new row's input names
                    var uid = 'n' + Date.now().toString(36) + Math.floor(Math.random() * 1e6).toString(36);
                    var html = wrap.querySelector('[data-ac-repeater-tpl]').innerHTML.replace(/__ROW__/g, uid);
                    var holder = document.createElement('div');
                    holder.innerHTML = html.trim();
                    var rows = wrap.querySelector('[data-ac-repeater-rows]');
                    while (holder.firstElementChild) {
                        var node = holder.firstElementChild;
                        rows.appendChild(node);
                        // Let field enhancers (select2, datepicker, CKEditor) initialise the new row.
                        node.dispatchEvent(new CustomEvent('ac:repeater:added', { bubbles: true, detail: { repeater: wrap } }));
                    }
                    return;
                }
                var rem = e.target.closest('[data-ac-repeater-remove]');
                if (rem) {
                    e.preventDefault();
                    var rowEl = rem.closest('[data-ac-repeater-row]');
                    if (rowEl) rowEl.remove();
                }
            });
        </script>
    @endpush
@endonce
