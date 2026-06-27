{{-- A one-time submit token: a repeated POST (double-click / browser retry) carrying the same value is
     short-circuited by WebController::store() instead of creating a duplicate record. Auto-included in
     generated create forms; drop it into any custom create form too. The value survives a validation-error
     redisplay via old() so the corrected resubmit reuses the same token. Disable globally with
     config('admin-core.forms.idempotency'). --}}
@if (config('admin-core.forms.idempotency', true))
    <input type="hidden" name="_idempotency_key" value="{{ old('_idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
@endif
