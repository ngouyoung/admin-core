{{--
     Data-driven, permission-aware sidebar menu. Renders config('admin-core.menu') by
     default, a named menu config('admin-core.menus.NAME') via the `menu` prop, or a
     passed :items array. For a separate-guard portal, pass the portal's `guard` so the
     permission checks run against the right user. Usage (in a ul.ac-nav):

       x-admin-core::sidebar-menu
       x-admin-core::sidebar-menu menu="merchant" guard="merchant"

     Items the user can't access — by permission or because the route doesn't exist —
     are dropped, and empty section headers with them. Treeview children render
     recursively.

     Accessibility: collapsible groups carry aria-expanded (kept in sync by shell.js) and
     aria-controls pointing at their treeview's id; the active leaf is marked aria-current,
     and decorative icons are aria-hidden.
--}}
@props(['items' => null, 'menu' => null, 'guard' => null, 'nested' => false])

@php($list = $items ?? ($menu !== null
    ? \Ngos\AdminCore\Support\Sidebar::items(config('admin-core.menus.' . $menu, []), $guard)
    : (config('admin-core.menu_source') === 'database'
        ? \Ngos\AdminCore\Support\Sidebar::database($guard)
        : \Ngos\AdminCore\Support\Sidebar::items(config('admin-core.menu', []), $guard))
))

@foreach ($list as $item)
    @if (isset($item['header']))
        <li class="ac-nav-header">{{ __($item['header']) }}</li>
    @elseif (isset($item['children']))
        @php($open = isset($item['match']) && request()->is($item['match']))
        @php($tvId = 'ac-tv-' . \Illuminate\Support\Str::random(8)) {{-- unique per render: no id collision even with duplicate labels --}}
        <li class="ac-nav-item {{ $open ? 'open' : '' }}">
            <a href="#" role="button" class="ac-nav-link ac-nav-toggle"
               aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="{{ $tvId }}">
                <i class="{{ $item['icon'] ?? 'bi bi-circle' }}" aria-hidden="true"></i><span>{{ __($item['label']) }}</span>
                <i class="bi bi-chevron-right ac-nav-caret" aria-hidden="true"></i>
            </a>
            <ul class="ac-treeview" id="{{ $tvId }}">
                <x-admin-core::sidebar-menu :items="$item['children']" nested />
            </ul>
        </li>
    @else
        @php($active = isset($item['match']) && request()->is($item['match']))
        <li class="ac-nav-item">
            <a href="{{ isset($item['route']) ? route($item['route']) : ($item['url'] ?? '#') }}"
               class="ac-nav-link {{ $active ? 'active' : '' }}" @if ($active) aria-current="page" @endif>
                <i class="{{ $item['icon'] ?? 'bi bi-circle' }}" aria-hidden="true"></i><span>{{ __($item['label']) }}</span>
            </a>
        </li>
    @endif
@endforeach
