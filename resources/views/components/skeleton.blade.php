{{-- Animated loading-skeleton placeholders to show while content loads, then swap
     for the real thing. Usage:
       <x-admin-core::skeleton :lines="3" />                 (text lines)
       <x-admin-core::skeleton type="card" />                (a card-shaped block)
       <x-admin-core::skeleton type="table" :rows="5" :cols="4" />
     The shimmer + colours (dark-mode aware) live in app.scss (.ac-skeleton). --}}
@props(['type' => 'text', 'lines' => 3, 'rows' => 5, 'cols' => 4])
@switch($type)
    @case('card')
        <div {{ $attributes->merge(['class' => 'card']) }} aria-hidden="true">
            <div class="card-body">
                <div class="ac-skeleton ac-skeleton-title"></div>
                @for ($i = 0; $i < (int) $lines; $i++)
                    <div class="ac-skeleton ac-skeleton-line"></div>
                @endfor
            </div>
        </div>
        @break

    @case('table')
        <table {{ $attributes->merge(['class' => 'table']) }} aria-hidden="true">
            <tbody>
                @for ($r = 0; $r < (int) $rows; $r++)
                    <tr>
                        @for ($c = 0; $c < (int) $cols; $c++)
                            <td><div class="ac-skeleton ac-skeleton-line"></div></td>
                        @endfor
                    </tr>
                @endfor
            </tbody>
        </table>
        @break

    @default
        <div {{ $attributes->merge(['class' => 'ac-skeleton-text']) }} aria-hidden="true">
            @for ($i = 0; $i < (int) $lines; $i++)
                <div class="ac-skeleton ac-skeleton-line"></div>
            @endfor
        </div>
@endswitch
