{{-- Pemilih siklus untuk halaman pemantauan (Inc 154). Butuh $cycle (null = gabungan), $choices.
     Bawaan dari controller (pickCycle) = siklus lapangan terbaru. --}}
<form method="get" class="cycle-pick">
  @foreach (request()->except('cycle') as $k => $v)
    @continue(is_array($v))
    <input type="hidden" name="{{ $k }}" value="{{ $v }}">
  @endforeach
  <label>Siklus
    <select name="cycle" onchange="this.form.submit()">
      @foreach ($choices->groupBy(fn ($c) => $c->year().' TW'.$c->quarter()) as $grp => $cs)
        <optgroup label="{{ $grp }}">
          @foreach ($cs as $c)
            <option value="{{ $c->id }}" @selected($cycle && $cycle->id === $c->id)>TW{{ $c->quarter() }} {{ $c->year() }} · {{ $c->label() }} ({{ Istilah::cycleStatus($c->status) }})</option>
          @endforeach
        </optgroup>
      @endforeach
      <option value="semua" @selected(!$cycle)>Semua siklus (gabungan)</option>
    </select>
  </label>
  <noscript><button type="submit">Tampilkan</button></noscript>
  @if($cycle && in_array(auth()->user()->role, ['pml', 'pj', 'admin'], true))
    <a class="small" href="{{ route('periode.hub', $cycle) }}">Buka hub {{ $cycle->label() }} TW{{ $cycle->quarter() }} {{ $cycle->year() }} →</a>
  @endif
</form>
