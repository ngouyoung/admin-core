{{--
     In-app notification bell for the top bar. Reads the current user's Laravel database
     notifications. Renders only when the routes are wired (Route::adminCoreNotifications())
     and the user is Notifiable — so it's safe to drop in any layout.

       <x-admin-core::notifications-bell />

     Send one in a line:  $user->notify(new Ngos\AdminCore\Notifications\AdminNotification(
         title: 'Order shipped', message: '…', url: route(…), icon: 'bi-truck'));
--}}
@php($acUser = auth()->user())
@if ($acUser
    && \Illuminate\Support\Facades\Route::has('admin.notifications.index')
    && method_exists($acUser, 'unreadNotifications'))
    @php($acUnread = $acUser->unreadNotifications)
    <div class="dropdown" data-ac-bell>
        <a href="#" class="ac-icon-btn position-relative" data-bs-toggle="dropdown" role="button" title="{{ __('admin-core::admin-core.notifications.title') }}">
            <i class="bi bi-bell"></i>
            @if ($acUnread->count())
                <span class="badge rounded-pill text-bg-danger position-absolute top-0 start-100 translate-middle"
                      style="font-size:.6rem" data-ac-bell-count data-count="{{ $acUnread->count() }}">{{ $acUnread->count() > 9 ? '9+' : $acUnread->count() }}</span>
            @endif
        </a>
        <div class="dropdown-menu dropdown-menu-end shadow border-0 mt-2 p-0"
             style="border-radius:1rem; min-width:320px; max-width:360px">
            <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
                <h6 class="mb-0">{{ __('admin-core::admin-core.notifications.title') }}</h6>
                @if ($acUnread->count())
                    <form action="{{ route('admin.notifications.readAll') }}" method="POST" class="m-0">
                        @csrf
                        <button class="btn btn-link btn-sm p-0 text-decoration-none">{{ __('admin-core::admin-core.notifications.mark_all_read') }}</button>
                    </form>
                @endif
            </div>
            <div style="max-height:360px; overflow-y:auto">
                @forelse ($acUnread->take(6) as $n)
                    <form action="{{ route('admin.notifications.read', $n->id) }}" method="POST" class="m-0">
                        @csrf
                        <button type="submit" class="dropdown-item d-flex gap-2 py-2 text-wrap border-bottom">
                            <i class="bi {{ $n->data['icon'] ?? 'bi-info-circle' }} mt-1 text-primary"></i>
                            <span>
                                <span class="d-block fw-semibold small">{{ $n->data['title'] ?? 'Notification' }}</span>
                                <span class="d-block text-muted small">{{ \Illuminate\Support\Str::limit($n->data['message'] ?? '', 80) }}</span>
                                <span class="d-block text-muted" style="font-size:.7rem">{{ $n->created_at->diffForHumans() }}</span>
                            </span>
                        </button>
                    </form>
                @empty
                    <div class="px-3 py-4 text-center text-muted small">{{ __('admin-core::admin-core.notifications.empty') }}</div>
                @endforelse
            </div>
            <a href="{{ route('admin.notifications.index') }}" class="dropdown-item text-center py-2 small">{{ __('admin-core::admin-core.notifications.see_all') }}</a>
        </div>
    </div>
@endif
