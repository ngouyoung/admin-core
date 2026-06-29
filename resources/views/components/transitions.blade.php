{{-- State-machine buttons for a document's show page. Each item is a transition the current user may run
     from the record's current state (already permission- + state-filtered by the controller's
     transitionsFor()). Each posts to the transition endpoint; a `->confirm()` transition asks first.
     A no-op when there are no available transitions. --}}
@props(['items' => []])
@if (! empty($items))
    <div class="ac-transitions d-flex flex-wrap gap-2 mb-3">
        @foreach ($items as $t)
            <form action="{{ $t['url'] }}" method="POST" class="ac-transition-form d-inline"
                  data-confirm="{{ $t['confirm'] ?? '' }}">
                @csrf
                <button type="submit" class="btn btn-{{ $t['color'] ?? 'secondary' }}">
                    @if (! empty($t['icon']))<i class="{{ $t['icon'] }}"></i> @endif{{ $t['label'] }}
                </button>
            </form>
        @endforeach
    </div>
    @once
        @push('scripts')
            <script>
                // Confirm a transition that asked for one (SweetAlert if present, else native confirm).
                document.addEventListener('submit', function (e) {
                    const form = e.target.closest('.ac-transition-form');
                    if (!form) return;
                    const msg = form.getAttribute('data-confirm');
                    if (!msg || form.dataset.acConfirmed === '1') return;
                    e.preventDefault();
                    if (window.Swal) {
                        window.Swal.fire({ title: msg, icon: 'question', showCancelButton: true })
                            .then((r) => { if (r.value) { form.dataset.acConfirmed = '1'; form.submit(); } });
                    } else if (window.confirm(msg)) {
                        form.dataset.acConfirmed = '1';
                        form.submit();
                    }
                });
            </script>
        @endpush
    @endonce
@endif
