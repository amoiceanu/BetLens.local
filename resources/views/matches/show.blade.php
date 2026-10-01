@extends('layouts.app')
@section('title',$match->homeTeam->name.' - '.$match->awayTeam->name)
@section('content')
@php
    $plainSelection = function($selection) use ($match) {
        return match($selection) {
            '1' => 'Victoria '.$match->homeTeam->name,
            'X' => 'Meci egal',
            '2' => 'Victoria '.$match->awayTeam->name,
            '1X' => $match->homeTeam->name.' nu pierde',
            'X2' => $match->awayTeam->name.' nu pierde',
            '12' => 'Meciul nu se termină egal',
            'Peste 2.5' => 'Peste 2,5 goluri',
            'Sub 2.5' => 'Sub 2,5 goluri',
            default => $selection,
        };
    };
    $meaning = match($bestRecommendation?->selection) {
        '1' => 'Echipa gazdă trebuie să câștige meciul.',
        'X' => 'Meciul trebuie să se termine la egalitate.',
        '2' => 'Echipa oaspete trebuie să câștige meciul.',
        '1X' => 'Pariul câștigă dacă gazdele câștigă sau meciul se termină egal.',
        'X2' => 'Pariul câștigă dacă oaspeții câștigă sau meciul se termină egal.',
        '12' => 'Pariul câștigă dacă oricare echipă învinge; egalul pierde.',
        'Peste 2.5' => 'Trebuie să se înscrie minimum 3 goluri în total.',
        'Sub 2.5' => 'Trebuie să se înscrie maximum 2 goluri în total.',
        default => 'Consultă explicația modelului înainte de a lua o decizie.',
    };
    $factors=$bestRecommendation?->factors??[];
    $expectedTotal=isset($factors['expected_home_goals'],$factors['expected_away_goals'])?(float)$factors['expected_home_goals']+(float)$factors['expected_away_goals']:null;
    $bookmaker=match($factors['bookmaker']??null){'onexbet'=>'1xBet','pinnacle'=>'Pinnacle','williamhill'=>'William Hill','unibet_nl','unibet_se'=>'Unibet',default=>isset($factors['bookmaker'])?str($factors['bookmaker'])->replace('_',' ')->title():null};
@endphp

<div class="page-head"><div><p class="eyebrow">{{ $match->league->name }} · {{ $match->kickoff_at->format('d M Y, H:i') }}</p><h1>{{ $match->homeTeam->name }} – {{ $match->awayTeam->name }}</h1><p>{{ $match->venue ?: 'Stadion neconfirmat' }}</p></div><div class="match-page-actions">@if($backTicket)<a class="match-ticket-back" href="{{ route('tickets.show',$backTicket) }}"><i data-lucide="arrow-left" size="14"></i>Biletul meu</a>@endif<span class="pill">Date verificate</span></div></div>

@if($bestRecommendation)
<section class="bet-verdict {{ $bestRecommendation->eligible?'recommended':'caution' }}">
    <div class="verdict-copy">
        <div class="verdict-label"><i data-lucide="target" size="17"></i>{{ $bestRecommendation->eligible?'Pariul recomandat':'Cea mai bună opțiune disponibilă' }}</div>
        <h2>{{ $plainSelection($bestRecommendation->selection) }}</h2>
        <p class="verdict-meaning">{{ $meaning }}</p>
        <div class="verdict-source">
            @if($bestRecommendation->model_version==='poisson-fair-v1')
                <span><i data-lucide="calculator" size="15"></i>Cotă echitabilă estimată de model, nu cotă de bookmaker</span>
            @else
                <span><i data-lucide="radio" size="15"></i>Cotă reală{{ $bookmaker?' · '.$bookmaker:'' }}</span>
                @if($bestOdd)<span>Actualizată {{ $bestOdd->captured_at->diffForHumans() }}</span>@endif
            @endif
        </div>
    </div>
    <div class="verdict-odds"><span>Cotă</span><b>{{ number_format($bestRecommendation->odds,2,',','.') }}</b></div>
    <div class="verdict-metrics">
        <div><span>Probabilitate model</span><b>{{ number_format($bestRecommendation->model_probability*100,1,',','.') }}%</b></div>
        <div><span>Piața estimează</span><b>{{ number_format($bestRecommendation->implied_probability*100,1,',','.') }}%</b></div>
        <div><span>Avantaj calculat</span><b class="{{ $bestRecommendation->value>0?'positive':'negative' }}">{{ $bestRecommendation->value>0?'+':'' }}{{ number_format($bestRecommendation->value*100,1,',','.') }} pp</b></div>
        <div><span>Încredere</span><b>{{ $bestRecommendation->confidence }} · {{ $bestRecommendation->score }}/100</b></div>
    </div>
