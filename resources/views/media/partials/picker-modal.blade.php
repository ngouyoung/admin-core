{{-- The shared media picker modal, emitted once per page by <x-admin-core::media-collection>. Browses the
     library (admin.media.list) + uploads (admin.media.upload); media-picker.js feeds the field that opened it. --}}
<div class="modal fade" id="acMediaPicker" tabindex="-1" aria-hidden="true"
    data-ac-list-url="{{ route($acPrefix . 'media.list') }}" data-ac-upload-url="{{ route($acPrefix . 'media.upload') }}">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header gap-2">
                <h5 class="modal-title mb-0">{{ __('Media library') }}</h5>
                <input type="search" class="form-control form-control-sm" style="max-width: 220px" data-ac-picker-search
                    placeholder="{{ __('Search files…') }}">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
            </div>
            <div class="modal-body">
                <div data-ac-picker-dropzone class="border border-2 border-dashed rounded p-2 text-center text-muted small mb-3" style="cursor: pointer">
                    <i class="bi bi-cloud-arrow-up"></i> {{ __('Drop files here, or click to upload') }}
                    <input type="file" multiple class="d-none" data-ac-picker-input>
                </div>
                <div class="row g-2" data-ac-picker-grid></div>
            </div>
        </div>
    </div>
</div>
