@extends('layouts.app')
@section('title','Bilet importat '.$ticket->reference)
@section('content')
@php
    $state=in_array($ticket->status,['won','lost','pending'],true)?$ticket->status:'unknown';
    $stateIcon=match($state){'won'=>'circle-check','lost'=>'circle-x','pending'=>'clock-3',default=>'circle-help'};
    $potential=$state==='pending' && $ticket->stake!==null && $ticket->total_odds!==null ? $ticket->stake*$ticket->total_odds : null;
@endphp
<div class="imported-dashboard">
    <div class="imported-dashboard-top">
        <nav class="imported-breadcrumb" aria-label="Navigare bilet"><a href="{{ route('tickets') }}">Biletele mele</a><i data-lucide="chevron-right" size="13" aria-hidden="true"></i><span>Import SuperBet</span><i data-lucide="chevron-right" size="13" aria-hidden="true"></i><span>Bilet</span></nav>
        <a class="btn secondary imported-back" href="{{ route('tickets') }}"><i data-lucide="rotate-ccw" size="16"></i>Înapoi la istoric</a>
    </div>
    <div class="imported-dashboard-grid">
        <div class="imported-main-column">
            <section class="card imported-overview" aria-labelledby="imported-ticket-title">
                <div class="imported-overview-head">
                    <span class="imported-state-icon {{ $state }}"><i data-lucide="{{ $stateIcon }}" size="23"></i></span>
                    <div class="imported-overview-title"><h1 id="imported-ticket-title">Bilet #{{ $ticket->reference }}</h1><p>Plasat la {{ $ticket->placed_at?->format('d.m.Y · H:i') ?? 'dată indisponibilă' }} <span class="imported-source-pill"><i data-lucide="download" size="13"></i>Importat din SuperBet</span></p></div>
                    <span class="imported-state-badge {{ $state }}"><i data-lucide="{{ $stateIcon }}" size="17"></i>{{ $ticket->status_label }}</span>
                </div>
                <div class="imported-overview-metrics">
                    <div class="imported-overview-metric is-odds"><span class="imported-metric-icon"><i data-lucide="chart-no-axes-column" size="20"></i></span><div><span>Cotă totală</span><b>{{ $ticket->total_odds===null?'Nedisponibil':number_format($ticket->total_odds,2) }}</b></div></div>
                    <div class="imported-overview-metric is-stake"><span class="imported-metric-icon"><i data-lucide="coins" size="20"></i></span><div><span>Miză</span><b>{{ $ticket->stake===null?'Nedisponibil':number_format($ticket->stake,2).' '.$ticket->currency }}</b></div></div>
                    <div class="imported-overview-metric is-payout"><span class="imported-metric-icon"><i data-lucide="trophy" size="20"></i></span><div><span>{{ $potential!==null?'Câștig potențial':'Plată' }}</span><b>{{ $ticket->payout!==null?number_format($ticket->payout,2).' '.$ticket->currency:($potential!==null?number_format($potential,2).' '.$ticket->currency:'—') }}</b></div></div>
                </div>
            </section>
            <div class="imported-match-list">@foreach($selectionCards as $card)
                @include('tickets.partials.imported-match-card', ['card'=>$card])
            @endforeach</div>
            <p class="imported-data-note"><i data-lucide="info" size="15"></i>Date transcrise din capturile SuperBet furnizate. BetLens nu a verificat independent decontarea.</p>
        </div>
        @include('tickets.partials.imported-insights', ['ticket'=>$ticket])
    </div>
</div>
@endsection
