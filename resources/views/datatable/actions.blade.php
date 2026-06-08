{{-- Standard edit/delete action buttons for a DataTables row.
     Vars: $model, $base (route-name base e.g. 'admin.assessments.users.'), $resource (e.g. 'user'). --}}
@can('edit-' . $resource)
    <a href="{{ route($base . 'edit', $model->id) }}" class="{{ config('class.button.edit') }}"
       data-bs-toggle="tooltip" title="Edit Record"><i class="{{ config('class.icon.edit') }}"></i></a>
@endcan
@can('delete-' . $resource)
    <button data-remote="{{ route($base . 'ajaxDelete', $model->id) }}" class="{{ config('class.button.delete') }}"
            data-bs-toggle="tooltip" title="Delete Record" id="delete"><i class="{{ config('class.icon.delete') }}"></i></button>
@endcan
