@extends('layouts.app') @section('title','Dashboard') @section('content')
<section class="hero"><div><p class="eyebrow">Inteligență pentru fotbal</p><h1>Găsește selecții bazate pe <span>date</span>, nu pe impuls.</h1></div><div><p class="hero-copy">Transformăm forma, golurile și contextul fiecărui meci în estimări clare și explicabile.</p><div class="stats-strip"><div class="stat-mini"><b>{{ $leagues->sum('upcoming_matches_count') }}</b><span>meciuri viitoare</span></div><div class="stat-mini"><b>{{ $leagues->where('upcoming_matches_count','>',0)->count() }}</b><span>competiții cu date</span></div></div></div></section>
<form method="post" action="{{ route('generate') }}" x-data="{league:'{{ old('league_id','') }}',profile:'{{ old('profile','echilibrat') }}'}">@csrf
<div class="section-head"><div><p class="eyebrow">Pasul 01</p><h2>Alege liga</h2><p>Analizează o singură competiție sau întregul univers BetLens.</p></div></div>
<div class="league-grid"><label class="choice"><input type="radio" name="league_id" value="" x-model="league"><span class="league-card"><span class="league-logo league-logo--all">ALL</span><span><b>Toate ligile</b><small>{{ $leagues->sum('upcoming_matches_count') }} meciuri</small></span></span></label>@foreach($leagues as $league)<label class="choice"><input type="radio" name="league_id" value="{{ $league->id }}" x-model="league"><span class="league-card"><span class="league-logo league-logo--{{ $league->slug }}">{{ $league->code }}</span><span><b>{{ $league->name }}</b><small>{{ $league->upcoming_matches_count }} meciuri</small></span></span></label>@endforeach</div>
<div class="section-head"><div><p class="eyebrow">Pasul 02</p><h2>Alege profilul biletului</h2><p>Pragurile modelelor se adaptează nivelului de risc.</p></div></div>
<div class="risk-grid">
<label class="choice"><input type="radio" name="profile" value="conservator" x-model="profile"><span class="risk-card"><span class="risk-top"><h3>Conservator</h3><span class="risk-count">2–3 selecții</span></span><p>Prioritizează selecții cu probabilitate estimată mai mare.</p></span></label>
<label class="choice"><input type="radio" name="profile" value="echilibrat" x-model="profile"><span class="risk-card"><span class="risk-top"><h3>Echilibrat</h3><span class="risk-count">5–8 selecții</span></span><p>Echilibru între cotă totală și probabilitate.</p></span></label>
<label class="choice"><input type="radio" name="profile" value="agresiv" x-model="profile"><span class="risk-card"><span class="risk-top"><h3>Agresiv</h3><span class="risk-count">10–15 selecții</span></span><p>Cotă potențială mai mare, cu risc semnificativ mai ridicat.</p></span></label></div>
<div class="notice"><i data-lucide="triangle-alert" size="18"></i><span>Un număr mai mare de selecții reduce probabilitatea ca biletul complet să fie câștigător. Estimările nu garantează rezultatul.</span></div><div class="actions"><button class="btn" type="submit"><i data-lucide="sparkles" size="18"></i>Generează biletul</button><span class="muted actions-note">Folosește exclusiv meciuri viitoare eligibile</span></div></form>
<div class="section-head section-head-spaced"><div><p class="eyebrow">Date live</p><h2>{{ $recommendations->isNotEmpty() ? 'Selecții cu cel mai mare scor' : 'Următoarele meciuri importate' }}</h2></div><a class="muted" href="{{ route('matches') }}">Vezi toate →</a></div>
<div class="match-grid">
@forelse($recommendations->take(4) as $rec)
<article class="match-card"><div class="match-meta"><span>{{ $rec->match->league->name }}</span><span>{{ $rec->match->kickoff_at->format('d M · H:i') }}</span></div><div class="teams">{{ $rec->match->homeTeam->name }} — {{ $rec->match->awayTeam->name }}</div><div class="rec-row"><span class="score">{{ $rec->confidence }}</span><span class="market">{{ $rec->selection }}</span><b class="odds">{{ number_format($rec->odds,2) }}</b></div></article>
@empty
@foreach($upcomingMatches->take(4) as $match)
<article class="match-card"><div class="match-meta"><span>{{ $match->league->name }}</span><span>{{ $match->kickoff_at->format('d M · H:i') }}</span></div><div class="teams">{{ $match->homeTeam->name }} — {{ $match->awayTeam->name }}</div><div class="rec-row"><span class="market">Meci programat</span><a class="muted" href="{{ route('matches.show',$match) }}">Detalii →</a></div></article>
@endforeach
@endforelse
</div>
@endsection
