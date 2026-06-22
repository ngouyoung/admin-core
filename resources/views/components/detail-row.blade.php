{{-- One label/value row inside <x-admin-core::detail-list>. Usage:
       <x-admin-core::detail-row label="Email">{{ $object->email }}</x-admin-core::detail-row>
       <x-admin-core::detail-row label="Type" width="140px"><code>{{ $log->type }}</code></x-admin-core::detail-row>
     `label` the row heading · `width` the label-column width (default 220px; pass null to let it auto-size). --}}
@props(['label', 'width' => '220px'])
<tr>
    <th @if ($width) style="width: {{ $width }}" @endif>{{ $label }}</th>
    <td>{{ $slot }}</td>
</tr>
