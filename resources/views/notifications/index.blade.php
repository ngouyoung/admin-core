@extends('backend.layouts.app')

@section('title', 'Notifications')

@section('contents')
    <x-admin-core::page-header title="Notifications" description="Your recent alerts and updates.">
        <x-slot:actions>
            @if ($unreadCount)
                <form action="{{ route('admin.notifications.readAll') }}" method="POST">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-check2-all me-1"></i>Mark all read</button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin-core::page-header>

    <div class="card border-0 shadow-sm">
        <div class="list-group list-group-flush">
            @forelse ($notifications as $n)
                <div class="list-group-item d-flex gap-3 align-items-start {{ $n->read_at ? '' : 'bg-body-secondary' }}">
                    <i class="bi {{ $n->data['icon'] ?? 'bi-info-circle' }} fs-5 text-primary mt-1"></i>
                    <div class="flex-fill">
                        <div class="fw-semibold">{{ $n->data['title'] ?? 'Notification' }}</div>
                        @if (! empty($n->data['message']))
                            <div class="text-muted">{{ $n->data['message'] }}</div>
                        @endif
                        <div class="text-muted small">{{ $n->created_at->diffForHumans() }}</div>
                    </div>
                    <div class="d-flex gap-2">
                        @if (! $n->read_at)
                            <form action="{{ route('admin.notifications.read', $n->id) }}" method="POST">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary" title="Mark read / open"><i class="bi bi-check2"></i></button>
                            </form>
                        @endif
                        <form action="{{ route('admin.notifications.destroy', $n->id) }}" method="POST">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="list-group-item text-center text-muted py-5">No notifications yet.</div>
            @endforelse
        </div>
    </div>

    <div class="mt-3">{{ $notifications->links('pagination::bootstrap-5') }}</div>
@endsection
