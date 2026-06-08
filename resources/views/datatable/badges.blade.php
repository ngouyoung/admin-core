{{-- A list of Bootstrap-5 badges for a DataTables cell.
     Vars: $items (collection/array of models-with-->name or strings), $variant (BS5 colour, default success). --}}
@foreach ($items as $item)
    <span class="badge text-bg-{{ $variant ?? 'success' }} text-capitalize">{{ is_string($item) ? $item : $item->name }}</span>
@endforeach
