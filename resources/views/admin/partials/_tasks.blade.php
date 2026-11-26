{{-- Inc 161: pekerjaan latar belakang (periksa ulang massal / hitung aturan sesudah impor).
     Diperbarui berkala selama masih berjalan. Butuh: $tasks (koleksi BackgroundTask). --}}
@foreach ($tasks ?? [] as $t)
<div class="bg-task panel" data-task="{{ route('admin.tugas.status', $t) }}" data-running="{{ $t->running() ? 1 : 0 }}">
  <div class="bg-task-head">
    <strong>{{ $t->label }}</strong>
    <span class="chip bg-{{ $t->status }}" data-f="label">{{ \App\Models\BackgroundTask::STATUS_LABELS[$t->status] ?? $t->status }}</span>
  </div>
  <div class="bg-bar"><span data-f="bar" style="width:{{ $t->pct() }}%"></span></div>
  <div class="small muted">
    <span data-f="count">{{ number_format($t->done, 0, ',', '.') }} dari {{ number_format($t->total, 0, ',', '.') }} sampel</span>
    <span data-f="msg">{{ $t->message }}</span>
  </div>
  <p class="small bg-stuck" data-f="stuck" @unless($t->stuck()) hidden @endunless>
    Pekerjaan ini belum mulai lebih dari {{ \App\Models\BackgroundTask::STUCK_MINUTES }} menit. Kemungkinan proses latar belakang di server
    belum dinyalakan — minta pengelola server menjalankan <span class="mono">php artisan queue:work</span> (lihat panduan pemasangan).
  </p>
</div>
@endforeach
@once
@push('scripts')
<script>
(function () {
  var nf = function (n) { return Number(n).toLocaleString("id-ID"); };
  document.querySelectorAll(".bg-task[data-running='1']").forEach(function (box) {
    var f = function (k) { return box.querySelector('[data-f="' + k + '"]'); };
    var tick = function () {
      fetch(box.dataset.task, { headers: { Accept: "application/json" } }).then(function (r) { return r.json(); }).then(function (d) {
        f("label").textContent = d.label;
        f("label").className = "chip bg-" + d.status;
        f("bar").style.width = d.pct + "%";
        f("count").textContent = nf(d.done) + " dari " + nf(d.total) + " sampel";
        f("msg").textContent = d.message || "";
        f("stuck").hidden = !d.stuck;
        if (d.running) setTimeout(tick, 3000);
      }).catch(function () { setTimeout(tick, 10000); });
    };
    setTimeout(tick, 1500);
  });
})();
</script>
@endpush
@endonce
