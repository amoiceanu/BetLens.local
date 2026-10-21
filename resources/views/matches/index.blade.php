@extends('layouts.app') @section('title','Meciuri') @section('content')
<div class="matches-page">
    <header class="matches-hero">
        <div><p class="eyebrow">Radarul meciurilor</p><h1>Meciuri</h1><p>Caută după una sau două echipe, fără să conteze diacriticele sau semnele speciale.</p></div>
        <span class="matches-count">{{ number_format($matches->count()) }} {{ $matches->count()===1 ? 'meci' : 'meciuri' }}</span>
    </header>

    <section class="matches-search-card" aria-label="Caută meciuri">
        <form class="matches-search-form" method="get" action="{{ route('matches') }}">
          <div class="matches-filters">
            <label><span class="matches-field-label"><i data-lucide="trophy" size="16"></i>Liga</span><select class="field" name="league"><option value="">Toate ligile ({{ number_format($leagues->sum('listed_matches_count')) }})</option>@foreach($leagues as $league)<option value="{{ $league->id }}" @selected(request('league')==$league->id)>{{ $league->name }} ({{ number_format($league->listed_matches_count) }})</option>@endforeach</select></label>
            <label><span class="matches-field-label"><i data-lucide="users-round" size="16"></i>Echipa 1</span><input class="field" type="search" name="team1" value="{{ $team1 }}" placeholder="Ex. Bayern Munchen" autocomplete="off"></label>
            <label><span class="matches-field-label"><i data-lucide="users-round" size="16"></i>Echipa 2</span><input class="field" type="search" name="team2" value="{{ $team2 }}" placeholder="Ex. RB Leipzig" autocomplete="off"></label>
            <label class="matches-probability-filter"><input type="checkbox" name="with_probabilities" value="1" @checked($onlyWithProbabilities)><span>Doar date cu probabilități</span></label>
          </div>
          <div class="matches-search-divider"><span>sau caută după link</span></div>
          <div class="matches-url-search">
            <label for="match-url"><i data-lucide="link" size="16"></i>URL meci Superbet sau Winbet</label>
            <div class="matches-url-controls"><input id="match-url" class="field" type="search" name="match_url" value="{{ $matchUrl }}" placeholder="https://winbet.ro/in-play/echipa-1-vs-echipa-2-ID" autocomplete="off" maxlength="2048" aria-describedby="match-url-help"><button class="btn matches-submit" type="submit"><i data-lucide="search" size="18"></i>Caută meciuri</button><a class="btn secondary matches-reset" href="{{ route('matches') }}"><i data-lucide="rotate-ccw" size="16"></i>Resetează filtrele</a></div>
            <small id="match-url-help">Dacă introduci un URL, acesta are prioritate față de Echipa 1 și Echipa 2. Ordinea echipelor nu contează; dacă nu găsim partida exactă, afișăm și meciuri din baza de date care conțin cel puțin una dintre echipe.</small>
          </div>
        </form>
        @if($urlError)<p class="matches-url-error" role="alert">{{ $urlError }}</p>@elseif($matchUrl!=='' && $urlExactMatch)<p class="matches-url-result"><i data-lucide="circle-check" size="15"></i>Am găsit potrivirea pentru {{ $team1 }} — {{ $team2 }}. Ordinea gazdă/oaspete poate fi inversată; ID-ul din URL nu este verificat.</p>@elseif($matchUrl!=='' && $urlPartialMatch)<p class="matches-url-result"><i data-lucide="info" size="15"></i>Nu am găsit o potrivire exactă pentru {{ $team1 }} — {{ $team2 }}. Mai jos sunt meciurile din baza de date în care am recunoscut cel puțin una dintre echipe; verifică liga și adversarul.</p>@elseif($matchUrl!=='')<p class="matches-url-error" role="status">Nu am găsit nici potrivirea exactă, nici meciuri cu una dintre echipele {{ $team1 }} — {{ $team2 }} în baza de date.</p>@endif
        <p class="matches-search-note"><i data-lucide="info" size="16"></i>Căutarea include și meciurile deja începute. Rezultatele sunt ordonate cronologic.</p>
    </section>

    <section class="matches-results-card" aria-labelledby="matches-results-title">
        <div class="matches-results-head"><span class="matches-results-icon"><i data-lucide="list-filter" size="20"></i></span><h2 id="matches-results-title">Rezultate <span>({{ $matches->count() }} {{ $matches->count()===1 ? 'meci' : 'meciuri' }})</span></h2></div>
        <table class="matches-table"><thead><tr><th>Dată</th><th>Liga</th><th>Meci</th><th>Recomandare</th><th>Probabilitate model</th><th>Cotă</th><th>Acțiune</th></tr></thead><tbody>
        @forelse($matches as $match)
            @php($rec=$match->recommendations->sortByDesc('score')->first())
            @php($modelProbability=$rec?->model_probability === null ? null : max(0, min(100, $rec->model_probability * 100)))
            @php($betlensPrediction=$match->latestBetlensPrediction)
            @php($predictionHasProbabilities=$betlensPrediction && ($betlensPrediction->home_win_probability!==null || $betlensPrediction->draw_probability!==null || $betlensPrediction->away_win_probability!==null))
            <tr>
                <td data-label="Dată"><div class="matches-date"><span class="matches-date-icon" aria-hidden="true"><i data-lucide="calendar-days" size="18"></i></span><span class="matches-date-detail"><time datetime="{{ $match->kickoff_at->toIso8601String() }}">{{ $match->kickoff_at->format('d.m.Y · H:i') }}</time>@if($match->is_live_window)<span class="match-live-badge" title="Interval live estimat după ora programată; statusul nu este confirmat în timp real."><span class="match-live-dot"></span>Live</span>@endif</span></div></td>
                <td data-label="Liga"><span class="matches-league"><span class="matches-league-logo league-logo--{{ $match->league->slug }}" aria-hidden="true">{{ $match->league->code }}</span><span>{{ $match->league->name }}</span></span></td>
                <td data-label="Meci"><span class="matches-fixture"><span class="matches-fixture-team"><span class="matches-team-logo">@if($match->homeTeam->crest)<img src="{{ $match->homeTeam->crest }}" alt="" loading="lazy" onerror="this.hidden=true">@endif<span class="matches-logo-fallback">{{ mb_substr($match->homeTeam->name,0,1) }}</span></span><span>{{ $match->homeTeam->name }}</span></span><span class="matches-vs">vs</span><span class="matches-fixture-team"><span class="matches-team-logo">@if($match->awayTeam->crest)<img src="{{ $match->awayTeam->crest }}" alt="" loading="lazy" onerror="this.hidden=true">@endif<span class="matches-logo-fallback">{{ mb_substr($match->awayTeam->name,0,1) }}</span></span><span>{{ $match->awayTeam->name }}</span></span></span>@if($urlPartialMatch)<small class="matches-partial-match">Potrivire parțială · {{ $match->url_search_match_count }}/2 echipe</small>@endif</td>
                <td data-label="Recomandare"><span class="matches-rec {{ $rec ? 'is-available' : 'is-unavailable' }}">{{ $rec?->selection ?? 'Indisponibil' }}</span></td>
                <td data-label="Probabilitate model" class="matches-probability">@if($modelProbability !== null)<span class="matches-probability-value">{{ number_format($modelProbability, 1, ',', '.') }}%</span><progress class="matches-probability-bar" max="100" value="{{ $modelProbability }}" aria-label="Probabilitatea modelului pentru {{ $match->homeTeam->name }} - {{ $match->awayTeam->name }}">{{ number_format($modelProbability, 1, ',', '.') }}%</progress>@elseif($predictionHasProbabilities)<div class="matches-probability-outcomes"><span><b>1</b> {{ $betlensPrediction->home_win_probability!==null?number_format($betlensPrediction->home_win_probability*100,1,',','.').'%':'—' }}</span><span><b>X</b> {{ $betlensPrediction->draw_probability!==null?number_format($betlensPrediction->draw_probability*100,1,',','.').'%':'—' }}</span><span><b>2</b> {{ $betlensPrediction->away_win_probability!==null?number_format($betlensPrediction->away_win_probability*100,1,',','.').'%':'—' }}</span></div><small class="matches-probability-source">Estimare BetLens (1/X/2)</small>@else<span class="matches-probability-unavailable">Indisponibil</span>@endif</td>
                <td data-label="Cotă" class="matches-odd">{{ $rec ? number_format($rec->odds,2) : '—' }}</td>
                <td data-label="Acțiune"><a class="btn secondary small matches-analysis" href="{{ route('matches.show',$match) }}" aria-label="Vezi analiza: {{ $match->homeTeam->name }} - {{ $match->awayTeam->name }}"><i data-lucide="chart-no-axes-column" size="17"></i>Vezi analiza<i data-lucide="arrow-right" size="17"></i></a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="matches-empty"><i data-lucide="search-x" size="27"></i><strong>Niciun meci găsit</strong><span>{{ $matchUrl!=='' && !$urlError ? 'Nu există o potrivire exactă sau parțială pentru echipele din URL în rezultatele BetLens. Verifică dacă liga și meciurile ei au fost importate.' : 'Încearcă alte echipe sau selectează o altă ligă.' }}</span></td></tr>
        @endforelse
        </tbody></table>
    </section>
</div>
@endsection
