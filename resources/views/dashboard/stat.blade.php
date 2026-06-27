{{-- StatWidget partial: a KPI value, an optional trend arrow vs the previous period, and an optional
     drill-down link. Payload: title, value, icon, link, tone (1-4), trend{delta,pct,dir}. --}}
@php($acTrend = $data['trend'] ?? null)
@if (! empty($data['link']))
    <a href="{{ $data['link'] }}" class="d-block text-decoration-none">
@endif
        <div class="ac-stat ac-stat-{{ $data['tone'] ?? '1' }} h-100 d-flex align-items-center justify-content-between">
            <div>
                <div class="ac-stat-value">{{ $data['value'] }}</div>
                <div class="ac-stat-label">{{ $data['title'] }}</div>
                @if ($acTrend)
                    @php($acDir = $acTrend['dir'] ?? 'flat')
                    <div class="ac-stat-trend small mt-1 text-{{ $acDir === 'up' ? 'success' : ($acDir === 'down' ? 'danger' : 'muted') }}">
                        <i class="bi bi-arrow-{{ $acDir === 'up' ? 'up-right' : ($acDir === 'down' ? 'down-right' : 'right') }}"></i>
                        {{ number_format(abs($acTrend['pct'] ?? 0), 1) }}%
                        <span class="text-muted">{{ __('vs prev.') }}</span>
                    </div>
                @endif
            </div>
            @if (! empty($data['icon']))<i class="bi {{ $data['icon'] }} ac-stat-icon"></i>@endif
        </div>
@if (! empty($data['link']))
    </a>
@endif
