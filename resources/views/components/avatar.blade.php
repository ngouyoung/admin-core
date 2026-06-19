{{-- A round avatar: the photo when `src` is set, otherwise a coloured circle with
     the name's initials (colour derived from the name, so it's stable per person).
     Usage:
       <x-admin-core::avatar :src="$user->avatar_url" :name="$user->name" size="40" />
     `size` is pixels (default 40); omit `src` for the initials fallback. --}}
@props(['src' => null, 'name' => '', 'size' => 40])
@php
    $acName = trim((string) $name);
    $acInitials = \Illuminate\Support\Str::of($acName)->explode(' ')->filter()
        ->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    $acPx = max(16, (int) $size);
    $acHue = $acName === '' ? 210 : crc32($acName) % 360;
@endphp
@if ($src)
    <img {{ $attributes->merge(['class' => 'ac-avatar rounded-circle']) }} src="{{ $src }}" alt="{{ $acName }}"
         style="width: {{ $acPx }}px; height: {{ $acPx }}px; object-fit: cover;">
@else
    <span {{ $attributes->merge(['class' => 'ac-avatar ac-avatar-initials rounded-circle']) }} title="{{ $acName }}"
          style="width: {{ $acPx }}px; height: {{ $acPx }}px; font-size: {{ (int) round($acPx * 0.4) }}px; background: hsl({{ $acHue }}deg 60% 45%);"
          aria-label="{{ $acName }}">{{ $acInitials !== '' ? $acInitials : '?' }}</span>
@endif
