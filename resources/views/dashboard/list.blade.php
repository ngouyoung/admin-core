{{-- ListWidget partial: a compact list of recent / top rows. Each row: label, meta?, link?, badge?. --}}
<div class="card h-100">
    <div class="card-body">
        <h6 class="card-title mb-3">{{ $data['title'] }}</h6>
        @forelse ($data['rows'] as $acRow)
            <div class="d-flex justify-content-between align-items-center gap-2 py-2 {{ ! $loop->last ? 'border-bottom' : '' }}">
                <div class="text-truncate">
                    @if (! empty($acRow['link']))
                        <a href="{{ $acRow['link'] }}" class="text-decoration-none">{{ $acRow['label'] }}</a>
                    @else
                        {{ $acRow['label'] }}
                    @endif
                    @if (! empty($acRow['meta']))<div class="small text-muted text-truncate">{{ $acRow['meta'] }}</div>@endif
                </div>
                @if (! empty($acRow['badge']))<span class="badge bg-light text-dark flex-shrink-0">{{ $acRow['badge'] }}</span>@endif
            </div>
        @empty
            <p class="text-muted small mb-0">{{ $data['empty'] }}</p>
        @endforelse
    </div>
</div>
