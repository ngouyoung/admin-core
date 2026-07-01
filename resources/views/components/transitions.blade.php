{{-- State-machine buttons for a document's show page. Each item is a transition the current user may run
     from the record's current state (already permission- + state-filtered by the controller's
     transitionsFor()). A plain transition is a button that POSTs (with a `->confirm()` dialog if asked); an
     action with a `->form()` opens a modal that collects + validates input first. A no-op when empty. --}}
@props(['items' => []])
@if (! empty($items))
    <div class="ac-transitions d-flex flex-wrap gap-2 mb-3">
        @foreach ($items as $t)
            @php($key = $t['key'])
            @if (empty($t['form']))
                <form action="{{ $t['url'] }}" method="POST" class="ac-transition-form d-inline"
                      data-confirm="{{ $t['confirm'] ?? '' }}">
                    @csrf
                    <input type="hidden" name="_idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <button type="submit" class="btn btn-{{ $t['color'] ?? 'secondary' }}">
                        @if (! empty($t['icon']))<i class="{{ $t['icon'] }}"></i> @endif{{ $t['label'] }}
                    </button>
                </form>
            @else
                {{-- Input action: the button opens a modal; the modal form POSTs the validated fields. --}}
                <button type="button" class="btn btn-{{ $t['color'] ?? 'secondary' }}"
                        data-bs-toggle="modal" data-bs-target="#ac-tr-{{ $key }}">
                    @if (! empty($t['icon']))<i class="{{ $t['icon'] }}"></i> @endif{{ $t['label'] }}
                </button>
                <div class="modal fade" id="ac-tr-{{ $key }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <form action="{{ $t['url'] }}" method="POST" class="modal-content">
                            @csrf
                            <input type="hidden" name="_idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                            <input type="hidden" name="_ac_modal" value="ac-tr-{{ $key }}">
                            <div class="modal-header">
                                <h5 class="modal-title">{{ $t['label'] }}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                @foreach ($t['form'] as $f)
                                    @php($id = 'ac-' . $key . '-' . $f['name'])
                                    <div class="mb-3">
                                        @if ($f['type'] !== 'checkbox')
                                            <label for="{{ $id }}" class="form-label">{{ $f['label'] }}@if ($f['required'])<span class="text-danger">*</span>@endif</label>
                                        @endif
                                        @switch($f['type'])
                                            @case('textarea')
                                                <textarea name="{{ $f['name'] }}" id="{{ $id }}" rows="3"
                                                          class="form-control @error($f['name']) is-invalid @enderror">{{ old($f['name']) }}</textarea>
                                                @break
                                            @case('select')
                                                <select name="{{ $f['name'] }}" id="{{ $id }}" class="form-select @error($f['name']) is-invalid @enderror">
                                                    <option value="">—</option>
                                                    @foreach ($f['options'] as $val => $opt)
                                                        <option value="{{ $val }}" @selected((string) old($f['name']) === (string) $val)>{{ $opt }}</option>
                                                    @endforeach
                                                </select>
                                                @break
                                            @case('checkbox')
                                                <div class="form-check">
                                                    <input type="checkbox" name="{{ $f['name'] }}" value="1" id="{{ $id }}"
                                                           class="form-check-input @error($f['name']) is-invalid @enderror" @checked(old($f['name']))>
                                                    <label class="form-check-label" for="{{ $id }}">{{ $f['label'] }}</label>
                                                </div>
                                                @break
                                            @default
                                                <input type="{{ $f['type'] }}" @if ($f['type'] === 'number') step="any" @endif
                                                       name="{{ $f['name'] }}" id="{{ $id }}" value="{{ old($f['name']) }}"
                                                       class="form-control @error($f['name']) is-invalid @enderror">
                                        @endswitch
                                        @error($f['name'])<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                    </div>
                                @endforeach
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('admin-core::admin-core.actions.cancel') }}</button>
                                <button type="submit" class="btn btn-{{ $t['color'] ?? 'primary' }}">{{ $t['label'] }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
    @once
        @push('scripts')
            <script>
                // Confirm a transition that asked for one (SweetAlert if present, else native confirm).
                document.addEventListener('submit', function (e) {
                    const form = e.target.closest('.ac-transition-form');
                    if (!form) return;
                    const msg = form.getAttribute('data-confirm');
                    if (!msg || form.dataset.acConfirmed === '1') return;
                    e.preventDefault();
                    if (window.Swal) {
                        window.Swal.fire({ title: msg, icon: 'question', showCancelButton: true })
                            .then((r) => { if (r.value) { form.dataset.acConfirmed = '1'; form.submit(); } });
                    } else if (window.confirm(msg)) {
                        form.dataset.acConfirmed = '1';
                        form.submit();
                    }
                });
            </script>
        @endpush
    @endonce
    @if (old('_ac_modal'))
        {{-- Re-open the action's modal after a validation error so the user sees the messages + their input. --}}
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const el = document.getElementById(@json(old('_ac_modal')));
                    if (el && window.bootstrap) { new window.bootstrap.Modal(el).show(); }
                });
            </script>
        @endpush
    @endif
@endif
