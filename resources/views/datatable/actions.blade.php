{{-- Row actions as a kebab (⋯) dropdown: View / Edit / Delete.
     Vars: $model, $base (route-name base e.g. 'admin.assessments.users.'), $resource (e.g. 'user').
     The View item appears only when the resource registered a `show` route.
     Delete keeps id="delete" + data-remote so the existing SweetAlert handler still binds. --}}
<div class="dropdown ac-row-actions">
    <button class="ac-kebab" type="button" data-bs-toggle="dropdown" data-bs-auto-close="true"
            aria-expanded="false" aria-label="Actions">
        <i class="bi bi-three-dots"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end ac-actions-menu">
        @if (Route::has($base . 'show'))
            @if (auth()->guard($guard ?? null)->user()?->can('list-' . $resource))
                <li><a class="dropdown-item" href="{{ route($base . 'show', $model->getRouteKey()) }}">
                    <i class="bi bi-eye"></i> View</a></li>
            @endif
        @endif
        @foreach (($extra ?? []) as $item)
            @if (empty($item['can']) || auth()->guard($guard ?? null)->user()?->can($item['can']))
                <li><a class="dropdown-item {{ $item['class'] ?? '' }}" href="{{ $item['url'] }}">
                    <i class="{{ $item['icon'] ?? 'bi bi-dot' }}"></i> {{ $item['label'] }}</a></li>
            @endif
        @endforeach
        {{-- Declared per-row actions (resourceActions). Already permission-filtered server-side; they POST
             via datatable.js (.ac-row-action) rather than navigate, so they're buttons, not links. --}}
        @foreach (($rowActions ?? []) as $item)
            <li><button type="button" class="dropdown-item ac-row-action"
                        data-ac-url="{{ $item['url'] }}" data-id="{{ $item['id'] }}"
                        data-confirm="{{ $item['confirm'] ?? '' }}">
                <i class="{{ $item['icon'] ?? 'bi bi-lightning' }}"></i> {{ $item['label'] }}</button></li>
        @endforeach
        @if (auth()->guard($guard ?? null)->user()?->can('edit-' . $resource))
            <li><a class="dropdown-item" href="{{ route($base . 'edit', $model->getRouteKey()) }}">
                <i class="bi bi-pencil"></i> Edit</a></li>
        @endif
        @if (auth()->guard($guard ?? null)->user()?->can('delete-' . $resource))
            <li><button type="button" class="dropdown-item text-danger" id="delete"
                        data-remote="{{ route($base . 'ajaxDelete', $model->getRouteKey()) }}">
                <i class="bi bi-trash"></i> Delete</button></li>
        @endif
    </ul>
</div>
