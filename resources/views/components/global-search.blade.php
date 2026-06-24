{{-- Global search box for the topbar. Queries config('admin-core.search') via the admin-core.search
     endpoint (Route::adminCoreSearch()) and shows a grouped results dropdown. LIKE-based, no dependency.
     Drop it in the header: <x-admin-core::global-search />. Renders nothing if no resources are configured.

     Accessibility: an ARIA combobox (input) + listbox (results) with full keyboard support —
     ArrowUp/Down to move through options, Enter to open, Escape to close — aria-activedescendant
     tracking, and a polite live region announcing the result count. WCAG 2.1 AA. --}}
@php($acSearchRoute = config('admin-core.route.name_prefix', 'admin.') . 'search')
@php($acSearchLabel = __('admin-core::admin-core.actions.search') ?: 'Search')
@if (config('admin-core.search') && \Illuminate\Support\Facades\Route::has($acSearchRoute))
    <div class="ac-global-search" role="search" style="position:relative">
        <label for="ac-gsearch" class="visually-hidden">{{ $acSearchLabel }}</label>
        <input type="search" id="ac-gsearch" class="form-control form-control-sm" autocomplete="off"
               role="combobox" aria-expanded="false" aria-controls="ac-gsearch-results"
               aria-autocomplete="list" aria-haspopup="listbox" aria-label="{{ $acSearchLabel }}"
               placeholder="{{ $acSearchLabel }}…" style="min-width:180px">
        <div class="dropdown-menu shadow border-0 mt-1" id="ac-gsearch-results" role="listbox"
             aria-label="{{ $acSearchLabel }}" style="width:320px;max-height:360px;overflow:auto;border-radius:.75rem"></div>
        <span class="visually-hidden" role="status" aria-live="polite" id="ac-gsearch-status"></span>
    </div>
    @once
        @push('scripts')
            <script>
                (function () {
                    var input = document.getElementById('ac-gsearch'),
                        box = document.getElementById('ac-gsearch-results'),
                        status = document.getElementById('ac-gsearch-status'),
                        url = @json(route($acSearchRoute)), timer, active = -1;
                    if (!input) return;
                    function esc(s) {
                        return String(s).replace(/[&<>"']/g, function (c) {
                            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
                        });
                    }
                    function options() { return box.querySelectorAll('[role="option"]'); }
                    function open(show) {
                        box.classList.toggle('show', show);
                        input.setAttribute('aria-expanded', show ? 'true' : 'false');
                        if (!show) { active = -1; input.removeAttribute('aria-activedescendant'); }
                    }
                    function highlight(i) {
                        var opts = options();
                        opts.forEach(function (o) { o.classList.remove('active'); o.setAttribute('aria-selected', 'false'); });
                        if (i < 0 || i >= opts.length) { active = -1; input.removeAttribute('aria-activedescendant'); return; }
                        active = i;
                        opts[i].classList.add('active');
                        opts[i].setAttribute('aria-selected', 'true');
                        input.setAttribute('aria-activedescendant', opts[i].id);
                        opts[i].scrollIntoView({ block: 'nearest' });
                    }
                    function render(items) {
                        active = -1; input.removeAttribute('aria-activedescendant');
                        if (!items.length) {
                            box.innerHTML = '<span class="dropdown-item-text text-muted small" role="presentation">No results</span>';
                            status.textContent = 'No results'; // the live region announces it; the span is presentational
                            open(true);
                            return;
                        }
                        var html = '', group = null, n = 0;
                        items.forEach(function (i) {
                            if (i.group !== group) { group = i.group; html += '<h6 class="dropdown-header" role="presentation">' + esc(group) + '</h6>'; }
                            html += '<a class="dropdown-item text-truncate" role="option" id="ac-gsearch-opt-' + (n++) + '" aria-selected="false" href="' + esc(i.url || '#') + '"><i class="' + esc(i.icon) + ' me-2" aria-hidden="true"></i>' + esc(i.label) + '</a>';
                        });
                        box.innerHTML = html;
                        status.textContent = items.length + ' result' + (items.length === 1 ? '' : 's');
                        open(true);
                    }
                    input.addEventListener('input', function () {
                        clearTimeout(timer);
                        var q = input.value.trim();
                        if (q.length < 2) { open(false); status.textContent = ''; return; }
                        timer = setTimeout(function () {
                            fetch(url + '?q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                                .then(function (r) { return r.json(); }).then(render).catch(function () {});
                        }, 250);
                    });
                    input.addEventListener('keydown', function (e) {
                        var opts = options();
                        if (e.key === 'ArrowDown') {
                            if (!box.classList.contains('show')) return;
                            e.preventDefault();
                            highlight(active + 1 >= opts.length ? 0 : active + 1);
                        } else if (e.key === 'ArrowUp') {
                            if (!box.classList.contains('show')) return;
                            e.preventDefault();
                            highlight(active - 1 < 0 ? opts.length - 1 : active - 1);
                        } else if (e.key === 'Enter') {
                            if (active >= 0 && opts[active]) { e.preventDefault(); window.location.href = opts[active].getAttribute('href'); }
                        } else if (e.key === 'Escape') {
                            open(false); input.focus(); // explicit focus return (don't rely on the browser)
                        }
                    });
                    box.addEventListener('mousemove', function (e) {
                        var opt = e.target.closest('[role="option"]');
                        if (opt) { highlight([].indexOf.call(options(), opt)); }
                    });
                    document.addEventListener('click', function (e) {
                        if (!input.contains(e.target) && !box.contains(e.target)) open(false);
                    });
                })();
            </script>
        @endpush
    @endonce
@endif
