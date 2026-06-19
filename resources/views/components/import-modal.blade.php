{{-- CSV import: a toolbar button + its modal (the form posts a file). Drop it in
     a list toolbar; gate it with @can(...) at the call site. Usage:
       @can('create-product')
           <x-admin-core::import-modal :route="route('admin.products.import')"
               :template="route('admin.products.importTemplate')" title="Products" />
       @endcan
     `route` is the POST import endpoint; `template` (optional) links a blank CSV
     template; `title` names the records in the heading. Pass `id` if more than one
     import modal lives on the same page. Invalid rows are skipped and reported by
     the controller. --}}
@props(['route', 'template' => null, 'title' => 'records', 'id' => 'importModal'])
<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#{{ $id }}">
    <i class="bi bi-upload"></i> Import
</button>
<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="{{ $route }}" method="POST" enctype="multipart/form-data" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Import {{ $title }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                @if ($template)
                    <p class="text-muted small mb-2">Not sure which columns to use?
                        <a href="{{ $template }}"><i class="bi bi-download"></i> Download a blank template</a>,
                        fill in the rows and upload it. (Same shape as Export; invalid rows are skipped and reported.)</p>
                @endif
                <input type="file" name="file" accept=".csv,.txt" class="form-control @error('file') is-invalid @enderror" required>
                @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-upload"></i> Import</button>
            </div>
        </form>
    </div>
</div>
