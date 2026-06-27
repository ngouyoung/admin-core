{{-- The admin-core dashboard: renders config('admin-core.dashboard.widgets'), permission-filtered, in each
     user's saved arrangement, with a date-range toolbar every widget respects and an optional Customize mode
     (drag to reorder + hide widgets, saved per user). Drop it into your dashboard view:
       @section('contents') <x-admin-core::dashboard /> @endsection
     Widgets and their data are entirely your app's — this only lays them out. --}}
@php
    $acDashboard = app(\Ngos\AdminCore\Dashboard\Dashboard::class);
    $acContext = \Ngos\AdminCore\Dashboard\DashboardContext::fromRequest(request());
    $acWidgets = $acDashboard->arranged();
    $acPresets = \Ngos\AdminCore\Dashboard\DashboardContext::presets();

    $acPrefix = config('admin-core.route.name_prefix');
    $acHasEndpoint = \Illuminate\Support\Facades\Route::has($acPrefix . 'dashboard.widget');
    $acCanCustomize = config('admin-core.dashboard.customizable', true)
        && auth()->check()
        && \Illuminate\Support\Facades\Route::has($acPrefix . 'dashboard.layout');
    $acLayoutUrl = $acCanCustomize ? route($acPrefix . 'dashboard.layout') : null;
@endphp

@if ($acWidgets->isNotEmpty() && (config('admin-core.dashboard.date_filter', true) || $acCanCustomize))
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            @if ($acCanCustomize)
                <button type="button" class="btn btn-sm btn-outline-secondary" data-ac-customize>
                    <i class="bi bi-grid-1x2-fill"></i> {{ __('Customize') }}
                </button>
                <span class="d-none" data-ac-customize-actions>
                    <button type="button" class="btn btn-sm btn-success" data-ac-customize-save>{{ __('Save layout') }}</button>
                    <button type="button" class="btn btn-sm btn-link text-muted" data-ac-customize-cancel>{{ __('Cancel') }}</button>
                </span>
            @endif
        </div>
        @if (config('admin-core.dashboard.date_filter', true))
            <div class="btn-group btn-group-sm" role="group" aria-label="{{ __('Date range') }}">
                @foreach ($acPresets as $acValue => $acLabel)
                    <a href="{{ request()->fullUrlWithQuery(['range' => $acValue, 'from' => null, 'to' => null]) }}"
                        class="btn btn-outline-secondary {{ $acContext->range === $acValue ? 'active' : '' }}">{{ __($acLabel) }}</a>
                @endforeach
            </div>
        @endif
    </div>
@endif

@once
    @if ($acCanCustomize)
        <style>
            .ac-widget-tools { position: absolute; top: .35rem; right: .6rem; z-index: 5; display: flex; gap: .35rem; align-items: center; }
            .ac-widget-handle { cursor: grab; color: var(--bs-secondary-color); }
            .ac-customizing [data-ac-widget] { outline: 1px dashed var(--bs-border-color); border-radius: .5rem; }
            .ac-dragging { opacity: .5; }
        </style>
    @endif
@endonce

<div class="row g-3" data-ac-dashboard @if ($acLayoutUrl) data-ac-layout-url="{{ $acLayoutUrl }}" @endif>
    @forelse ($acWidgets as $acWidget)
        @php
            $acSpan = max(1, min(12, $acWidget->colSpan()));
            $acUrl = $acHasEndpoint ? route($acPrefix . 'dashboard.widget', $acWidget->key()) . '?range=' . $acContext->range : null;
            $acLazy = $acWidget->lazy() && $acUrl;
            $acRefresh = $acWidget->refreshSeconds() > 0 && $acUrl ? $acWidget->refreshSeconds() : null;
        @endphp
        <div class="col-12 col-md-6 col-xl-{{ $acSpan }} position-relative" data-ac-widget="{{ $acWidget->key() }}"
            @if ($acRefresh) data-ac-refresh="{{ $acRefresh }}" data-ac-widget-url="{{ $acUrl }}" @endif>
            @if ($acCanCustomize)
                <div class="ac-widget-tools d-none">
                    <span class="ac-widget-handle" title="{{ __('Drag to reorder') }}"><i class="bi bi-grip-vertical"></i></span>
                    <button type="button" class="ac-widget-hide btn-close" title="{{ __('Hide') }}" aria-label="{{ __('Hide widget') }}"></button>
                </div>
            @endif
            <div data-ac-widget-body>
                @if ($acLazy)
                    <div data-ac-widget-lazy data-ac-widget-url="{{ $acUrl }}">
                        <div class="card h-100 placeholder-glow" aria-busy="true">
                            <div class="card-body">
                                <span class="placeholder col-6 mb-2 d-block"></span>
                                <span class="placeholder col-12 mb-2 d-block"></span>
                                <span class="placeholder col-8 d-block"></span>
                            </div>
                        </div>
                    </div>
                @else
                    @include($acWidget->partial(), ['widget' => $acWidget, 'data' => $acDashboard->payload($acWidget, $acContext)])
                @endif
            </div>
        </div>
    @empty
        <div class="col-12">
            <x-admin-core::card>
                <p class="text-muted mb-0">
                    {{ __('No dashboard widgets yet.') }}
                    {!! __('Declare some in <code>config(\'admin-core.dashboard.widgets\')</code>.') !!}
                </p>
            </x-admin-core::card>
        </div>
    @endforelse
</div>
