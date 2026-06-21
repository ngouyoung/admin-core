{{-- Global search box for the topbar. Queries config('admin-core.search') via the admin-core.search
     endpoint (Route::adminCoreSearch()) and shows a grouped results dropdown. LIKE-based, no dependency.
     Drop it in the header: <x-admin-core::global-search />. Renders nothing if no resources are configured. --}}
@php($acSearchRoute = config('admin-core.route.name_prefix', 'admin.') . 'search')
@if (config('admin-core.search') && \Illuminate\Support\Facades\Route::has($acSearchRoute))
    <div class="ac-global-search" style="position:relative">
        <input type="search" id="ac-gsearch" class="form-control form-control-sm" autocomplete="off"
               placeholder="{{ __('admin-core::admin-core.actions.search') ?? 'Search' }}…" style="min-width:180px">
        <div class="dropdown-menu shadow border-0 mt-1" id="ac-gsearch-results"
             style="width:320px;max-height:360px;overflow:auto;border-radius:.75rem"></div>
    </div>
    @once
        @push('scripts')
            <script>
                (function () {
                    var input = document.getElementById('ac-gsearch'),
                        box = document.getElementById('ac-gsearch-results'),
                        url = @json(route($acSearchRoute)), timer;
                    if (!input) return;
                    function esc(s) {
                        return String(s).replace(/[&<>"']/g, function (c) {
                            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
                        });
                    }
                    function render(items) {
                        if (!items.length) { box.innerHTML = '<span class="dropdown-item-text text-muted small">No results</span>'; box.classList.add('show'); return; }
                        var html = '', group = null;
                        items.forEach(function (i) {
                            if (i.group !== group) { group = i.group; html += '<h6 class="dropdown-header">' + esc(group) + '</h6>'; }
                            html += '<a class="dropdown-item text-truncate" href="' + esc(i.url || '#') + '"><i class="' + esc(i.icon) + ' me-2"></i>' + esc(i.label) + '</a>';
                        });
                        box.innerHTML = html; box.classList.add('show');
                    }
                    input.addEventListener('input', function () {
                        clearTimeout(timer);
                        var q = input.value.trim();
                        if (q.length < 2) { box.classList.remove('show'); return; }
                        timer = setTimeout(function () {
                            fetch(url + '?q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                                .then(function (r) { return r.json(); }).then(render).catch(function () {});
                        }, 250);
                    });
                    document.addEventListener('click', function (e) {
                        if (!input.contains(e.target) && !box.contains(e.target)) box.classList.remove('show');
                    });
                })();
            </script>
        @endpush
    @endonce
@endif
