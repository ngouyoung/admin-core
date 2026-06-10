{{-- Row actions as a kebab (⋯) dropdown: View / Edit / Delete.
     Vars: $model, $base (route-name base e.g. 'admin.assessments.users.'), $resource (e.g. 'user').
     The View item appears only when the resource registered a `show` route.
     Delete keeps id="delete" + data-remote so the existing SweetAlert handler still binds. --}}
<div class="dropdown ac-row-actions">
    <button class="ac-kebab" type="button" data-bs-toggle="dropdown" data-bs-display="static"
            aria-expanded="false" aria-label="Actions">
        <i class="bi bi-three-dots"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end ac-actions-menu">
        @if (Route::has($base . 'show'))
            @can('list-' . $resource)
                <li><a class="dropdown-item" href="{{ route($base . 'show', $model->getRouteKey()) }}">
                    <i class="bi bi-eye"></i> View</a></li>
            @endcan
        @endif
        @can('edit-' . $resource)
            <li><a class="dropdown-item" href="{{ route($base . 'edit', $model->getRouteKey()) }}">
                <i class="bi bi-pencil"></i> Edit</a></li>
        @endcan
        @can('delete-' . $resource)
            <li><button type="button" class="dropdown-item text-danger" id="delete"
                        data-remote="{{ route($base . 'ajaxDelete', $model->getRouteKey()) }}">
                <i class="bi bi-trash"></i> Delete</button></li>
        @endcan
    </ul>
</div>
