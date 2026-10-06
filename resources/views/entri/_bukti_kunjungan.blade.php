@if(config('susenas.bukti_kunjungan'))
<div class="panel" id="bukti-kunjungan">
  <h2>Bukti kunjungan</h2>
  <p class="muted small">Foto bangunan / wawancara rumah tangga ini. Terlihat oleh pengawas.
    @if(config('susenas.bukti_kunjungan_wajib'))<strong>Minimal 1 foto sebelum finalisasi.</strong>@endif</p>

  @if($visitPhotos->isEmpty())
    <p class="muted small">Belum ada foto.</p>
  @else
    <div class="foto-grid">
      @foreach ($visitPhotos as $ph)
        <figure class="foto-item">
          <a href="{{ route('kunjungan.foto', $ph) }}" target="_blank" rel="noopener">
            <img src="{{ route('kunjungan.foto', $ph) }}" alt="{{ $ph->caption ?: 'bukti kunjungan' }}" loading="lazy">
          </a>
          <figcaption>
            {{ $ph->caption ?: '—' }}
            <span class="muted">{{ $ph->uploader?->name }} · {{ $ph->created_at->format('d/m H:i') }}</span>
            @if(auth()->user()->role === 'admin' || (int) $ph->uploaded_by === auth()->id())
              <form method="post" action="{{ route('kunjungan.foto.hapus', $ph) }}" onsubmit="return confirm('Hapus foto ini?')">
                @csrf @method('DELETE')<button class="linkbtn">hapus</button>
              </form>
            @endif
          </figcaption>
        </figure>
      @endforeach
    </div>
  @endif

  @if(in_array(auth()->user()->role, ['operator','pml','pj','admin']) && $doc->status !== 'bersih' && ! $doc->cycleLocked())
    <form method="post" action="{{ route('entri.foto.store', $doc) }}" enctype="multipart/form-data" class="stack-form mt">
      @csrf
      <label>Tambah foto (JPG/PNG/WebP, maks 8 MB)
        <input type="file" name="foto" accept="image/*" capture="environment" required>
      </label>
      <label>Keterangan (opsional)
        <input name="caption" maxlength="200" placeholder="mis. tampak depan rumah">
      </label>
      <div><button type="submit">Unggah</button></div>
    </form>
  @endif
</div>
@endif