</section>

<div class="decision-grid">
    <section class="card"><p class="eyebrow">De ce acest pariu</p><h2>Argumentele principale</h2><ul class="reason-list">
        @if($expectedTotal!==null)<li><i data-lucide="activity" size="18"></i><span>Modelul estimează <b>{{ number_format($expectedTotal,2,',','.') }} goluri</b> în total ({{ number_format($factors['expected_home_goals'],2,',','.') }} gazde + {{ number_format($factors['expected_away_goals'],2,',','.') }} oaspeți).</span></li>@endif
        <li><i data-lucide="database" size="18"></i><span>Calculul folosește <b>{{ $factors['history_matches']??0 }} rezultate reale</b> din {{ $match->league->name }}.</span></li>
        @if($bestRecommendation->value>0)<li><i data-lucide="trending-up" size="18"></i><span>Modelul este cu <b>{{ number_format($bestRecommendation->value*100,1,',','.') }} puncte procentuale</b> peste probabilitatea implicită a cotei.</span></li>@endif
    </ul></section>
    <aside class="card decision-box"><p class="eyebrow">Pe scurt</p><div class="decision-line"><span>Selecție</span><b>{{ $bestRecommendation->selection }}</b></div><div class="decision-line"><span>Verdict</span><b class="positive">{{ $bestRecommendation->eligible?'Eligibilă pentru bilet':'Nu trece toate pragurile' }}</b></div><div class="notice"><i data-lucide="shield-alert" size="18"></i><span>Este o estimare statistică, nu o garanție. Verifică dacă oferta bookmakerului este încă disponibilă înainte de plasare.</span></div></aside>
</div>
@else
<section class="card empty">Nu există încă o analiză disponibilă pentru acest meci.</section>
@endif

<div class="two-col analysis-columns"><div><section class="card"><div class="section-head compact"><h2>Forma echipelor</h2></div><table><tr><th>Echipă</th><th>Ultimele 5</th><th>GM</th><th>GP</th><th>xG</th><th>xGA</th></tr>@foreach([[$match->homeTeam,$homeStats],[$match->awayTeam,$awayStats]] as [$team,$stats])<tr><td><b>{{ $team->name }}</b></td><td><div class="form-row">@forelse($stats['form'] as $f)<span class="form-dot {{ $f==='L'?'loss':'' }}">{{ $f }}</span>@empty<span class="muted">—</span>@endforelse</div></td><td>{{ $stats['scored'] }}</td><td>{{ $stats['conceded'] }}</td><td>{{ $stats['xg'] }}</td><td>{{ $stats['xga'] }}</td></tr>@endforeach</table></section></div>
<aside class="card"><p class="eyebrow">Alternative analizate</p><h2>Ce nu recomandăm prioritar</h2><div class="alternative-list">@forelse($alternatives->take(5) as $rec)<div class="alternative"><div><b>{{ $plainSelection($rec->selection) }}</b><small>{{ $rec->market->name }}</small></div><div><b>{{ number_format($rec->odds,2,',','.') }}</b><small class="{{ $rec->value>0?'positive':'negative' }}">{{ $rec->value>0?'+':'' }}{{ number_format($rec->value*100,1,',','.') }} pp</small></div></div>@empty<p class="muted">Nu există alte piețe analizate.</p>@endforelse</div></aside></div>
@endsection
