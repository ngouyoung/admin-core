{{-- A dashboard KPI card: a big number with a label and an icon, optionally a link.
     Usage:
       <x-admin-core::stat-card label="Users" :count="$count" icon="bi-people"
            :route="route('admin.assessments.users.index')" tone="1" />
     `tone` (1-4) picks the icon accent colour; omit `route` for a non-clickable card.
     `icon` is a Bootstrap-Icons name (e.g. bi-people). Wrap it in your own grid
     column (e.g. <div class="col-xl-3 col-md-6">) on the dashboard. --}}
@props(['label', 'count', 'icon' => null, 'route' => null, 'tone' => '1'])
@if ($route)
    <a href="{{ $route }}" class="d-block text-decoration-none">
@endif
        <div {{ $attributes->merge(['class' => 'ac-stat ac-stat-' . $tone . ' d-flex align-items-center justify-content-between']) }}>
            <div>
                <div class="ac-stat-value">{{ $count }}</div>
                <div class="ac-stat-label">{{ $label }}</div>
            </div>
            @if ($icon)<i class="bi {{ $icon }} ac-stat-icon"></i>@endif
        </div>
@if ($route)
    </a>
@endif
