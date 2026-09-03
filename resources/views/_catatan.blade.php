{{-- $doc, $notes (koleksi DocumentNote+user, urut lama→baru), $from = 'entri'|'pengolahan' --}}
<div class="catatan" id="catatan">
  <h3>Catatan dokumen</h3>
  <ul class="catatan-list">
    @forelse ($notes as $n)
    <li>
      <div class="catatan-meta">
        <strong>{{ $n->user->name ?? '—' }}</strong>
        <span class="muted small">{{ $n->created_at?->format('d M Y H:i') }}</span>
      </div>
      <div class="catatan-body">{{ $n->body }}</div>
    </li>
    @empty
    <li class="muted">Belum ada catatan.</li>
    @endforelse
  </ul>
  @if($doc->status !== 'bersih' && in_array(auth()->user()->role, ['operator','pml','editor','admin']))
  <form method="post" action="{{ route('dokumen.catatan', $doc) }}" class="catatan-form">
    @csrf
    <input type="hidden" name="from" value="{{ $from }}">
    <textarea name="body" rows="2" required minlength="2" maxlength="2000"
      placeholder="Tanya/klarifikasi ke petugas atau pemeriksa (tanpa mengubah status)"></textarea>
    <button type="submit" class="btn-ghost">Kirim catatan</button>
  </form>
  @endif
</div>
