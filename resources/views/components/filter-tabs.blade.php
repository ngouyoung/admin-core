{{-- Segmented filter tabs that drive a DataTables column search. Usage:
       <x-admin-core::filter-tabs table="#users_table" :column="2"
            :tabs="['' => 'All', 'admin' => 'Admins', 'editor' => 'Editors']" />
     or, from a backed enum (single source of truth — tabs follow the cases):
       <x-admin-core::filter-tabs table="#posts_table" :column="2"
            :enum="\App\Enums\PostStatus::class" />
     Each tab's array key is the value searched on the given column index
     (empty string = clear the filter); the value is the visible label.
     Works with server-side DataTables (Yajra) via column().search(). --}}
@props(['table', 'column', 'tabs' => null, 'enum' => null])
@php
    if ($tabs === null) {
        $tabs = ['' => 'All'];
        foreach (($enum ? $enum::cases() : []) as $case) {
            $tabs[$case->value] = \Illuminate\Support\Str::headline($case->value);
        }
    }
@endphp
<div class="ac-tabs" role="tablist" data-ac-tabs data-ac-table="{{ $table }}" data-ac-column="{{ $column }}">
    @foreach ($tabs as $value => $label)
        <button type="button" class="ac-tab {{ $loop->first ? 'is-active' : '' }}"
                data-ac-value="{{ $value }}" role="tab">{{ $label }}</button>
    @endforeach
</div>
