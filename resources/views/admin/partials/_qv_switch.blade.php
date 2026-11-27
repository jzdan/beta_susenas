{{-- Selector versi kuesioner yang sedang dilihat/disunting. "Bawaan" (is_active) hanya versi yang
     dibuka pertama kali di menu admin; tidak memengaruhi petugas (isian ikut periode sampelnya). --}}
<div class="row-form" style="margin-bottom:10px;align-items:center">
  <label class="small">Versi kuesioner
    <select onchange="var u=new URL(location);u.searchParams.set('qv',this.value);location=u;">
      @foreach ($versions as $v)
        <option value="{{ $v->id }}" @selected($v->id === $qv->id)>{{ $v->code }}{{ $v->id === $activeId ? ' (bawaan)' : '' }}</option>
      @endforeach
    </select>
  </label>
  @if($qv->cycle)
    <span class="muted small">Dipakai sampel periode {{ $qv->cycle->name }} ({{ strtolower(Istilah::cycleStatus($qv->cycle->status)) }}).</span>
  @endif
</div>
