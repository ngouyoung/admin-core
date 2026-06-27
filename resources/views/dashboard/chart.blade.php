{{-- ChartWidget partial: an ApexCharts container. dashboard.js renders it from data-ac-chart-config once
     the (lazy-loaded) ApexCharts bundle is ready. Payload: title, key, chart{type,series,categories,...}. --}}
<div class="card h-100">
    <div class="card-body">
        <h6 class="card-title mb-3">{{ $data['title'] }}</h6>
        <div data-apexchart data-ac-chart="{{ $data['key'] }}"
            data-ac-chart-config='@json($data['chart'])'></div>
    </div>
</div>
