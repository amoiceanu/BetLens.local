@extends('layouts.app')
@section('title',$ticket->reference)
@section('content')
@php($modelOnly=$ticket->selections->contains(fn($s)=>$s->recommendation->model_version==='poisson-fair-v1'))

<div class="ticket-detail-page">
    <div class="page-head ticket-detail-head">
        <div class="ticket-title-block">
            <nav class="ticket-breadcrumb" aria-label="Navigare secundară">
                <a href="{{ route('tickets') }}">Istoric</a><span aria-hidden="true">/</span><span aria-current="page">Detalii bilet</span>
            </nav>
            <div class="ticket-title-row">
                <h1>Rezumat bilet</h1>
                <span class="ticket-risk-badge">{{ ucfirst($ticket->risk_profile) }}</span>
            </div>
            <p class="ticket-title-meta"><span title="{{ $ticket->reference }}">{{ $ticket->reference }}</span><span aria-hidden="true">·</span><time datetime="{{ $ticket->created_at->toIso8601String() }}">Creat la {{ $ticket->created_at->format('d M Y, H:i') }}</time></p>
        </div>
        <a href="{{ route('tickets') }}" class="btn secondary ticket-history-button"><i data-lucide="arrow-left" size="16"></i>Înapoi la istoric</a>
    </div>

    <section class="card ticket-summary-card">
            <div class="ticket-summary-metric">
                <span class="ticket-metric-icon"><i data-lucide="trending-up" size="18"></i></span>
                <span class="ticket-metric-copy"><span>{{ $modelOnly?'Cotă echitabilă totală':'Cotă totală' }}</span><b>{{ number_format($ticket->total_odds,2,',','.') }}</b></span>
            </div>
            <div class="ticket-summary-metric">
                <span class="ticket-metric-icon"><i data-lucide="percent" size="18"></i></span>
                <span class="ticket-metric-copy"><span>Probabilitate combinată</span><b>{{ number_format($ticket->combined_probability*100,2,',','.') }}%</b></span>
            </div>
            <div class="ticket-summary-metric">
                <span class="ticket-metric-icon"><i data-lucide="list-checks" size="18"></i></span>
                <span class="ticket-metric-copy"><span>Selecții</span><b>{{ $ticket->selections->count() }}</b></span>
            </div>
        <form class="ticket-summary-controls" method="post" action="{{ route('tickets.update',$ticket) }}">
            @csrf @method('PATCH')
            <div class="ticket-reference-form ticket-summary-control">
                <label for="ticket-reference">Referință bilet</label>
                <input id="ticket-reference" class="field" name="reference" value="{{ old('reference',$ticket->reference) }}" maxlength="100" required autocomplete="off">
            </div>
            <div class="ticket-status-form ticket-summary-control">
                <label for="ticket-status">Status</label>
                <select id="ticket-status" class="field ticket-status-field" name="status">
                    <option value="pending" @selected($ticket->status==='pending')>În așteptare</option>
                    @foreach($operators as $operator)<option value="placed:{{ $operator->id }}" @selected($ticket->status==='placed' && $ticket->operator_id===$operator->id)>Plasat pe {{ $operator->name }}</option>@endforeach
                    <option value="won" @selected($ticket->status==='won')>Câștigat</option>
                    <option value="lost" @selected($ticket->status==='lost')>Pierdut</option>
                    <option value="void" @selected($ticket->status==='void')>Anulat</option>
                </select>
            </div>
            <div class="ticket-match-window-form ticket-summary-control">
                <span class="ticket-control-label" id="match-window-label">Intervalul meciurilor</span>
                <div class="ticket-match-window-fields" aria-labelledby="match-window-label">
                    <input class="field" type="datetime-local" name="first_match_at" value="{{ old('first_match_at',$ticket->effective_first_match_at?->format('Y-m-d\TH:i')) }}" aria-label="Primul meci">
                    <input class="field" type="datetime-local" name="last_match_at" value="{{ old('last_match_at',$ticket->effective_last_match_at?->format('Y-m-d\TH:i')) }}" aria-label="Ultimul meci">
                </div>
            </div>
            <button class="btn ticket-save-button" type="submit"><i data-lucide="save" size="17"></i>Salvează</button>
        </form>
    </section>
    <aside class="ticket-responsible-row" aria-label="Avertisment joc responsabil">
        <i data-lucide="shield-alert" size="17"></i>
        <span>@if($modelOnly)Cotele sunt estimări BetLens calculate din rezultate reale, nu oferte ale unui bookmaker. @endif Riscul este {{ $ticket->risk_profile==='conservator'?'moderat':($ticket->risk_profile==='echilibrat'?'ridicat':'foarte ridicat') }}. Rezultatul nu este garantat. Joacă responsabil.</span>
    </aside>

    <div class="ticket-selection-grid">
        @foreach($ticket->selections as $selection)
            @php($r=$selection->recommendation)
            @php($winbet=$r->match->providerMappings->firstWhere('provider','winbet'))
            @php($winbetUrl=$winbet?'https://winbet.ro/sports/event/'.\Illuminate\Support\Str::slug($winbet->home_name.'-vs-'.$winbet->away_name).'-'.$winbet->external_id:'https://winbet.ro/sports/soccer-1001?tab=0')
            <article class="match-card ticket-selection-card">
                <div class="match-meta"><span>{{ $r->match->league->name }}</span><span>{{ $r->match->kickoff_at->format('d M · H:i') }}</span></div>
                <div class="teams">{{ $r->match->homeTeam->name }} — {{ $r->match->awayTeam->name }}</div>
                <div class="rec-row"><span class="score">{{ $r->confidence }}</span><span class="market">{{ $r->selection }}</span><b class="odds">{{ number_format($r->odds,2,',','.') }}</b></div>
                <p class="ticket-explanation">{{ $r->explanation }}</p>

                <div class="ticket-card-footer">
                    <a class="btn winbet-link" href="{{ $winbetUrl }}" target="_blank" rel="noopener noreferrer"><i data-lucide="external-link" size="16"></i>{{ $winbet?'Deschide meciul pe Winbet':'Caută meciul pe Winbet' }}</a>
                    @if(!$winbet)<small class="winbet-note">Evenimentul exact nu este încă publicat în oferta Winbet.</small>@endif
                    <div class="ticket-card-actions">
                        <a class="btn secondary small" href="{{ route('matches.show',['match'=>$r->match,'ticket'=>$ticket->id]) }}">Vezi analiza</a>
                        <form method="post" action="{{ route('selections.replace',$selection) }}">@csrf<button class="btn secondary small">Înlocuiește</button></form>
                        <form method="post" action="{{ route('selections.destroy',$selection) }}">@csrf @method('DELETE')<button class="btn danger small" aria-label="Elimină"><i data-lucide="trash-2" size="14"></i></button></form>
                    </div>
                </div>
            </article>
        @endforeach
    </div>
</div>
@endsection

@push('scripts')
<script>try{const saved=JSON.parse(localStorage.getItem('betlens_tickets')||'[]');const current={reference:@json($ticket->reference),profile:@json($ticket->risk_profile),odds:{{ $ticket->total_odds }},probability:{{ $ticket->combined_probability }},createdAt:@json($ticket->created_at->toIso8601String())};const next=[current,...saved.filter(t=>t.reference!==current.reference)].slice(0,50);localStorage.setItem('betlens_tickets',JSON.stringify(next));}catch(e){console.warn('Local ticket storage unavailable',e)}</script>
@endpush
