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
--}}
@props(['items' => null, 'menu' => null, 'guard' => null, 'nested' => false])

@php($list = $items ?? \Ngos\AdminCore\Support\Sidebar::items(
    $menu !== null ? config('admin-core.menus.' . $menu, []) : config('admin-core.menu', []),
    $guard,
))

@foreach ($list as $item)
    @if (isset($item['header']))
        <li class="ac-nav-header">{{ $item['header'] }}</li>
    @elseif (isset($item['children']))
        <li class="ac-nav-item {{ isset($item['match']) && request()->is($item['match']) ? 'open' : '' }}">
            <a href="#" class="ac-nav-link ac-nav-toggle">
                <i class="{{ $item['icon'] ?? 'bi bi-circle' }}"></i><span>{{ $item['label'] }}</span>
                <i class="bi bi-chevron-right ac-nav-caret"></i>
            </a>
            <ul class="ac-treeview">
                <x-admin-core::sidebar-menu :items="$item['children']" nested />
            </ul>
        </li>
    @else
        <li class="ac-nav-item">
            <a href="{{ isset($item['route']) ? route($item['route']) : ($item['url'] ?? '#') }}"
               class="ac-nav-link {{ isset($item['match']) && request()->is($item['match']) ? 'active' : '' }}">
                <i class="{{ $item['icon'] ?? 'bi bi-circle' }}"></i><span>{{ $item['label'] }}</span>
            </a>
        </li>
    @endif
@endforeach
