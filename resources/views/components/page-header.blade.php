{{-- Reusable page header: breadcrumb + title + description + a right-aligned
     action slot. Usage:
       <x-admin-core::page-header title="Users" description="Manage your team.">
           <x-slot:actions>
               <a href="..." class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Add User</a>
           </x-slot:actions>
       </x-admin-core::page-header>
     Pass :breadcrumb="false" to hide the auto "Dashboard › Title" trail, or a
     <x-slot:breadcrumb> to supply your own. --}}
@props(['title', 'description' => null, 'breadcrumb' => true])
<div class="ac-page-header">
    @if ($breadcrumb !== false)
        <nav class="ac-breadcrumb" aria-label="Breadcrumb">
            @isset($breadcrumb)
                {{ $breadcrumb }}
            @else
                @if (Route::has('admin.dashboard'))
                    <a href="{{ route('admin.dashboard') }}">Dashboard</a>
                    <i class="bi bi-chevron-right"></i>
                @endif
                <span class="current">{{ $title }}</span>
            @endisset
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
