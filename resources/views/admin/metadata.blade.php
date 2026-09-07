@extends('layouts.app')
@php($nav = 'meta')
@section('title', 'Metadata Kuesioner · Sistem SUSENAS')
@section('content')
<h1>Metadata Kuesioner</h1>
<p class="muted">Versi aktif: <strong>{{ $qv->code }}</strong> (berlaku {{ $qv->effective_date }}).
  "Aktif" = versi yang sedang disunting di menu Admin. Periode baru cukup
  <em>duplikasi versi</em> lalu sunting, bukan mengubah kode.</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

<section class="panel">
  <h2>Versi kuesioner</h2>
  @foreach ($versions as $v)
    <form method="post" action="{{ route('admin.versi.simpan', $v) }}" id="vf{{ $v->id }}" hidden>@csrf</form>
  @endforeach
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th style="width:180px">Kode</th><th style="width:160px">Tanggal berlaku</th><th>Dokumen</th><th></th><th></th></tr></thead>
    <tbody>
    @foreach ($versions as $v)
      <tr>
        <td><input form="vf{{ $v->id }}" name="code" value="{{ $v->code }}" class="mono" style="width:160px"></td>
        <td><input form="vf{{ $v->id }}" type="date" name="effective_date" value="{{ $v->effective_date }}"></td>
        <td class="muted">{{ $v->documents_count }} dokumen</td>
        <td>@if($v->id === $qv->id)<span class="chip d-bersih">aktif</span>@endif</td>
        <td class="right nowrap">
          <button form="vf{{ $v->id }}" type="submit" class="btn-ghost">Simpan</button>
          @if($v->id !== $qv->id)
            <form method="post" action="{{ route('admin.versi.aktifkan', $v) }}">@csrf<button class="btn-ghost">Jadikan aktif</button></form>
          @endif
          <a class="btn-ghost" href="{{ route('admin.versi.codebook', $v) }}">Buku kode</a>
          <a class="btn-ghost" href="{{ route('admin.versi.export', $v) }}">Ekspor JSON</a>
          @if($v->documents_count === 0 && $versions->count() > 1)
            <form method="post" action="{{ route('admin.versi.hapus', $v) }}"
              onsubmit="return confirm('Hapus versi {{ $v->code }} beserta semua blok, rincian, dan aturannya? Tidak bisa dibatalkan.')">
              @csrf<button class="btn-ghost" style="color:var(--err)">Hapus</button></form>
          @endif
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
  <form method="post" action="{{ route('admin.versi.duplikat') }}" class="row-form mt">
    @csrf
    <input name="new_code" placeholder="Kode versi baru, mis. VSEN27.KP-rev2" required style="min-width:280px">
    <button type="submit">Duplikasi versi aktif</button>
  </form>
  <p class="muted small mt">Paket JSON berisi seluruh metadata versi (blok, rincian, opsi, parameter,
    aturan, agregat, nilai turunan, baris tetap).
    <a href="{{ route('admin.versi.import') }}">Impor paket dari instansi lain →</a>
    (atau CLI <span class="mono">php artisan susenas:qversion:import &lt;file&gt;</span>).</p>
</section>

@foreach ($blocks as $i => $b)
<section class="panel">
  <div class="panel-head">
    <h2>Blok {{ $b->code }} — {{ $b->title }}
      @if($b->repeat !== 'ruta')<span class="chip d-draft">{{ ['art'=>'roster ART','list'=>'daftar baris tetap','list_art'=>'daftar × ART'][$b->repeat] ?? $b->repeat }}</span>@endif</h2>
    <span>
      <form method="post" action="{{ route('admin.blok.order', $b) }}" style="display:inline">
        @csrf
        <button class="btn-ghost" name="arah" value="naik" @disabled($i === 0) title="naikkan">↑</button>
        <button class="btn-ghost" name="arah" value="turun" @disabled($loop->last) title="turunkan">↓</button>
      </form>
      <a class="btn-ghost" href="{{ route('admin.blok', $b) }}">Sunting blok</a>
      <form method="post" action="{{ route('admin.blok.del', $b) }}" style="display:inline" onsubmit="return confirm('Hapus blok {{ $b->code }}?')">
        @csrf<button class="btn-ghost">Hapus</button>
      </form>
    </span>
  </div>
  <table class="tbl">
    <thead><tr><th>Rincian</th><th>Variabel</th><th>Label</th><th>Tipe</th><th>Skip bila</th><th></th></tr></thead>
    <tbody>
    @foreach ($b->questions->sortBy('order') as $q)
      <tr>
        <td class="mono">{{ $q->code_rincian }}</td>
        <td class="mono">{{ $q->var_name }}</td>
        <td>{{ $q->label }}</td>
        <td>{{ $q->qtype }}@if($q->unit) · {{ $q->unit }}@endif</td>
        <td class="mono muted small">{{ $q->skip_expr ?: '—' }}</td>
        <td class="right"><a class="btn-ghost" href="{{ route('admin.rincian', $q) }}">Sunting</a></td>
      </tr>
    @endforeach
    </tbody>
  </table>
