{{-- Field-level permission guard. Wraps a form field; if the current user isn't allowed to write `name`
     (it's in the controller's deniedFields(), shared as acDeniedFields), the field is rendered inside a
     disabled <fieldset> — which natively disables every control inside AND excludes them from the submit,
     so the value can't be changed. The server still strips the field on write (stripDeniedFields), so this
     is purely the UI half; a no-op passthrough when the field isn't restricted. The deny list is shared
     per-request, so render only one resource's form per page (a second editable resource form could match a
     field of the same name); pass :denied-fields explicitly to override. --}}
@props(['name', 'deniedFields' => null])
@php
    $acDenied = $deniedFields ?? view()->shared('acDeniedFields', []);
    $acLocked = is_array($acDenied) && in_array($name, $acDenied, true);
@endphp
@if ($acLocked)
    <fieldset disabled class="ac-field-locked">
        {{ $slot }}
    </fieldset>
@else
    {{ $slot }}
@endif
