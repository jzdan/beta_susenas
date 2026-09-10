@extends('layouts.app')
@php($nav = 'pengguna')
@section('title', 'Pengguna · Sistem SUSENAS')
@section('content')
<h1>Manajemen Pengguna</h1>
<p class="muted">Produksi: idealnya identitas & peran dari SSO BPS. Halaman ini untuk pengelolaan lokal.</p>

@if(session('ok'))<div class="alert" style="background:var(--ok-soft);color:var(--ok);border:1px solid var(--ok)">{{ session('ok') }}</div>@endif
@if($errors->any())<div class="alert err">{{ $errors->first() }}</div>@endif

<section class="panel">
  <h2>Daftar pengguna ({{ $users->count() }})</h2>
  <div class="tablewrap">
  <table class="tbl">
    <thead><tr><th></th><th>Username</th><th>Nama</th><th>Peran</th><th>Wilayah</th><th>Aktif</th><th></th></tr></thead>
    <tbody>
    @foreach ($users as $u)
      <tr class="{{ $u->active ? '' : 'row-off' }}">
        <td><x-avatar :user="$u" :size="30" /></td>
        <td class="mono">{{ $u->username }}</td>
        <td>{{ $u->name }}</td>
        <td>{{ $roles[$u->role] ?? $u->role }}</td>
        <td class="mono">{{ $u->wilayah_scope ?: '—' }}</td>
        <td>{{ $u->active ? 'ya' : 'tidak' }}</td>
        <td class="right nowrap">
          <button class="btn-ghost" type="button"
            onclick="editUser({{ Illuminate\Support\Js::from($u->only('id','username','name','role','wilayah_scope')) }})">Sunting</button>
          <form method="post" action="{{ route('admin.pengguna.toggle', $u) }}" onsubmit="return confirm('Ubah status aktif?')">
            @csrf<button class="btn-ghost">{{ $u->active ? 'nonaktifkan' : 'aktifkan' }}</button>
          </form>
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  </div>
</section>

<section class="panel">
  <h2 id="form-title">Tambah pengguna</h2>
  <form method="post" action="{{ route('admin.pengguna.save') }}" class="stack-form" id="user-form">
    @csrf
    <input type="hidden" name="user_id" id="user_id">
    <div class="row-form">
      <label>Username <input name="username" id="username" class="mono" required pattern="[a-z0-9_.]+"></label>
      <label>Nama <input name="name" id="name" required></label>
      <label>Peran
        <select name="role" id="role">
          @foreach ($roles as $k => $lbl)<option value="{{ $k }}">{{ $lbl }}</option>@endforeach
        </select>
      </label>
      <label>Wilayah scope <input name="wilayah_scope" id="wilayah_scope" class="mono" placeholder="mis. 3526"></label>
    </div>
    <label>Kata sandi <input type="password" name="password" id="password" placeholder="min. 8 karakter (kosongkan = tidak diubah)"></label>
    <div class="row-form">
      <button type="submit">Simpan</button>
      <button type="button" class="btn-ghost" onclick="resetForm()">Baru</button>
    </div>
  </form>
</section>

@push('scripts')
<script>
function editUser(u) {
  document.getElementById('user_id').value = u.id;
  document.getElementById('username').value = u.username;
  document.getElementById('name').value = u.name;
  document.getElementById('role').value = u.role;
  document.getElementById('wilayah_scope').value = u.wilayah_scope || '';
  document.getElementById('password').value = '';
  document.getElementById('form-title').textContent = 'Sunting: ' + u.username;
  document.getElementById('user-form').scrollIntoView({ behavior: 'smooth' });
}
function resetForm() {
  document.getElementById('user-form').reset();
  document.getElementById('user_id').value = '';
  document.getElementById('form-title').textContent = 'Tambah pengguna';
}
</script>
@endpush
@endsection
