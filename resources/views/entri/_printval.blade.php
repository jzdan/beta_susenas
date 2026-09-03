@if($c['clean'] !== null && $c['clean'] !== '')
  <strong>{{ $c['clean'] }}</strong>
  @if(($c['raw'] ?? '') !== '')<span class="muted small">(mentah: {{ $c['raw'] }})</span>@endif
@else
  {{ ($c['raw'] ?? '') === '' ? '—' : $c['raw'] }}
@endif
