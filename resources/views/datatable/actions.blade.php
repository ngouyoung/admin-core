{{-- Standard view/edit/delete action buttons for a DataTables row.
     Vars: $model, $base (route-name base e.g. 'admin.assessments.users.'), $resource (e.g. 'user').
     The View button appears only when the resource registered a `show` route. --}}
<div class="d-flex gap-1 flex-nowrap justify-content-center">
    @if (Route::has($base . 'show'))
        @can('list-' . $resource)
            <a href="{{ route($base . 'show', $model->getRouteKey()) }}" class="btn btn-sm btn-info"
               data-bs-toggle="tooltip" title="View Record"><i class="fas fa-eye"></i></a>
        @endcan
    @endif
    @can('edit-' . $resource)
        <a href="{{ route($base . 'edit', $model->getRouteKey()) }}" class="{{ config('class.button.edit') }}"
           data-bs-toggle="tooltip" title="Edit Record"><i class="{{ config('class.icon.edit') }}"></i></a>
    @endcan
    @can('delete-' . $resource)
        <button data-remote="{{ route($base . 'ajaxDelete', $model->getRouteKey()) }}" class="{{ config('class.button.delete') }}"
                data-bs-toggle="tooltip" title="Delete Record" id="delete"><i class="{{ config('class.icon.delete') }}"></i></button>
    @endcan
</div>
