@php
    $selection=$card['selection'];
    $parts=explode(' · ',$selection->competition,2);
    $flags=['Belgia'=>'🇧🇪','Turcia'=>'🇹🇷','Anglia'=>'🏴','Spania'=>'🇪🇸','Germania'=>'🇩🇪','Japonia'=>'🇯🇵','Irlanda'=>'🇮🇪','Islanda'=>'🇮🇸','Italia'=>'🇮🇹'];
    $state=in_array($selection->result,['won','lost','pending'],true)?$selection->result:'unknown';
    $selectionLabel=match($state){'won'=>'Câștigătoare','lost'=>'Pierdută','pending'=>'În așteptare',default=>'Indisponibilă'};
@endphp
<article class="card imported-match-card">
    <div class="imported-match-meta"><span><span class="imported-country-flag" aria-hidden="true">{{ $flags[$parts[0]]??'⚽' }}</span>{{ mb_strtoupper($selection->competition) }}</span><span>{{ $selection->kickoff_at?->format('d.m.Y · H:i') ?? 'Data indisponibilă' }} <b>{{ $card['has_score']?'Final':'Rezultat indisponibil' }}</b></span></div>
    <div class="imported-scoreboard">
        <div class="imported-team"><span class="imported-team-initials" aria-hidden="true">{{ mb_strtoupper(mb_substr($selection->home_team,0,2)) }}</span><h2>{{ $selection->home_team }}</h2></div>
        <div class="imported-score"><strong>{{ $card['has_score']?$selection->home_score.' – '.$selection->away_score:'— : —' }}</strong><span>{{ $card['has_score']?'FINAL':'SCOR INDISPONIBIL' }}</span></div>
        <div class="imported-team"><span class="imported-team-initials" aria-hidden="true">{{ mb_strtoupper(mb_substr($selection->away_team,0,2)) }}</span><h2>{{ $selection->away_team }}</h2></div>
    </div>
    <div class="imported-selection-result"><span class="imported-selection-icon"><i data-lucide="circle-dot" size="21"></i></span><div class="imported-selection-name"><small>SELECȚIA TA</small><strong>{{ $selection->market_label }} · {{ $selection->selection_label }}</strong></div><div class="imported-selection-odd"><small>Cotă</small><strong>{{ $selection->odds===null?'—':number_format($selection->odds,2) }}</strong></div><span class="imported-selection-badge {{ $state }}">{{ $selectionLabel }}</span></div>
    @if($card['explanation'])
        <div class="imported-explanation {{ $state }}"><span><i data-lucide="{{ $state==='won'?'circle-check':'circle-x' }}" size="22"></i></span><div><h3>{{ $state==='won'?'De ce a câștigat selecția?':'De ce a pierdut selecția?' }}</h3><p>{{ $card['explanation'] }}</p>@if($selection->details['note']??null)<p class="imported-explanation-note">{{ $selection->details['note'] }}</p>@endif</div></div>
    @elseif($state==='pending' || $state==='unknown')
        <div class="imported-explanation neutral"><span><i data-lucide="info" size="22"></i></span><div><h3>Rezultat indisponibil</h3><p>Selecția nu are încă un rezultat confirmat în datele importate.</p></div></div>
    @endif
    @if($card['summary'])
        <div class="imported-match-summary"><h3><i data-lucide="clipboard-list" size="18"></i>Rezumat meci</h3><div class="imported-summary-grid">@foreach($card['summary'] as $metric)<div><span><i data-lucide="{{ $metric['icon'] }}" size="17"></i></span><strong>{{ $metric['value'] }}</strong><small>{{ $metric['label'] }}</small></div>@endforeach</div></div>
    @endif
</article>
