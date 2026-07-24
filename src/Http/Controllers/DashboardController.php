<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Ngos\AdminCore\Dashboard\Dashboard;
use Ngos\AdminCore\Dashboard\DashboardContext;

/**
 * The dashboard widget framework's AJAX surface: renders a single widget's partial for the active range,
 * used by lazy-load (heavy widgets render a skeleton first) and auto-refresh (live widgets re-poll). Wire it
 * with Route::adminCoreDashboard() inside your admin route group (admin-core:install does this).
 */
class DashboardController extends Controller
{
    public function widget(string $key, Request $request, Dashboard $dashboard): View
    {
        // Authorize against the host-declared panel guard (the SERVER-SIDE route default stamped by
        // Route::adminCoreDashboard($guard)); null = the default portal-first resolution. ADR-0009. Read from
        // the route default, never a client param, so no caller can render a widget the dashboard would hide.
        $guard = $request->route()?->defaults['acDashboardGuard'] ?? null;

        $widget = $dashboard->forGuard($guard)->find($key); // resolves + permission-filters; null = unknown/forbidden
        abort_if($widget === null, 404);

        return view($widget->partial(), [
            'widget' => $widget,
            'data' => $dashboard->payload($widget, DashboardContext::fromRequest($request)),
        ]);
    }

    /** Persist the current user's customised layout (widget order + hidden keys). */
    public function saveLayout(Request $request, Dashboard $dashboard): JsonResponse
    {
        $data = $request->validate([
            'order' => ['array', 'max:200'],
            'order.*' => ['string', 'max:191'],
            'hidden' => ['array', 'max:200'],
            'hidden.*' => ['string', 'max:191'],
        ]);

        // Same panel guard as widget(): saveLayout() persists only keys of widgets this guard authorizes (its
        // $valid set walks the authorization-visible widgets), while the storage KEY stays portal-first via
        // currentUser() — attribution unchanged (ADR-0009). Route default, never a client param.
        $guard = $request->route()?->defaults['acDashboardGuard'] ?? null;

        $dashboard->forGuard($guard)->saveLayout($data['order'] ?? [], $data['hidden'] ?? []);

        return response()->json(['ok' => true]);
    }
}
