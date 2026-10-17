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
          </div>
          <div class="matches-search-divider"><span>sau caută după link</span></div>
          <div class="matches-url-search">
            <label for="match-url"><i data-lucide="link" size="16"></i>URL meci Superbet sau Winbet</label>
            <div class="matches-url-controls"><input id="match-url" class="field" type="search" name="match_url" value="{{ $matchUrl }}" placeholder="https://winbet.ro/in-play/echipa-1-vs-echipa-2-ID" autocomplete="off" maxlength="2048" aria-describedby="match-url-help"><button class="btn matches-submit" type="submit"><i data-lucide="search" size="18"></i>Caută meciuri</button></div>
            <small id="match-url-help">Dacă introduci un URL, acesta are prioritate față de echipe. Căutarea respectă ordinea gazdă–oaspeți din link, dar nu verifică ID-ul sau data meciului la operator.</small>
          </div>
        </form>
        @if($urlError)<p class="matches-url-error" role="alert">{{ $urlError }}</p>@elseif($matchUrl!=='')<p class="matches-url-result"><i data-lucide="circle-check" size="15"></i>Căutare din URL: {{ $team1 }} — {{ $team2 }}</p>@endif
        <p class="matches-search-note"><i data-lucide="info" size="16"></i>Căutarea include și meciurile deja începute. Rezultatele sunt ordonate cronologic.</p>
    </section>

    <section class="matches-results-card" aria-labelledby="matches-results-title">
        <div class="matches-results-head"><span class="matches-results-icon"><i data-lucide="list-filter" size="18"></i></span><h2 id="matches-results-title">Rezultate <span>({{ $matches->count() }} {{ $matches->count()===1 ? 'meci' : 'meciuri' }})</span></h2></div>
        <table class="matches-table"><thead><tr><th>Dată</th><th>Liga</th><th>Meci</th><th>Recomandare</th><th>Cotă</th><th>Acțiune</th></tr></thead><tbody>
        @forelse($matches as $match)
            @php($rec=$match->recommendations->sortByDesc('score')->first())
            <tr>
                <td data-label="Dată"><div class="matches-date"><time datetime="{{ $match->kickoff_at->toIso8601String() }}">{{ $match->kickoff_at->format('d.m.Y · H:i') }}</time>@if($match->is_live_window)<span class="match-live-badge" title="Interval live estimat după ora programată; statusul nu este confirmat în timp real."><span class="match-live-dot"></span>Live</span>@endif</div></td>
                <td data-label="Liga"><span class="matches-league"><span class="matches-league-logo league-logo--{{ $match->league->slug }}" aria-hidden="true">{{ $match->league->code }}</span><span>{{ $match->league->name }}</span></span></td>
                <td data-label="Meci"><span class="matches-fixture"><span class="matches-fixture-team"><span class="matches-team-logo">@if($match->homeTeam->crest)<img src="{{ $match->homeTeam->crest }}" alt="" loading="lazy" onerror="this.hidden=true">@endif<span class="matches-logo-fallback">{{ mb_substr($match->homeTeam->name,0,1) }}</span></span><span>{{ $match->homeTeam->name }}</span></span><span class="matches-vs">vs</span><span class="matches-fixture-team"><span class="matches-team-logo">@if($match->awayTeam->crest)<img src="{{ $match->awayTeam->crest }}" alt="" loading="lazy" onerror="this.hidden=true">@endif<span class="matches-logo-fallback">{{ mb_substr($match->awayTeam->name,0,1) }}</span></span><span>{{ $match->awayTeam->name }}</span></span></span></td>
                <td data-label="Recomandare"><span class="matches-rec {{ $rec ? 'is-available' : 'is-unavailable' }}">{{ $rec?->selection ?? 'Indisponibil' }}</span></td>
                <td data-label="Cotă" class="matches-odd">{{ $rec ? number_format($rec->odds,2) : '—' }}</td>
                <td data-label="Acțiune"><a class="btn secondary small matches-analysis" href="{{ route('matches.show',$match) }}" aria-label="Vezi analiza: {{ $match->homeTeam->name }} - {{ $match->awayTeam->name }}"><i data-lucide="chart-no-axes-column" size="15"></i>Vezi analiza<i data-lucide="chevron-right" size="15"></i></a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="matches-empty"><i data-lucide="search-x" size="27"></i><strong>Niciun meci găsit</strong><span>{{ $matchUrl!=='' && !$urlError ? 'Meciul din URL nu există încă în baza BetLens. Verifică dacă liga a fost importată.' : 'Încearcă alte echipe sau selectează o altă ligă.' }}</span></td></tr>
        @endforelse
        </tbody></table>
    </section>
</div>
@endsection
