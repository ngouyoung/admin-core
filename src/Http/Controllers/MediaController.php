<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ngos\AdminCore\Models\MediaItem;
use Ngos\AdminCore\Support\MediaLibrary;

/**
 * The media library screen: browse (paginated, searchable, collection-filtered), drag-drop upload, and delete.
 * Registered by Route::adminCoreMedia(); reuses the MediaLibrary service (which rides Support\Media for
 * compression / disk / CDN).
 */
class MediaController extends Controller
{
    public function __construct(private MediaLibrary $library) {}

    public function index(Request $request): View
    {
        $items = $this->library
            ->query($request->query('search'), $request->query('collection'))
            ->paginate((int) config('admin-core.pagination', 50))
            ->withQueryString();

        return view('admin-core::media.index', [
            'items' => $items,
            'collections' => $this->library->collections(),
            'search' => $request->query('search'),
            'collection' => $request->query('collection'),
        ]);
    }

    /** JSON list of library items for the media picker modal (paginated + searchable). */
    public function list(Request $request): JsonResponse
    {
        $items = $this->library
            ->query($request->query('search'), $request->query('collection'))
            ->paginate((int) config('admin-core.pagination', 50));

        return response()->json([
            'data' => $items->getCollection()->map(fn (MediaItem $m) => [
                'id' => $m->getKey(),
                'name' => $m->name,
                'url' => $m->url,
                'is_image' => $m->is_image,
            ])->values(),
            'next' => $items->hasMorePages() ? $items->currentPage() + 1 : null,
        ]);
    }

    /** Upload one or more files into the library; returns the created items as JSON. */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array'],
            // Allowlist (config admin-core.uploads.allowed_mimes) — never accept executable/markup uploads
            // (php, phtml, svg, html…) onto the public disk, where they'd be served as stored XSS / RCE.
            'files.*' => [
                'file',
                'mimes:' . config('admin-core.uploads.allowed_mimes', 'jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,csv,txt,zip'),
                'max:' . (int) config('admin-core.uploads.max_kb', 12288),
            ],
            'collection' => ['nullable', 'string', 'max:191'],
        ]);

        $items = collect($request->file('files'))
            ->map(fn ($file) => $this->library->store($file, (string) ($request->input('collection') ?: 'default')))
            ->map(fn (MediaItem $m) => ['id' => $m->getKey(), 'name' => $m->name, 'url' => $m->url, 'is_image' => $m->is_image])
            ->values();

        return response()->json(['data' => $items]);
    }

    public function destroy(MediaItem $media): JsonResponse
    {
        if (! $this->library->delete($media)) {
            return response()->json(['ok' => false, 'message' => __('That file is still in use and can\'t be deleted.')], 409);
        }

        return response()->json(['ok' => true]);
    }
}
