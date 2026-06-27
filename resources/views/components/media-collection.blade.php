{{-- A media field backed by HasMedia: shows the attached media (drag-reorder, remove) and opens the shared
     picker modal to browse the library + upload. Submits `name[]` = the ordered media_item ids, which the
     generated service hands to $model->syncMedia(...). Used by the `media` (single) + `gallery` (multiple)
     generator field types. Usage:
       <x-admin-core::media-collection name="photos" collection="photos" :items="$attached" :multiple="true" /> --}}
@props(['name', 'label' => null, 'collection' => 'default', 'items' => [], 'multiple' => true])
@php
    $label ??= \Illuminate\Support\Str::headline($name);
    $acPrefix = config('admin-core.route.name_prefix');
    $acReady = \Illuminate\Support\Facades\Route::has($acPrefix . 'media.list');
@endphp
<x-admin-core::form-row :name="$name" :label="$label">
    <div class="ac-media-collection" data-ac-media-collection data-ac-name="{{ $name }}" data-ac-multiple="{{ $multiple ? '1' : '0' }}">
        <div class="d-flex flex-wrap gap-2 mb-2" data-ac-media-items>
            @foreach ($items as $acItem)
                <div class="ac-media-tile position-relative" data-ac-media-tile draggable="true">
                    <input type="hidden" name="{{ $name }}[]" value="{{ $acItem['id'] }}">
                    <div class="ratio ratio-1x1 rounded border overflow-hidden bg-light">
                        @if ($acItem['is_image'] ?? true)
                            <img src="{{ $acItem['url'] }}" class="w-100 h-100" style="object-fit: cover" alt="">
                        @else
                            <span class="d-flex align-items-center justify-content-center h-100"><i class="bi bi-file-earmark fs-3 text-muted"></i></span>
                        @endif
                    </div>
                    <button type="button" class="btn-close position-absolute top-0 end-0 m-1 bg-white rounded-circle" data-ac-media-remove style="font-size: .55rem"></button>
                </div>
            @endforeach
        </div>
        @if ($acReady)
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#acMediaPicker"
                data-ac-media-add>
                <i class="bi bi-images"></i> {{ $multiple ? __('Add media') : __('Choose media') }}
            </button>
        @else
            <small class="text-muted">{{ __('Media routes not installed — run admin-core:install.') }}</small>
        @endif
    </div>
</x-admin-core::form-row>

@once
    @if ($acReady)
        @include('admin-core::media.partials.picker-modal', ['acPrefix' => $acPrefix])
        <style>
            .ac-media-tile { width: 84px; }
            .ac-media-tile.ac-dragging { opacity: .4; }
            [data-ac-picker-grid] .ac-pick { cursor: pointer; transition: outline .1s; }
            [data-ac-picker-grid] .ac-pick:hover { outline: 3px solid var(--bs-primary); }
        </style>
    @endif
@endonce
