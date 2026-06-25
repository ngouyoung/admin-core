{{-- A labelled <select> field row. Give options as an [value => label] array, or render
     <option>s yourself in the slot. select2-enhanced by default (matches every other dropdown).
     Usage:
       <x-admin-core::select name="status" :options="['active' => 'Active', 'inactive' => 'Inactive']" :value="old('status', $object?->status)" />
       <x-admin-core::select name="category_id" :options="$categories" placeholder="— select —" :value="old('category_id', $object?->category_id)" />
       <x-admin-core::select name="tags" :options="$tags" :value="$selectedTagIds" multiple />

     `name` field name · `label` row label · `value` selected value (array when multiple)
     `options` [value => label] · `placeholder` empty first option (single only)
     `multiple` multi-select · `enhance` add the select2 class (default true). Extra attrs pass through.

     For a big list, make it a REMOTE select that searches + paginates server-side instead of rendering every
     option. Two ways:
       • `source` (preferred) — the related resource's route base; the endpoint is resolved dynamically from
         the configured route-name prefix (portal/prefix-safe), falling back to a static select when that
         resource has no `select` route:
           <x-admin-core::select name="product_id" source="products"
                :options="$object?->product ? [$object->product_id => ac_localize($object->product->name)] : []"
                :value="old('product_id', $object?->product_id)" placeholder="— search —" />
       • `ajax-url` — an explicit URL (wins over `source`), e.g. :ajax-url="route('admin.products.select')".
     Pass only the currently-selected option as `options` (so an edit form shows it); the rest load on search. --}}
@props(['name', 'label' => null, 'value' => null, 'options' => [], 'placeholder' => null, 'multiple' => false, 'enhance' => true, 'ajaxUrl' => null, 'source' => null])
@php
    $label ??= \Illuminate\Support\Str::headline($name);
    $selected = array_map('strval', (array) $value);
    // `source` resolves the remote endpoint dynamically from the route-name prefix; an explicit ajax-url wins.
    // No matching `select` route → $ajaxUrl stays null → it renders as a plain static select.
    if ($ajaxUrl === null && $source !== null) {
        $route = config('admin-core.route.name_prefix') . $source . '.select';
        $ajaxUrl = \Illuminate\Support\Facades\Route::has($route) ? route($route) : null;
    }
    $remote = $ajaxUrl !== null;
@endphp
<x-admin-core::form-row :name="$name" :label="$label">
    <select name="{{ $name }}{{ $multiple ? '[]' : '' }}" id="{{ $name }}" @if ($multiple) multiple @endif
        @if ($placeholder !== null) data-placeholder="{{ $placeholder }}" @endif
        @if ($remote) data-ajax-url="{{ $ajaxUrl }}" @endif
        {{ $attributes->class([
            'form-select',
            'admin-core-select' => $enhance && ! $remote,
            'admin-core-select-ajax' => $remote,
            'is-invalid' => $errors->has($name),
        ]) }}>
        @if ($placeholder !== null && ! $multiple)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $val => $text)
            <option value="{{ $val }}" @selected(in_array((string) $val, $selected, true))>{{ $text }}</option>
        @endforeach
        {{ $slot }}
    </select>
</x-admin-core::form-row>
