{{-- An advanced-filter bar above a server-side DataTable. Each control carries data-ac-filter="<column>" (and
     data-ac-filter-part="from|to" for a date range); the shared datatable.js reads them, appends
     filter[col]=… to every AJAX request, and reloads the table on change. The server applies them in
     WebController::applyListFilters() — only for the columns listed in the controller's listFilters().

     Usage (the generator wires this from the resource's fields):
       <x-admin-core::list-filters table="products_table" :filters="$acFilters ?? []" />

     Each filter descriptor:
       ['column' => 'status',     'type' => 'select', 'label' => 'Status', 'options' => ['active' => 'Active', …]]
       ['column' => 'is_active',  'type' => 'select', 'label' => 'Active', 'options' => [1 => 'Yes', 0 => 'No']]
       ['column' => 'created_at', 'type' => 'date',   'label' => 'Created'] --}}
@props(['table', 'filters' => []])
@if (! empty($filters))
    <div class="ac-list-filters card card-body py-2 px-3 mb-3" data-ac-filters="{{ $table }}">
        <div class="d-flex flex-wrap align-items-end gap-3">
            @foreach ($filters as $filter)
                @php
                    $column = $filter['column'];
                    $type = $filter['type'] ?? 'select';
                    $label = $filter['label'] ?? \Illuminate\Support\Str::headline($column);
                @endphp
                <div class="ac-list-filter">
                    <label class="form-label small text-muted mb-1 d-block" for="ac-filter-{{ $table }}-{{ $column }}">{{ $label }}</label>
                    @if ($type === 'date')
                        <div class="d-flex align-items-center gap-1">
                            <input type="date" class="form-control form-control-sm" style="width:auto"
                                id="ac-filter-{{ $table }}-{{ $column }}"
                                data-ac-filter="{{ $column }}" data-ac-filter-part="from" aria-label="{{ $label }} from">
                            <span class="text-muted small">–</span>
                            <input type="date" class="form-control form-control-sm" style="width:auto"
                                data-ac-filter="{{ $column }}" data-ac-filter-part="to" aria-label="{{ $label }} to">
                        </div>
                    @else
                        <select class="form-select form-select-sm" style="min-width:9rem"
                            id="ac-filter-{{ $table }}-{{ $column }}" data-ac-filter="{{ $column }}">
                            <option value="">{{ __('admin-core::admin-core.filters.all') }}</option>
                            @foreach (($filter['options'] ?? []) as $value => $text)
                                <option value="{{ $value }}">{{ $text }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
            @endforeach
            <button type="button" class="btn btn-sm btn-link text-muted px-1" data-ac-filter-clear>
                {{ __('admin-core::admin-core.filters.clear') }}
            </button>
        </div>
    </div>
@endif