</section>
@endforeach

<section class="panel">
  <h2>Tambah blok</h2>
  <form method="post" action="{{ route('admin.blok.add') }}" class="row-form">
    @csrf
    <input name="code" placeholder="Kode (mis. IV.1)" required>
    <input name="title" placeholder="Judul blok" required style="min-width:240px">
    <select name="repeat">
      <option value="ruta">per rumah tangga</option>
      <option value="art">roster per ART</option>
      <option value="list">daftar baris tetap (komoditas dsb.)</option>
      <option value="list_art">daftar baris tetap × per ART (mis. makanan jadi / MBG)</option>
    </select>
    <button type="submit">Tambah</button>
  </form>
</section>

<section class="panel">
  <h2>Parameter</h2>
  @foreach ($params as $p)
  <div class="param-row">
    <form method="post" action="{{ route('admin.parameter.save') }}" class="row-form">
      @csrf
      <input type="hidden" name="param_id" value="{{ $p->id }}">
      <input name="name" value="{{ $p->name }}" class="mono" required>
      <input name="value" value="{{ $p->value }}" style="width:110px" required>
      <input name="note" value="{{ $p->note }}" style="flex:1;min-width:180px">
      <button class="btn-ghost">Simpan</button>
    </form>
    <form method="post" action="{{ route('admin.parameter.del', $p) }}">@csrf<button class="btn-ghost">Hapus</button></form>
  </div>
  @endforeach
  <form method="post" action="{{ route('admin.parameter.save') }}" class="row-form mt">
    @csrf
    <input name="name" placeholder="nama_parameter_baru" class="mono" required>
    <input name="value" placeholder="nilai" style="width:110px" required>
    <input name="note" placeholder="catatan" style="flex:1;min-width:180px">
    <button type="submit">Tambah</button>
  </form>
</section>

@php($opOpts = ['sum','weighted_sum','count','max','min'])
@php($fopOpts = ['any','eq','in','ne'])
<section class="panel">
  <h2>Agregat cek-silang <small class="muted">(NERACA — agregasi terkelompok atas blok berulang)</small></h2>

  @forelse ($aggregates->sortBy('order') as $a)
  <details class="param-row" style="display:block">
    <summary style="cursor:pointer">
      <span class="mono">{{ $a->name }}</span> = {{ $a->op }}(<span class="mono">{{ $a->value_var }}</span>) atas <span class="mono">{{ $a->block_code }}</span>
      <span class="muted small">{{ $a->filter_op === 'any' && !$a->filter_ref ? '' : '· filter' }}</span>
    </summary>
    <form method="post" action="{{ route('admin.agregat.save') }}" class="row-form mt">
      @csrf
      <input type="hidden" name="aggregate_id" value="{{ $a->id }}">
      <input name="order" value="{{ $a->order }}" style="width:52px" title="urutan">
      <input name="name" value="{{ $a->name }}" class="mono" required style="width:130px">
      <input name="label" value="{{ $a->label }}" placeholder="label" style="width:150px">
      <select name="block_code" required>
        @foreach ($repeatBlocks as $b)<option value="{{ $b->code }}" @selected($a->block_code === $b->code)>{{ $b->code }}</option>@endforeach
      </select>
      <input name="value_var" value="{{ $a->value_var }}" class="mono" required style="width:120px">
      <select name="op">@foreach ($opOpts as $o)<option @selected($a->op === $o)>{{ $o }}</option>@endforeach</select>
      <input name="weight_ref" value="{{ $a->weight_ref }}" placeholder="ref pembobot" class="mono" style="width:120px">
      <input name="filter_var" value="{{ $a->filter_var }}" placeholder="kolom filter" class="mono" style="width:110px">
      <select name="filter_op">@foreach ($fopOpts as $o)<option @selected($a->filter_op === $o)>{{ $o }}</option>@endforeach</select>
      <input name="filter_val" value="{{ $a->filter_val }}" placeholder="kode" style="width:90px">
      <input name="filter_ref" value="{{ $a->filter_ref }}" placeholder="ref penyaring" class="mono" style="width:120px">
      <input name="filter_ref_val" value="{{ $a->filter_ref_val }}" placeholder="nilai ref" class="mono" style="width:110px">
      <button class="btn-ghost">Simpan</button>
    </form>
    <form method="post" action="{{ route('admin.agregat.del', $a) }}" onsubmit="return confirm('Hapus agregat {{ $a->name }}?')">
      @csrf<button class="btn-ghost">Hapus</button>
    </form>
  </details>
  @empty
  <p class="muted">Belum ada agregat.</p>
  @endforelse

  <form method="post" action="{{ route('admin.agregat.save') }}" class="row-form mt">
    @csrf
    <input name="order" placeholder="urut" value="{{ ($aggregates->max('order') ?? 0) + 10 }}" style="width:52px">
    <input name="name" placeholder="nama_var" class="mono" required style="width:130px">
    <input name="label" placeholder="label" style="width:150px">
    <select name="block_code" required>
      @foreach ($repeatBlocks as $b)<option value="{{ $b->code }}">{{ $b->code }}</option>@endforeach
    </select>
    <input name="value_var" placeholder="kolom nilai" class="mono" required style="width:120px">
    <select name="op">@foreach ($opOpts as $o)<option>{{ $o }}</option>@endforeach</select>
    <input name="weight_ref" placeholder="ref pembobot" class="mono" style="width:120px">
    <input name="filter_var" placeholder="kolom filter" class="mono" style="width:110px">
    <select name="filter_op">@foreach ($fopOpts as $o)<option>{{ $o }}</option>@endforeach</select>
    <input name="filter_val" placeholder="kode (1 atau 2,3)" style="width:90px">
    <input name="filter_ref" placeholder="ref penyaring (mis. group)" class="mono" style="width:120px">
    <input name="filter_ref_val" placeholder="nilai ref (mis. IKAN)" class="mono" style="width:110px">
    <button type="submit">Tambah</button>
  </form>
  <p class="muted small">Ekspresi aturan per-baris bisa memakai <span class="mono">ref_&lt;kunci&gt;</span>
    (mis. <span class="mono">ref_harga_min</span>) dari data referensi baris.</p>
