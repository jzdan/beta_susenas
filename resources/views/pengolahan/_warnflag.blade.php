{{-- $w: ['status','message','code','row_index','ack'] ; $var: target_var (boleh '') ; $doc --}}
@php($locked = $doc->status === 'bersih' || $doc->status === 'dikembalikan')
<div class="flag {{ $w['status'] }} {{ $w['ack'] ? 'flag-ack' : '' }}">
  <span>{{ $w['message'] }}</span>
  @if($w['status'] === 'warning' && $w['code'] !== '')
    @if($w['ack'])
      <div class="ack-note">✔ Ditinjau{{ $w['ack']['by'] ? ' — '.$w['ack']['by'] : '' }}: {{ $w['ack']['note'] }}
        @unless($locked)
        <form method="post" action="{{ route('pengolahan.batal-terima-warning', $doc) }}" style="display:inline">
          @csrf
          <input type="hidden" name="rule_code" value="{{ $w['code'] }}">
          <input type="hidden" name="target_var" value="{{ $var }}">
          <input type="hidden" name="row_index" value="{{ $w['row_index'] }}">
          <button type="submit" class="linkbtn">batalkan</button>
        </form>
        @endunless
      </div>
    @elseif(!$locked)
      <form method="post" action="{{ route('pengolahan.terima-warning', $doc) }}" class="ack-form">
        @csrf
        <input type="hidden" name="rule_code" value="{{ $w['code'] }}">
        <input type="hidden" name="target_var" value="{{ $var }}">
        <input type="hidden" name="row_index" value="{{ $w['row_index'] }}">
        <input name="note" placeholder="catatan peninjauan (mis. dikonfirmasi ke responden — wajar)" required minlength="3">
        <button type="submit" class="btn-ghost">Terima</button>
      </form>
    @endif
  @endif
</div>
