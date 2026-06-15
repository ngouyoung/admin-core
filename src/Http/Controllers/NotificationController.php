<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * The current user's in-app notifications (Laravel database notifications). Personal —
 * no permission gate; everyone manages their own. Wired by `Route::adminCoreNotifications()`
 * and surfaced by the `<x-admin-core::notifications-bell />` component. Queries the
 * notifications table directly so it works for any Notifiable user model / guard.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin-core::notifications.index', [
            'notifications' => $this->forUser($request)->latest()->paginate(20),
            'unreadCount' => $this->forUser($request)->whereNull('read_at')->count(),
        ]);
    }

    /** Mark one read, then follow its `url` (if any) or go back. */
    public function read(Request $request, string $id): RedirectResponse
    {
        $notification = $this->forUser($request)->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        $url = $notification->data['url'] ?? null;

        return is_string($url) ? redirect()->to($url) : redirect()->back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->forUser($request)->whereNull('read_at')->update(['read_at' => now()]);

        return redirect()->back()->with('success', 'All notifications marked as read.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $this->forUser($request)->whereKey($id)->delete();

        return redirect()->back();
    }

    /**
     * A query of the authenticated user's notifications, scoped by its morph identity.
     *
     * @return Builder<DatabaseNotification>
     */
    private function forUser(Request $request): Builder
    {
        $user = $request->user();
        if (! $user instanceof Model) {
            abort(403);
        }

        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey());
    }
}
