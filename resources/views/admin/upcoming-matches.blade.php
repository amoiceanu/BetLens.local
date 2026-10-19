@extends('layouts.app')
@section('title', 'Meciuri viitoare')
@section('content')
<div class="upcoming-admin">
    <header class="page-head">
        <div><p class="eyebrow">Administrare · meciuri și estimări</p><h1>Meciuri viitoare</h1><p>Două rânduri pe meci: rezultat real și ultima estimare BetLens. Valorile lipsă rămân necompletate.</p></div>
        <form method="post" action="{{ route('admin.upcoming-matches.import') }}" data-upcoming-import>@csrf
            <input type="hidden" name="days" value="30">
            <button class="btn" type="submit">Importă următoarele 30 zile</button>
        </form>
    </header>
    <p class="upcoming-note">Importul folosește OpenLigaDB pentru ligile configurate (Premier League, La Liga, Bundesliga, Champions League). Cotele BetLens sunt cote echitabile calculate din istoricul meciurilor, nu oferte ale unui operator. Sunt disponibile doar pentru 1/X/2 când există istoric suficient.</p>
    <form class="card upcoming-filters" method="get" action="{{ route('admin.upcoming-matches') }}">
        <label>Liga<select name="league"><option value="">Toate ligile</option>@foreach($leagues as $league)<option value="{{ $league->id }}" @selected(($filters['league'] ?? '') == $league->id)>{{ $league->name }}</option>@endforeach</select></label>
        <label>Echipa<input name="team" value="{{ $filters['team'] ?? '' }}" placeholder="Numele echipei"></label>
        <label>De la<input type="date" name="from" value="{{ $from }}"></label>
        <label>Până la<input type="date" name="to" value="{{ $to }}"></label>
        <button class="btn" type="submit">Filtrează</button><a class="btn secondary" href="{{ route('admin.upcoming-matches') }}">Resetează</a>
    </form>
    <section class="card upcoming-table-card" aria-label="Rezultate reale și cote BetLens">
        <div class="upcoming-table-head"><h2>{{ $matches->total() }} meciuri</h2><small>Scorul și statisticile reale apar după actualizarea rezultatului de către sursă.</small></div>
        <div class="upcoming-scroll"><table>
            <thead><tr><th>Data · Liga · Meci</th><th>Tip</th><th>1</th><th>X</th><th>2</th><th>Goluri</th><th>Pauză</th><th>Rep. 2</th><th>Cornere</th><th>Cornere pauză</th><th>Cornere rep. 2</th></tr></thead>
            <tbody>
            @forelse($matches as $match)
                @php($actual = $match->actualResult)
                @php($prediction = $match->latestBetlensPrediction)
                @php($outcome = $actual && $actual->home_score !== null && $actual->away_score !== null ? ($actual->home_score > $actual->away_score ? '1' : ($actual->home_score < $actual->away_score ? '2' : 'X')) : null)
                <tr class="upcoming-actual">
                    <th rowspan="2" scope="rowgroup"><a href="{{ route('matches.show', $match) }}">{{ $match->homeTeam?->name ?? '—' }} – {{ $match->awayTeam?->name ?? '—' }}</a><small>{{ $match->league?->name ?? '—' }} · {{ $match->kickoff_at?->format('d.m.Y H:i') }}</small><small>{{ $actual?->match_status ?? $match->status }}</small></th>
                    <th scope="row">Rezultat real</th>
                    <td>{{ $outcome === '1' ? '✓' : '—' }}</td><td>{{ $outcome === 'X' ? '✓' : '—' }}</td><td>{{ $outcome === '2' ? '✓' : '—' }}</td>
                    <td>{{ $actual?->home_score !== null && $actual?->away_score !== null ? $actual->home_score.' : '.$actual->away_score : '—' }}</td>
                    <td>{{ $actual?->home_score_ht !== null && $actual?->away_score_ht !== null ? $actual->home_score_ht.' : '.$actual->away_score_ht : '—' }}</td>
                    <td>{{ $actual?->home_score_2h !== null && $actual?->away_score_2h !== null ? $actual->home_score_2h.' : '.$actual->away_score_2h : '—' }}</td>
                    <td>{{ $actual?->home_corners !== null && $actual?->away_corners !== null ? $actual->home_corners.' : '.$actual->away_corners : '—' }}</td>
                    <td>{{ $actual?->home_corners_ht !== null && $actual?->away_corners_ht !== null ? $actual->home_corners_ht.' : '.$actual->away_corners_ht : '—' }}</td>
                    <td>{{ $actual?->home_corners_2h !== null && $actual?->away_corners_2h !== null ? $actual->home_corners_2h.' : '.$actual->away_corners_2h : '—' }}</td>
                </tr>
                <tr class="upcoming-prediction">
                    <th scope="row">BetLens <small>{{ $prediction?->predicted_at?->format('d.m H:i') ?? 'fără estimare' }}</small></th>
                    <td>{{ $prediction?->home_win_odds !== null ? number_format($prediction->home_win_odds, 2) : '—' }}</td>
                    <td>{{ $prediction?->draw_odds !== null ? number_format($prediction->draw_odds, 2) : '—' }}</td>
                    <td>{{ $prediction?->away_win_odds !== null ? number_format($prediction->away_win_odds, 2) : '—' }}</td>
                    <td>{{ $prediction?->home_score !== null && $prediction?->away_score !== null ? $prediction->home_score.' : '.$prediction->away_score : '—' }}</td>
                    <td>{{ $prediction?->home_score_ht !== null && $prediction?->away_score_ht !== null ? $prediction->home_score_ht.' : '.$prediction->away_score_ht : '—' }}</td>
                    <td>{{ $prediction?->home_score_2h !== null && $prediction?->away_score_2h !== null ? $prediction->home_score_2h.' : '.$prediction->away_score_2h : '—' }}</td>
                    <td>{{ $prediction?->home_corners !== null && $prediction?->away_corners !== null ? $prediction->home_corners.' : '.$prediction->away_corners : '—' }}</td>
                    <td>{{ $prediction?->home_corners_ht !== null && $prediction?->away_corners_ht !== null ? $prediction->home_corners_ht.' : '.$prediction->away_corners_ht : '—' }}</td>
                    <td>{{ $prediction?->home_corners_2h !== null && $prediction?->away_corners_2h !== null ? $prediction->home_corners_2h.' : '.$prediction->away_corners_2h : '—' }}</td>
                </tr>
            @empty<tr><td colspan="11">Nu există meciuri pentru filtrele selectate. Poți lansa importul sau extinde perioada.</td></tr>@endforelse
            </tbody>
        </table></div>
        @if($matches->hasPages())
            <nav class="upcoming-pagination" aria-label="Paginarea meciurilor viitoare">
                <span class="upcoming-pagination-summary">Meciurile {{ $matches->firstItem() }}–{{ $matches->lastItem() }} din {{ $matches->total() }}</span>
                <div class="upcoming-pagination-links">
                    @if($matches->onFirstPage())
                        <span class="upcoming-page-link is-disabled" aria-disabled="true">Înapoi</span>
                    @else
                        <a class="upcoming-page-link" href="{{ $matches->previousPageUrl() }}" rel="prev">Înapoi</a>
                    @endif

                    @foreach($matches->getUrlRange(max(1, $matches->currentPage() - 2), min($matches->lastPage(), $matches->currentPage() + 2)) as $page => $url)
                        @if($page === $matches->currentPage())
                            <span class="upcoming-page-link is-current" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="upcoming-page-link" href="{{ $url }}" aria-label="Pagina {{ $page }}">{{ $page }}</a>
                        @endif
                    @endforeach

                    @if($matches->hasMorePages())
                        <a class="upcoming-page-link" href="{{ $matches->nextPageUrl() }}" rel="next">Înainte</a>
                    @else
                        <span class="upcoming-page-link is-disabled" aria-disabled="true">Înainte</span>
                    @endif
                </div>
            </nav>
        @endif
    </section>
