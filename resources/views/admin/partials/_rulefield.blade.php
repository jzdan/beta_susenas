<label>{{ $label }}
  <select name="{{ $name }}">
    <option value="">— pilih rincian —</option>
    @foreach ($wizardBlocks as $bl)
      @if($bl->questions->isNotEmpty())
        <optgroup label="Blok {{ $bl->code }} — {{ $bl->title }}">
          @foreach ($bl->questions as $q)
            <option value="{{ $q->var_name }}">{{ $q->code_rincian }} · {{ $q->label }}</option>
          @endforeach
        </optgroup>
      @endif
    @endforeach
    <optgroup label="Metadata sampel (dari Daftar Sampel Rumah Tangga)">
      <option value="sampel_wilayah">sampel_wilayah · kode wilayah (desa)</option>
      <option value="sampel_bs">sampel_bs · nomor blok sensus</option>
      <option value="sampel_ruta">sampel_ruta · nomor urut rumah tangga</option>
      <option value="sampel_krt">sampel_krt · nama kepala rumah tangga</option>
    </optgroup>
    @if(($derivedFields ?? collect())->isNotEmpty())
      <optgroup label="Nilai turunan (dihitung sistem)">
        @foreach ($derivedFields as $df)
          <option value="{{ $df->var_name }}">{{ $df->var_name }} · {{ $df->label }}</option>
        @endforeach
      </optgroup>
    @endif
  </select>
</label>
