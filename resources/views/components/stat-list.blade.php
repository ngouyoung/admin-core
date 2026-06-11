{{-- Summary stat list: "Label …………… value [suffix]" rows in a card. Usage:
       <x-admin-core::stat-list title="Summary" :items="[
           ['label' => 'Number of Invoice', 'value' => 72],
           ['label' => 'Total Invoice Amounts', 'value' => '1,417.90', 'suffix' => 'USD'],
           ['label' => 'Refund', 'value' => '-35.00', 'suffix' => 'USD'],
           ['label' => 'Total Outstanding Debt', 'value' => '1,250.00', 'suffix' => 'USD', 'strong' => true],
       ]" />
     Values whose string starts with '-' render red automatically; 'strong' => true
     emphasises a row (e.g. a total). Numbers are tabular-aligned on the right. --}}
@props(['title' => null, 'items' => []])
<div {{ $attributes->merge(['class' => 'card ac-stat-list']) }}>
    @if ($title)
        <div class="card-header">{{ $title }}</div>
    @endif
    <ul class="ac-stat-rows">
        @foreach ($items as $item)
            @php($value = (string) ($item['value'] ?? ''))
            <li @class(['ac-stat-row', 'is-strong' => $item['strong'] ?? false])>
                <span class="ac-stat-row-label">{{ $item['label'] ?? '' }}</span>
                <span @class(['ac-stat-row-value', 'is-negative' => str_starts_with(trim($value), '-')])>
                    {{ $value }}@isset($item['suffix'])<span class="ac-stat-row-suffix">{{ $item['suffix'] }}</span>@endisset
                </span>
            </li>
        @endforeach
    </ul>
</div>
