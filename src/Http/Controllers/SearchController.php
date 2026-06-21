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
        return response()->json(Search::query((string) $request->query('q', '')));
    }
}
