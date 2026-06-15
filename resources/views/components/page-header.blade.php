{{-- Reusable page header: breadcrumb + title + description + a right-aligned
     action slot. Usage:
       <x-admin-core::page-header title="Users" description="Manage your team.">
           <x-slot:actions>
               <a href="..." class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add User</a>
           </x-slot:actions>
       </x-admin-core::page-header>
     Pass :breadcrumb="false" to hide the auto "Dashboard › Title" trail. For a
     sub-page (create / edit / show), pass parent + parentUrl to insert the list
     crumb: "Dashboard › Posts › Edit". The Dashboard crumb targets the *current*
     portal's dashboard (derived from the route name, e.g. merchant.dashboard on a
     merchant page); pass :dashboard="'x.dashboard'" to override. --}}
@props(['title', 'description' => null, 'breadcrumb' => true, 'parent' => null, 'parentUrl' => null, 'dashboard' => null])
@php($acDashboard = $dashboard ?? \Illuminate\Support\Str::before(Route::currentRouteName() ?? 'admin.', '.') . '.dashboard')
<div class="ac-page-header">
    @if ($breadcrumb)
        <nav class="ac-breadcrumb" aria-label="Breadcrumb">
            @if (Route::has($acDashboard))
                <a href="{{ route($acDashboard) }}">Dashboard</a>
                <i class="bi bi-chevron-right"></i>
            @endif
            @if ($parent)
                @if ($parentUrl)
                    <a href="{{ $parentUrl }}">{{ $parent }}</a>
                @else
                    <span>{{ $parent }}</span>
                @endif
                <i class="bi bi-chevron-right"></i>
            @endif
            <span class="current">{{ $title }}</span>
        </nav>
    @endif
    <div class="ac-page-header-row">
        <div class="ac-page-heading">
            <h1 class="ac-page-title">{{ $title }}</h1>
            @if ($description)
                <p class="ac-page-desc">{{ $description }}</p>
            @endif
        </div>
        @isset($actions)
            <div class="ac-page-actions">{{ $actions }}</div>
        @endisset
    </div>
</div>