</section>

<section class="panel">
  <h2>Variabel turunan</h2>
  @error('expr')<div class="alert err">{{ $message }}</div>@enderror
  @foreach ($derived->sortBy('order') as $d)
  <div class="param-row">
    <form method="post" action="{{ route('admin.turunan.save') }}" class="row-form">
      @csrf
      <input type="hidden" name="derived_id" value="{{ $d->id }}">
      <input name="order" value="{{ $d->order }}" style="width:52px" title="urutan">
      <input name="var_name" value="{{ $d->var_name }}" class="mono" required style="width:150px">
      <input name="label" value="{{ $d->label }}" style="width:180px">
      <input name="expr" value="{{ $d->expr }}" class="mono" required style="flex:1;min-width:220px">
      <button class="btn-ghost">Simpan</button>
    </form>
    <form method="post" action="{{ route('admin.turunan.del', $d) }}" onsubmit="return confirm('Hapus turunan {{ $d->var_name }}?')">
      @csrf<button class="btn-ghost">Hapus</button>
    </form>
  </div>
  @endforeach
  <form method="post" action="{{ route('admin.turunan.save') }}" class="row-form mt">
    @csrf
    <input name="order" placeholder="urut" value="{{ ($derived->max('order') ?? 0) + 10 }}" style="width:52px">
    <input name="var_name" placeholder="nama_var_turunan" class="mono" required style="width:150px">
    <input name="label" placeholder="label" style="width:180px">
    <input name="expr" placeholder="ekspresi, mis. pengeluaran_ruta / n(banyak_art)" class="mono" required style="flex:1;min-width:220px">
    <button type="submit">Tambah</button>
  </form>
  <p class="muted small">Dievaluasi berurutan setelah agregat; hasilnya jadi variabel di konteks (dipakai aturan &amp; tabulasi).</p>
</section>

<section class="panel">
  <h2>Aturan validasi <a class="btn-ghost" href="{{ route('admin.aturan') }}">Kelola →</a></h2>
  <table class="tbl">
    <thead><tr><th>Kode</th><th>Sev</th><th>Ekspresi</th><th>Aktif</th></tr></thead>
    <tbody>
    @foreach ($rules as $r)
      <tr class="{{ $r->active ? '' : 'row-off' }}">
        <td class="mono">{{ $r->code }}</td>
        <td><span class="flag {{ $r->severity }}">{{ $r->severity }}</span></td>
        <td class="mono small">{{ $r->expr }}
          @if($ruleErrs[$r->id])<div class="flag error">sintaks: {{ $ruleErrs[$r->id] }}</div>@endif
        </td>
        <td>{{ $r->active ? 'ya' : 'tidak' }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
</section>
@endsection
