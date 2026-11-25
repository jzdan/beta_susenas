{{-- "Dipakai oleh": aturan/turunan yang memakai sebuah agregat, turunan, atau parameter (Inc 155). --}}
@if(!empty($who))
  <span class="used-by small" title="{{ implode(', ', $who) }}">dipakai oleh {{ count($who) }}:
    {{ implode(', ', array_slice($who, 0, 3)) }}@if(count($who) > 3), +{{ count($who) - 3 }} lagi @endif</span>
@else
  <span class="used-by small muted" title="Belum dirujuk aturan atau turunan mana pun; bisa saja dipakai tabulasi/indikator.">belum dipakai aturan/turunan</span>
@endif
