<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ngos\AdminCore\Models\SavedView;

/**
 * Per-user saved list views for the advanced-filter bar. Every row is scoped to the current user, so a user
 * only ever lists / overwrites / deletes their OWN views (no permission to manage — it's personal state).
 * Wire it with Route::adminCoreSavedViews() inside your admin route group (admin-core:install does this).
 */
class SavedViewController extends Controller
{
    /** The current user's saved views for a resource (?resource=product). */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->scoped()
            ->where('resource', (string) $request->query('resource'))
            ->orderBy('name')
            ->get(['id', 'name', 'filters']));
    }

    /** Save (or overwrite, by name) the current filter values as a named view. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'resource' => ['required', 'string', 'max:191'],
            'name' => ['required', 'string', 'max:191'],
            'filters' => ['nullable', 'array'],
        ]);

        [$userId, $guard] = $this->actor();
        $view = SavedView::query()->updateOrCreate(
            ['user_id' => $userId, 'guard' => $guard, 'resource' => $data['resource'], 'name' => $data['name']],
            ['filters' => $data['filters'] ?? []],
        );

        return response()->json(['id' => $view->id, 'name' => $view->name, 'filters' => $view->filters]);
    }

    /** Delete one of the current user's views (scoped, so a crafted id can't touch another user's). */
    public function destroy(int $id): JsonResponse
    {
        $this->scoped()->whereKey($id)->delete();

        return response()->json(['deleted' => true]);
    }

    /** Saved views belonging to the current user only — scoped by BOTH id and guard (see actor()). */
    private function scoped(): \Illuminate\Database\Eloquent\Builder
    {
        [$userId, $guard] = $this->actor();

        return SavedView::query()->where('user_id', $userId)->where('guard', $guard);
    }

    /**
     * The current user's [id, guard]. Scoping by id ALONE is a cross-portal IDOR: a multi-portal install has
     * independent user tables per guard (App\Models\User id 5 on 'web', App\Models\Merchant id 5 on
     * 'merchant'), so a bare `where('user_id', auth()->id())` lets a merchant read/overwrite/delete an admin's
     * saved view of the same numeric id. Resolve the guard the user is actually authenticated on (default +
     * any configured portal guard) and scope by both. Unauthenticated → 403 (personal state, never guest-shared).
     *
     * @return array{0: int|string, 1: string}
     */
    private function actor(): array
    {
        $guards = array_unique(array_merge(
            [(string) config('auth.defaults.guard', 'web')],
            array_keys((array) config('admin-core.permission.guards', [])),
        ));

        foreach ($guards as $guard) {
            try {
                if (auth()->guard($guard)->check()) {
                    return [auth()->guard($guard)->id(), (string) $guard];
                }
            } catch (\Throwable) {
                continue; // a guard named in admin-core config but not defined in auth.php — skip
            }
        }

        abort(403);
    }
}
