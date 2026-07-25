<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Ngos\AdminCore\Models\Notification;

/**
 * The current user's in-app notifications, backed by the Notification Platform's store ({@see Notification}). Personal
 * — no permission gate; everyone manages their own. Wired by `Route::adminCoreNotifications()` and surfaced by the
 * `<x-admin-core::notifications-bell />` component. Scopes by the recipient's morph identity, so it works for any
 * Notifiable user model / guard.
 *
 * The store carries a `guard` column, but the platform's send() surface cannot express a recipient guard yet, so every
 * row is written with guard=null (see the WP-N2 note). Portal isolation therefore comes from resolving the RIGHT user
 * on the route's guard and scoping by that user's morph — never from filtering the guard column — which preserves the
 * pre-platform behaviour exactly.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->user($request);

        return view('admin-core::notifications.index', [
            'notifications' => $this->forUser($user)->latest()->paginate(20),
            'unreadCount' => Notification::unreadCountFor($user->getMorphClass(), $user->getKey(), null),
        ]);
    }

    /** Mark one read, then follow its `url` (if any) or go back. */
    public function read(Request $request, string $id): RedirectResponse
    {
        $notification = $this->forUser($this->user($request))->where('uuid', $id)->firstOrFail();
        $notification->update(['read_at' => now()]); // a model save → fires saved → invalidates the unread-count cache

        $url = $notification->data['url'] ?? null;
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : false;

        // Follow the stored URL only when it's relative or points at this app — never an arbitrary
        // external/protocol-relative host (defence-in-depth, even though payloads are server-authored).
        return is_string($url) && $url !== '' && ($host === null || $host === $request->getHost())
            ? redirect()->to($url)
            : redirect()->back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        // Update through model instances (not a mass query update) so each `saved` event fires and the WP-N2A
        // unread-count cache is invalidated — a `->update()` on the query builder would bypass the model events and
        // leave the bell's cached badge stale. Unread counts are small, so per-row is fine for this deliberate action.
        $this->forUser($this->user($request))->whereNull('read_at')->get()
            ->each(fn (Notification $n) => $n->update(['read_at' => now()]));

        return redirect()->back()->with('success', 'All notifications marked as read.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        // Delete the model instance (not a mass query delete) so the `deleted` event fires and invalidates the cache.
        $this->forUser($this->user($request))->where('uuid', $id)->first()?->delete();

        return redirect()->back();
    }

    /**
     * The authenticated user on the route's portal guard when set (Route::adminCoreNotifications('merchant')), else
     * the default guard — auth()->guard(null)->user() equals $request->user(), so the default case is unchanged, but
     * a portal inbox scopes to its own guard's user instead of a null/wrong default-guard identity.
     */
    private function user(Request $request): Model
    {
        $guard = $request->route()?->defaults['acNotificationGuard'] ?? null;
        $user = auth()->guard($guard)->user();
        if (! $user instanceof Model) {
            abort(403);
        }

        return $user;
    }

    /**
     * A query of the given user's notifications, scoped by its morph identity.
     *
     * @return Builder<Notification>
     */
    private function forUser(Model $user): Builder
    {
        return Notification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey());
    }
}
