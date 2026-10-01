@extends('layouts.app')
@section('title',$ticket->reference)
@section('content')
@php($modelOnly=$ticket->selections->contains(fn($s)=>$s->recommendation->model_version==='poisson-fair-v1'))

<div class="ticket-detail-page" x-data="{copied:false}">
    <div class="page-head">
        <div>
            <p class="eyebrow">Bilet generat · {{ ucfirst($ticket->risk_profile) }}</p>
            <h1>{{ $ticket->reference }}</h1>
            <p>{{ $ticket->created_at->format('d M Y, H:i') }} · Date din surse externe</p>
        </div>
        <a href="{{ route('tickets') }}" class="btn secondary">Înapoi la istoric</a>
    </div>

    <section class="card ticket-summary-card">
        <div class="ticket-summary-main">
            <div class="ticket-summary-heading">
                <p class="eyebrow">Biletul meu</p>
                <h2>Rezumat bilet</h2>
                <form class="ticket-reference-form" method="post" action="{{ route('tickets.update',$ticket) }}">
                    @csrf @method('PATCH')
                    <label for="ticket-reference">Referința mea / Winbet</label>
                    <div class="ticket-reference-control">
                        <input id="ticket-reference" class="field" name="reference" value="{{ old('reference',$ticket->reference) }}" maxlength="100" required>
                        <button class="btn secondary" type="submit" aria-label="Salvează referința" title="Salvează referința"><i data-lucide="check" size="15"></i></button>
                    </div>
                </form>
                <form class="ticket-status-form" method="post" action="{{ route('tickets.update',$ticket) }}">
                    @csrf @method('PATCH')
                    <select class="field ticket-status-field" name="status" onchange="this.form.submit()" aria-label="Status bilet">
                        <option value="pending" @selected($ticket->status==='pending')>În așteptare</option>
                        <option value="placed_winbet" @selected($ticket->status==='placed_winbet')>Plasat pe Winbet</option>
                        <option value="won" @selected($ticket->status==='won')>Câștigat</option>
                        <option value="lost" @selected($ticket->status==='lost')>Pierdut</option>
                        <option value="void" @selected($ticket->status==='void')>Anulat</option>
                    </select>
                </form>
            </div>
            <div class="ticket-summary-metric">
                <span>{{ $modelOnly?'Cotă echitabilă totală':'Cotă totală' }}</span>
                <b>{{ number_format($ticket->total_odds,2,',','.') }}</b>
            </div>
            <div class="ticket-summary-metric">
                <span>Probabilitate combinată</span>
                <b>{{ number_format($ticket->combined_probability*100,2,',','.') }}%</b>
            </div>
            <div class="ticket-summary-metric">
                <span>Număr selecții</span>
                <b>{{ $ticket->selections->count() }}</b>
            </div>
            <button class="btn ticket-copy-button" type="button" @click="navigator.clipboard.writeText(document.querySelector('#ticketText').value);copied=true">
                <i data-lucide="copy" size="17"></i><span x-text="copied?'Copiat!':'Copiază selecțiile'">Copiază selecțiile</span>
            </button>
        </div>
        <div class="ticket-responsible-row">
            <i data-lucide="shield-alert" size="17"></i>
            <span>@if($modelOnly)Cotele sunt estimări BetLens calculate din rezultate reale, nu oferte ale unui bookmaker. @endif Riscul este {{ $ticket->risk_profile==='conservator'?'moderat':($ticket->risk_profile==='echilibrat'?'ridicat':'foarte ridicat') }}. Rezultatul nu este garantat. Joacă responsabil.</span>
        </div>
        <textarea id="ticketText" hidden>@foreach($ticket->selections as $s){{ $s->recommendation->match->league->name }}
{{ $s->recommendation->match->homeTeam->name }} – {{ $s->recommendation->match->awayTeam->name }} | {{ $s->recommendation->selection }} | cotă {{ number_format($s->recommendation->odds,2) }}
@endforeach Cotă totală: {{ number_format($ticket->total_odds,2) }}</textarea>
    </section>

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
