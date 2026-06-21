{{-- Per-user UI language switcher. Each item is a plain `?setlang=<code>` link that the SetLocale
     middleware picks up, persists (to the user's `locale` column when present, else the session) and
     applies — no route or controller needed. Drop it in the topbar:  <x-admin-core::language-switcher />
     Renders nothing if fewer than two languages are configured. --}}
@php
    $locales = (array) config('admin-core.translation.locales', []);
    $active = app()->getLocale();
@endphp

@if (count($locales) > 1)
    <div class="dropdown">
        <a href="#" class="ac-user-btn" data-bs-toggle="dropdown" role="button" title="{{ __('admin-core::admin-core.language') }}">
            <i class="bi bi-translate"></i>
            <span class="d-none d-sm-inline text-uppercase ms-1">{{ $active }}</span>
        </a>
        <div class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" style="border-radius:1rem">
            <h6 class="dropdown-header">{{ __('admin-core::admin-core.language') }}</h6>
            @foreach ($locales as $code => $native)
                <a class="dropdown-item d-flex justify-content-between align-items-center {{ $code === $active ? 'active' : '' }}"
                   href="{{ request()->fullUrlWithQuery(['setlang' => $code]) }}">
                    <span>{{ $native }}</span>
                    <small class="text-uppercase text-muted ms-3">{{ $code }}</small>
                </a>
            @endforeach
        </div>
    </div>
@endif
