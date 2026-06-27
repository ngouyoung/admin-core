@extends('backend.layouts.app')

@section('title', 'Media')

@section('contents')
    @php($acPrefix = config('admin-core.route.name_prefix'))
    <x-admin-core::page-header title="{{ __('Media library') }}" />

    <x-admin-core::card>
        <form method="GET" class="d-flex flex-wrap gap-2 mb-3">
            <input type="search" name="search" value="{{ $search }}" class="form-control form-control-sm" style="max-width: 240px"
                placeholder="{{ __('Search files…') }}">
            @if (count($collections) > 1)
                <select name="collection" class="form-select form-select-sm" style="max-width: 200px" onchange="this.form.submit()">
                    <option value="">{{ __('All collections') }}</option>
                    @foreach ($collections as $acCol)
                        <option value="{{ $acCol }}" @selected($collection === $acCol)>{{ $acCol }}</option>
                    @endforeach
                </select>
            @endif
            <button class="btn btn-sm btn-secondary">{{ __('Search') }}</button>
        </form>

        <div data-ac-media-dropzone data-ac-upload-url="{{ route($acPrefix . 'media.upload') }}" data-ac-collection="{{ $collection }}"
            class="border border-2 border-dashed rounded p-4 text-center text-muted mb-3" style="cursor: pointer">
            <i class="bi bi-cloud-arrow-up fs-3 d-block mb-1"></i>
            {{ __('Drag files here, or click to upload') }}
            <input type="file" multiple class="d-none" data-ac-media-input>
        </div>

        <div class="row g-3" data-ac-media-grid>
            @forelse ($items as $item)
                <div class="col-6 col-md-3 col-lg-2" data-ac-media-tile>
                    <div class="card h-100">
                        <div class="ratio ratio-1x1 bg-light d-flex align-items-center justify-content-center overflow-hidden">
                            @if ($item->is_image)
                                <img src="{{ $item->url }}" alt="{{ $item->alt ?? $item->name }}" class="w-100 h-100" style="object-fit: cover">
                            @else
                                <i class="bi bi-file-earmark fs-1 text-muted"></i>
                            @endif
                        </div>
                        <div class="card-body p-2 small">
                            <div class="text-truncate" title="{{ $item->name }}">{{ $item->name }}</div>
                            <div class="d-flex justify-content-between mt-1">
                                <button type="button" class="btn btn-sm btn-link p-0" data-ac-media-copy data-ac-url="{{ $item->url }}" title="{{ __('Copy URL') }}">
                                    <i class="bi bi-link-45deg"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-link text-danger p-0" data-ac-media-delete
                                    data-ac-url="{{ route($acPrefix . 'media.destroy', $item->uuid) }}" title="{{ __('Delete') }}">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="col-12">
                    <p class="text-muted text-center py-4 mb-0">{{ __('No media yet — drag some files into the box above.') }}</p>
                </div>
            @endforelse
        </div>

        <div class="mt-3">{{ $items->links() }}</div>
    </x-admin-core::card>
@endsection
