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
@props(['table', 'filters' => [], 'resource' => null, 'views' => []])
@php
    // The per-user "Views" dropdown only appears when the saved-views routes are wired (Route::adminCoreSavedViews()).
    $svBase = config('admin-core.route.name_prefix') . 'saved-views.';
    $savedViews = $resource && \Illuminate\Support\Facades\Route::has($svBase . 'index');
@endphp
@if (! empty($filters))
    <div class="ac-list-filters card card-body py-2 px-3 mb-3" data-ac-filters="{{ $table }}">
        <div class="d-flex flex-wrap align-items-end gap-3">
            @foreach ($filters as $filter)
                @php
                    $column = $filter['column'];
                    $type = $filter['type'] ?? 'select';
                    $label = $filter['label'] ?? \Illuminate\Support\Str::headline($column);
                    // options may be a closure (a foreign filter defers its DB query to render time, not getData).
                    $options = $filter['options'] ?? [];
                    if ($options instanceof \Closure) { $options = $options(); }
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
                    @elseif ($type === 'number')
                        <div class="d-flex align-items-center gap-1">
                            <input type="number" inputmode="decimal" step="any" class="form-control form-control-sm" style="width:6rem"
                                id="ac-filter-{{ $table }}-{{ $column }}"
                                data-ac-filter="{{ $column }}" data-ac-filter-part="min" placeholder="min" aria-label="{{ $label }} min">
                            <span class="text-muted small">–</span>
                            <input type="number" inputmode="decimal" step="any" class="form-control form-control-sm" style="width:6rem"
                                data-ac-filter="{{ $column }}" data-ac-filter-part="max" placeholder="max" aria-label="{{ $label }} max">
                        </div>
                    @elseif ($type === 'text')
                        <input type="text" class="form-control form-control-sm" style="min-width:9rem"
                            id="ac-filter-{{ $table }}-{{ $column }}" data-ac-filter="{{ $column }}"
                            placeholder="{{ $filter['placeholder'] ?? '' }}">
                    @else
                        <select class="form-select form-select-sm" style="min-width:9rem"
                            id="ac-filter-{{ $table }}-{{ $column }}" data-ac-filter="{{ $column }}">
                            <option value="">{{ __('admin-core::admin-core.filters.all') }}</option>
                            @foreach ($options as $value => $text)
                                <option value="{{ $value }}">{{ $text }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
            @endforeach
            <button type="button" class="btn btn-sm btn-link text-muted px-1" data-ac-filter-clear>
                {{ __('admin-core::admin-core.filters.clear') }}
            </button>

            @if ($savedViews)
                <div class="dropdown ac-saved-views ms-auto" data-ac-saved-views="{{ $table }}"
                    data-ac-resource="{{ $resource }}" data-ac-store-url="{{ route($svBase . 'store') }}"
                    data-ac-save-prompt="{{ __('admin-core::admin-core.filters.save_prompt') }}"
                    data-ac-error="{{ __('admin-core::admin-core.filters.view_error') }}">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        {{ __('admin-core::admin-core.filters.views') }}
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        @forelse ($views as $view)
                            <li class="d-flex align-items-center justify-content-between">
                                <button type="button" class="dropdown-item" data-ac-view-apply
                                    data-ac-view-filters="{{ json_encode($view['filters'] ?? [], JSON_UNESCAPED_SLASHES) }}">{{ $view['name'] }}</button>
                                <button type="button" class="btn btn-sm btn-link text-danger py-0 px-2" data-ac-view-delete
                                    data-ac-view-url="{{ route($svBase . 'destroy', $view['id']) }}" aria-label="Delete {{ $view['name'] }}">&times;</button>
                            </li>
                        @empty
                            <li><span class="dropdown-item-text text-muted small">{{ __('admin-core::admin-core.filters.no_views') }}</span></li>
                        @endforelse
                        <li><hr class="dropdown-divider"></li>
                        <li><button type="button" class="dropdown-item" data-ac-view-save>{{ __('admin-core::admin-core.filters.save_view') }}</button></li>
                    </ul>
                </div>
            @endif
        </div>
    </div>
@endif