</div>
<div class="upcoming-import-backdrop" id="upcoming-import-modal" hidden>
    <section class="upcoming-import-dialog" role="dialog" aria-modal="true" aria-labelledby="upcoming-import-title" tabindex="-1">
        <button type="button" class="upcoming-import-close" data-upcoming-close aria-label="Închide">×</button>
        <header class="upcoming-import-header">
            <div class="upcoming-import-hero-icon" aria-hidden="true"><svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M17 45H14a11 11 0 0 1-2-22 19 19 0 0 1 37-1 12 12 0 0 1 2 23h-3"/><path d="M32 30v24m-9-9 9 9 9-9"/></svg></div>
            <div><p class="upcoming-import-eyebrow">Import meciuri viitoare</p><h2 id="upcoming-import-title">Progresul importului</h2><p class="upcoming-import-subtitle">Urmărește etapele și datele procesate în timp real.</p></div>
        </header>
        <div class="upcoming-import-stages" aria-label="Etapele importului">
            <div data-import-stage="queued" class="is-active"><span class="upcoming-import-step-number">1</span><span class="upcoming-import-step-label">În coadă</span></div>
            <div data-import-stage="connecting"><span class="upcoming-import-step-number">2</span><span class="upcoming-import-step-label">Conectare</span></div>
            <div data-import-stage="writing"><span class="upcoming-import-step-number">3</span><span class="upcoming-import-step-label">Preluare și scriere</span></div>
            <div data-import-stage="finished"><span class="upcoming-import-step-number">4</span><span class="upcoming-import-step-label">Finalizat</span></div>
        </div>
        <div class="upcoming-import-status" role="status"><span class="upcoming-import-status-icon" aria-hidden="true">⌛</span><div><small>Status actual</small><strong id="upcoming-import-message">Importul se pregătește…</strong><span id="upcoming-import-status-detail">Un worker Laravel va procesa importul în curând.</span></div></div>
        <div class="upcoming-import-leagues">
            <div><span class="upcoming-import-league-icon" aria-hidden="true">⚽</span><p><span>Ligă curentă</span><strong id="upcoming-import-league">—</strong><small id="upcoming-import-league-detail">Nu a fost încă selectată o ligă.</small></p></div>
            <div><span class="upcoming-import-league-icon" aria-hidden="true">▱</span><p><span>Ligi procesate</span><strong id="upcoming-import-leagues">0</strong><small>din totalul selectat</small></p></div>
        </div>
        <div class="upcoming-import-grid">
            <div class="metric-read"><span aria-hidden="true">▤</span><p><small>Meciuri citite</small><strong id="upcoming-import-read">0</strong></p></div>
            <div class="metric-new"><span aria-hidden="true">✚</span><p><small>Meciuri noi</small><strong id="upcoming-import-new">0</strong></p></div>
            <div class="metric-updated"><span aria-hidden="true">↻</span><p><small>Actualizate</small><strong id="upcoming-import-updated">0</strong></p></div>
            <div class="metric-unchanged"><span aria-hidden="true">⇄</span><p><small>Neschimbate</small><strong id="upcoming-import-unchanged">0</strong></p></div>
            <div class="metric-predictions"><span aria-hidden="true">▥</span><p><small>Estimări noi</small><strong id="upcoming-import-predictions">0</strong></p></div>
            <div class="metric-errors"><span aria-hidden="true">⚠</span><p><small>Erori</small><strong id="upcoming-import-errors">0</strong></p></div>
        </div>
        <p id="upcoming-import-error-list" class="upcoming-import-errors" hidden></p>
        <p class="upcoming-import-help"><span aria-hidden="true">ⓘ</span><span>Importul continuă în fundal dacă închizi această fereastră.<br>Necesită un worker Laravel pentru coada „default”.</span></p>
    </section>
</div>
@endsection
