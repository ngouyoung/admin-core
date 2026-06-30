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

        $view = SavedView::query()->updateOrCreate(
            ['user_id' => $this->userId(), 'resource' => $data['resource'], 'name' => $data['name']],
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

    /** Saved views belonging to the current user only. */
    private function scoped(): \Illuminate\Database\Eloquent\Builder
    {
        return SavedView::query()->where('user_id', $this->userId());
    }

    /**
     * The current user's id — saved views are personal state, so an unauthenticated request is refused rather
     * than reading/writing user_id=null rows (which would be shared across guests). The endpoints normally
     * sit inside an auth-gated admin group; this is defence-in-depth.
     */
    private function userId(): int|string
    {
        $id = auth()->id();
        abort_if($id === null, 403);

        return $id;
    }
}
