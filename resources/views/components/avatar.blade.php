@props(['user' => null, 'name' => null, 'size' => 30])
@php
  $px = (int) $size;
  $label = $user?->name ?? $name ?? '?';
  $img = $user?->avatar ?? null;
  if ($img) {
      $initials = ''; $hue = 0;
  } else {
      $parts = array_values(array_filter(preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', (string) $label)))));
      $initials = $parts ? mb_strtoupper(mb_substr($parts[0], 0, 1).(count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '')) : '?';
      $hue = $user ? $user->avatarHue() : (int) (crc32((string) $label) % 360);
  }
@endphp
@if($img)
  <img src="{{ \Illuminate\Support\Facades\Storage::url($img) }}" alt="{{ $label }}"
    class="avatar avatar-img" style="width:{{ $px }}px;height:{{ $px }}px" title="{{ $label }}">
@else
  <span class="avatar" title="{{ $label }}" aria-hidden="true"
    style="width:{{ $px }}px;height:{{ $px }}px;font-size:{{ round($px * 0.4) }}px;background:hsl({{ $hue }} 40% 40%)">{{ $initials }}</span>
@endif
