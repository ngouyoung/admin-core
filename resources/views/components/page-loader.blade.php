{{-- A full-page loading overlay: covers the page during load/navigation and fades out once it's ready.
     Drop it just inside <body> in your layout: <x-admin-core::page-loader />. Colours follow the theme
     (--ac-accent, --ac-body-bg, --ac-border, with sensible fallbacks). It auto-hides on window `load`
     (plus an 8s safety timeout so it can never trap the page) and re-shows when navigating away, so
     page-to-page transitions and refreshes get a spinner instead of a blank flash. --}}
<div id="ac-page-loader" class="ac-page-loader" role="status" aria-live="polite" aria-label="Loading">
    <span class="ac-page-loader__spinner"></span>
</div>
@once
    <style>
        .ac-page-loader{position:fixed;inset:0;z-index:2000;display:flex;align-items:center;justify-content:center;background:var(--ac-body-bg,#fff);transition:opacity .25s ease;}
        .ac-page-loader.is-hidden{opacity:0;visibility:hidden;}
        .ac-page-loader__spinner{width:2.5rem;height:2.5rem;border-radius:50%;border:.25rem solid var(--ac-border,#e5e7eb);border-top-color:var(--ac-accent,#0d6efd);animation:ac-spin .7s linear infinite;}
        @keyframes ac-spin{to{transform:rotate(360deg);}}
    </style>
    @push('scripts')
        <script>
            (function () {
                var el = document.getElementById('ac-page-loader');
                if (!el) return;
                var hide = function () { el.classList.add('is-hidden'); };
                window.addEventListener('load', hide);
                setTimeout(hide, 8000); // safety: never trap the page if `load` never fires
                window.addEventListener('beforeunload', function () { el.classList.remove('is-hidden'); }); // refresh / navigate away
                window.addEventListener('pageshow', function (e) { if (e.persisted) hide(); }); // bfcache back/forward
            })();
        </script>
    @endpush
@endonce
