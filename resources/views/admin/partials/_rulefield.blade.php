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
  </select>
</label>
