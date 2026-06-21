{{-- The submit + cancel row at the foot of a form. Usage:
       <x-admin-core::form-actions submit="Create" :cancel="route('admin.products.index')" />
     `submit` is the primary button label; `cancel` (a URL) renders a Cancel link next
     to it (omit it for submit-only). `submitClass` / `cancelClass` override the
     config('class.button.*') defaults — e.g. pass the update style on an edit form. --}}
@props(['submit' => null, 'cancel' => null, 'submitClass' => null, 'cancelClass' => null])
<div class="row">
    <div class="col-md-8 offset-md-2">
        <button type="submit" class="{{ $submitClass ?? config('class.button.create') }}">{{ $submit ?? __('admin-core::admin-core.actions.save') }}</button>
        @if ($cancel)
            <a href="{{ $cancel }}" class="{{ $cancelClass ?? config('class.button.cancel') }}">{{ __('admin-core::admin-core.actions.cancel') }}</a>
        @endif
    </div>
</div>
