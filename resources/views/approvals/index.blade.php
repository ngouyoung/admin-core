@extends('backend.layouts.app')

@section('title', __('admin-core::admin-core.approvals.title'))

@section('contents')
    @php($acNs = config('admin-core.route.name_prefix', 'admin.'))
    <x-admin-core::page-header :title="__('admin-core::admin-core.approvals.title')"
        :description="__('admin-core::admin-core.approvals.description')" />

    <x-admin-core::card :body-class="''" class="border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('admin-core::admin-core.approvals.action') }}</th>
                        <th>{{ __('admin-core::admin-core.approvals.requester') }}</th>
                        <th class="text-center">{{ __('admin-core::admin-core.approvals.records') }}</th>
                        <th>{{ __('admin-core::admin-core.approvals.requested') }}</th>
                        <th>{{ __('admin-core::admin-core.approvals.note') }}</th>
                        <th class="text-end"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($approvals as $a)
                        <tr>
                            <td class="fw-semibold">{{ $a->label() }}</td>
                            <td>{{ optional($a->requester)->name ?? optional($a->requester)->email ?? '—' }}</td>
                            <td class="text-center">{{ count($a->ids()) }}</td>
                            <td><span class="text-muted small">{{ $a->created_at?->diffForHumans() }}</span></td>
                            <td><span class="text-muted">{{ $a->note }}</span></td>
                            <td class="text-end">
                                @if ($a->can_decide)
                                    <form action="{{ route($acNs . 'approvals.approve', $a->getRouteKey()) }}" method="POST" class="d-inline">
                                        @csrf
                                        <x-admin-core::button type="submit" variant="success" size="sm" icon="bi bi-check-lg">
                                            {{ __('admin-core::admin-core.approvals.approve') }}
                                        </x-admin-core::button>
                                    </form>
                                    <x-admin-core::button variant="danger" outline size="sm" icon="bi bi-x-lg"
                                        class="js-ac-reject" data-bs-toggle="modal" data-bs-target="#acRejectModal"
                                        data-url="{{ route($acNs . 'approvals.reject', $a->getRouteKey()) }}"
                                        data-label="{{ $a->label() }}">
                                        {{ __('admin-core::admin-core.approvals.reject') }}
                                    </x-admin-core::button>
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-0">
                                <x-admin-core::empty-state icon="bi-check2-square"
                                    :title="__('admin-core::admin-core.approvals.empty')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-admin-core::card>

    <div class="mt-3">{{ $approvals->links('pagination::bootstrap-5') }}</div>

    {{-- Shared reject modal: the trigger button fills its form action + the request label via data-* attrs. --}}
    <x-admin-core::modal id="acRejectModal" :title="__('admin-core::admin-core.approvals.reject')">
        <form id="acRejectForm" method="POST">
            @csrf
            <p class="mb-2"><span id="acRejectLabel" class="fw-semibold"></span></p>
            <div class="mb-1">
                <label class="form-label" for="acRejectNote">{{ __('admin-core::admin-core.approvals.reject_reason') }}</label>
                <textarea class="form-control" id="acRejectNote" name="note" rows="3"></textarea>
            </div>
        </form>
        <x-slot:footer>
            <x-admin-core::button variant="secondary" outline data-bs-dismiss="modal">
                {{ __('admin-core::admin-core.actions.cancel') }}
            </x-admin-core::button>
            <x-admin-core::button variant="danger" icon="bi bi-x-lg"
                onclick="document.getElementById('acRejectForm').submit()">
                {{ __('admin-core::admin-core.approvals.confirm_reject') }}
            </x-admin-core::button>
        </x-slot:footer>
    </x-admin-core::modal>

    @push('scripts')
        <script>
            document.addEventListener('click', function (e) {
                const btn = e.target.closest('.js-ac-reject');
                if (!btn) return;
                document.getElementById('acRejectForm').setAttribute('action', btn.getAttribute('data-url'));
                document.getElementById('acRejectLabel').textContent = btn.getAttribute('data-label') || '';
                document.getElementById('acRejectNote').value = '';
            });
        </script>
    @endpush
@endsection
