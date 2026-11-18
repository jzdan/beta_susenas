{{-- Tab "Neraca & Turunan" di halaman Aturan Validasi (Inc 141).
     Berisi: Parameter, Agregat cek-silang (NERACA), Variabel turunan.
     Dipindah dari halaman Metadata supaya Metadata hanya untuk membuat kuesioner. --}}
<section class="panel">
  <h2>Parameter <small class="muted">(konstanta per versi — dipakai ekspresi aturan)</small></h2>
  @foreach ($params as $p)
  <div class="param-row">
    <form method="post" action="{{ route('admin.parameter.save') }}" class="row-form">
      @csrf
      <input type="hidden" name="param_id" value="{{ $p->id }}">
      <input type="hidden" name="qv" value="{{ $qv->id }}">
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
    <input type="hidden" name="qv" value="{{ $qv->id }}">
    <input name="name" placeholder="nama_parameter_baru" class="mono" required>
    <input name="value" placeholder="nilai" style="width:110px" required>
    <input name="note" placeholder="catatan" style="flex:1;min-width:180px">
    <button type="submit">Tambah</button>
  </form>

  <details class="mt">
    <summary style="cursor:pointer;font-weight:600">Parameter sistem — nama variabel yang dipakai program</summary>
    <p class="muted small">Bila kuesioner baru mengganti nama variabel kunci, buat parameter dengan nama di bawah
      (nilai = nama variabel; boleh beberapa dipisah koma, yang pertama ada yang dipakai). Bila tidak dibuat,
      dipakai nilai bawaan.</p>
    <div class="tablewrap">
    <table class="tbl mini">
      <thead><tr><th>Nama parameter</th><th>Fungsi</th><th>Berlaku sekarang</th></tr></thead>
      <tbody>
      @foreach (\App\Models\Parameter::SYSTEM as $pn => $meta)
        @php($set = $params->firstWhere('name', $pn))
        <tr>
          <td class="mono">{{ $pn }}</td>
          <td class="small">{{ $meta['label'] }}</td>
          <td class="mono small">{{ $set ? $set->value : $meta['default'] }}
            <span class="muted">{{ $set ? '(diatur)' : '(bawaan)' }}</span></td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  </details>
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
      <input type="hidden" name="qv" value="{{ $qv->id }}">
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
    <input type="hidden" name="qv" value="{{ $qv->id }}">
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
      <input type="hidden" name="qv" value="{{ $qv->id }}">
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
    <input type="hidden" name="qv" value="{{ $qv->id }}">
    <input name="order" placeholder="urut" value="{{ ($derived->max('order') ?? 0) + 10 }}" style="width:52px">
    <input name="var_name" placeholder="nama_var_turunan" class="mono" required style="width:150px">
    <input name="label" placeholder="label" style="width:180px">
    <input name="expr" placeholder="ekspresi, mis. pengeluaran_ruta / n(banyak_art)" class="mono" required style="flex:1;min-width:220px">
    <button type="submit">Tambah</button>
  </form>
  <p class="muted small">Dievaluasi berurutan setelah agregat; hasilnya jadi variabel di konteks (dipakai aturan &amp; tabulasi).</p>
</section>
