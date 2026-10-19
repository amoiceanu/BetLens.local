<div class="ticket-options-content">
    <div class="ticket-options-heading"><b>Opțiunile biletului</b><span>{{ $ticket->selections->count() }} {{ $ticket->selections->count()===1?'selecție':'selecții' }}</span></div>
    @if($ticket->selections->isEmpty())
        <p class="muted">Nu există selecții salvate pentru acest bilet.</p>
    @else
        <div class="ticket-options-grid">
            @foreach($ticket->selections as $selection)
                @php
                    $recommendation=$imported?null:$selection->recommendation;
                    $match=$recommendation?->match;
                    $competition=$imported?$selection->competition:$match?->league?->name;
                    $teams=$imported?$selection->home_team.' – '.$selection->away_team:($match?$match->homeTeam?->name.' – '.$match->awayTeam?->name:'Meci indisponibil');
                    $kickoff=$imported?$selection->kickoff_at:$match?->kickoff_at;
                    $market=$imported?$selection->market_label:$recommendation?->market?->name;
                    $choice=$imported?$selection->selection_label:$recommendation?->selection;
                    $odd=$imported?$selection->odds:$selection->odds_at_creation;
                    $result=$selection->result;
                @endphp
                <article class="ticket-option-card">
                    <div class="ticket-option-meta"><span>{{ $competition?:'Competiție indisponibilă' }}</span><time>{{ $kickoff?->format('d.m.Y H:i')??'Data indisponibilă' }}</time></div>
                    <strong class="ticket-option-teams">{{ $teams }}</strong>
                    <div class="ticket-option-bottom"><span>{{ $market?:'Piață indisponibilă' }} · <b>{{ $choice?:'Selecție indisponibilă' }}</b></span><span class="ticket-option-odd">{{ $odd===null?'—':number_format($odd,2) }}</span></div>
                    @if($imported)<small class="ticket-option-result">{{ match($result){'won'=>'Câștigătoare','lost'=>'Pierdută','pending'=>'În așteptare',default=>'Indisponibilă'} }}</small>@endif
                </article>
            @endforeach
        </div>
    @endif
</div>
