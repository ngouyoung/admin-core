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
        [$search, $collection] = [$this->stringQuery($request, 'search'), $this->stringQuery($request, 'collection')];
        $items = $this->library
            ->query($search, $collection)
            ->paginate((int) config('admin-core.pagination', 50))
            ->withQueryString();

        return view('admin-core::media.index', [
            'items' => $items,
            'collections' => $this->library->collections(),
            'search' => $search,
            'collection' => $collection,
        ]);
    }

    /** JSON list of library items for the media picker modal (paginated + searchable). */
    public function list(Request $request): JsonResponse
    {
        $items = $this->library
            ->query($this->stringQuery($request, 'search'), $this->stringQuery($request, 'collection'))
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
            // A single path-safe segment — it's concatenated into the storage dir ('media/'.$collection), so
            // reject '../..', slashes, dots, etc. (which would path-traverse to the disk root or crash
            // Flysystem) with a clean 422 instead.
            'collection' => ['nullable', 'string', 'max:191', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $items = collect($request->file('files'))
            ->map(fn ($file) => $this->library->store($file, (string) ($request->input('collection') ?: 'default')))
            ->map(fn (MediaItem $m) => ['id' => $m->getKey(), 'name' => $m->name, 'url' => $m->url, 'is_image' => $m->is_image])
            ->values();

        return response()->json(['data' => $items]);
    }

    /** A query param as a string or null — `?search[]=x` (an array) must not TypeError into a 500. */
    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }

    public function destroy(MediaItem $media): JsonResponse
    {
        // Under the 'own' library scope a crafted uuid must not reach another user's / portal's upload — 404 it
        // (no existence disclosure), exactly as the browse/list query hides it. A no-op for the default 'shared'.
        abort_unless($this->library->owns($media), 404);

        if (! $this->library->delete($media)) {
            return response()->json(['ok' => false, 'message' => __('That file is still in use and can\'t be deleted.')], 409);
        }

        return response()->json(['ok' => true]);
    }
}
