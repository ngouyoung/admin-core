<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ngos\AdminCore\Support\Search;

/**
 * Global search endpoint. Wired by `Route::adminCoreSearch()` and surfaced by the
 * `<x-admin-core::global-search />` topbar component. Searches the resources declared in
 * config('admin-core.search') with LIKE — no external search engine / dependency.
 */
class SearchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // The portal's guard (if Route::adminCoreSearch('merchant') set one) so the permission gate resolves the
        // right user; null falls back to the default guard.
        $guard = $request->route()?->defaults['acSearchGuard'] ?? null;

        return response()->json(Search::query((string) $request->query('q', ''), guard: $guard));
    }
}
