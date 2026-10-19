@extends('layouts.app')
@section('title', 'BetLens – Simulator')
@section('content')
<div class="simulator-page">
    <header class="simulator-heading">
        <div><p class="simulator-eyebrow">ADMIN / ANALIZĂ</p><h1>Simulator</h1><p>Urmărește cum se schimbă șansele 1 / X / 2 când activezi sau excluzi un nivel de date.</p></div>
        <span class="simulator-db {{ $connected ? 'is-online' : 'is-offline' }}">{{ $database['label'] }}</span>
    </header>

    @if($warning)<div class="simulator-warning" role="alert">{{ $warning }}</div>@endif
    @if($connected)
    <form method="get" action="{{ route('admin.simulator') }}" class="simulator-form">
        <label for="simulator-q">Caută meci (echipă sau ID)</label>
        <div class="simulator-search"><input id="simulator-q" name="q" type="search" value="{{ $search }}" placeholder="Ex.: Bayern"><button type="submit" id="simulator-search-button">Caută</button></div>
        <label for="simulator-match">Meci</label>
        <select id="simulator-match" name="match_id">
            <option value="">Selectează un meci</option>
            @foreach($matches as $candidate)
                <option value="{{ $candidate->id }}" @selected($match?->id === $candidate->id)>{{ $candidate->kickoff_at?->format('d.m.Y H:i') }} · {{ $candidate->homeTeam?->name }} – {{ $candidate->awayTeam?->name }} · {{ $candidate->league?->name }} (#{{ $candidate->id }})</option>
            @endforeach
        </select>
        <fieldset class="simulator-levels"><legend>Niveluri incluse</legend>
            @foreach(\App\Services\Mindmap\SourceCatalog::LEVELS as $number => $level)
                @php($available = $availability[$number] ?? false)
                <label class="simulator-level {{ !$available ? 'is-unavailable' : '' }}"><input type="checkbox" name="levels[]" value="{{ $number }}" @checked($available && in_array($number, $enabled)) @disabled(!$available)><span><strong>L{{ $number }} · {{ $level['name'] }}</strong><small>{{ $available ? $level['description'] : 'Nu sunt date pentru meciul selectat' }}</small></span></label>
            @endforeach
        </fieldset>
        <input type="hidden" name="levels_submitted" value="1">
        <button type="submit" class="simulator-run">Calculează scenariul</button>
    </form>

    @if($match && $simulation)
        <section class="simulator-results">
            <h2>{{ $match->homeTeam?->name }} – {{ $match->awayTeam?->name }}</h2>
            <p>{{ $match->league?->name }} · {{ $match->kickoff_at?->format('d.m.Y H:i') }} · #{{ $match->id }}</p>
            <div class="simulator-comparison">
                @foreach(['Scenariul selectat' => $simulation['final'], 'Toate nivelurile' => $reference['final']] as $title => $probabilities)
                    <div class="simulator-result-card"><h3>{{ $title }}</h3>
                        @if($probabilities)
                            <div class="simulator-probabilities">
                                @foreach(['1' => $match->homeTeam?->name, 'X' => 'Egal', '2' => $match->awayTeam?->name] as $outcome => $label)
                                    <div><span>{{ $outcome }} · {{ $label }}</span><strong>{{ number_format($probabilities[$outcome], 1, ',', '.') }}%</strong><meter min="0" max="100" value="{{ $probabilities[$outcome] }}" aria-label="Probabilitate {{ $outcome }}"></meter></div>
                                @endforeach
                            </div>
                        @else<p class="simulator-unavailable">Probabilități indisponibile: niciun nivel activ nu oferă suficiente date 1/X/2.</p>@endif
                    </div>
                @endforeach
            </div>
            <h3>Rafinarea pe niveluri</h3>
            <div class="simulator-table-wrap"><table class="simulator-table"><thead><tr><th>Nivel</th><th>Stare</th><th>Date reale folosite</th><th>1</th><th>X</th><th>2</th></tr></thead><tbody>
                @foreach($simulation['steps'] as $step)
                <tr><td><strong>L{{ $step['level'] }} · {{ $step['name'] }}</strong><small>{{ $step['note'] }}</small></td><td><span class="simulator-status simulator-status-{{ $step['status'] }}">{{ ['applied'=>'Aplicat','disabled'=>'Dezactivat','unavailable'=>'Fără date','unmodeled'=>'Fără model','dependency'=>'Dependență L1'][$step['status']] }}</span></td><td>{{ $step['evidence'] }}</td>
                    @foreach(['1','X','2'] as $outcome)<td>{{ $step['probabilities'] ? number_format($step['probabilities'][$outcome], 1, ',', '.').'%' : '—' }}</td>@endforeach
                </tr>
                @endforeach
            </tbody></table></div>
            <p class="simulator-method">L1 folosește {{ $simulation['results_count'] }} rezultate anterioare din aceeași ligă. L2 folosește cote complete de la {{ $simulation['bookmakers_count'] }} operatori, fără marjă. L6 folosește modelul Poisson pe istoric. L3–L5 nu modifică 1/X/2 până când există date și reguli calibrate. Simularea nu schimbă recomandările sau datele din bază; nu este sfat de pariere.</p>
        </section>
    @elseif(!$warning)<p class="simulator-empty">Caută și selectează un meci pentru a vedea simularea.</p>@endif
    @endif
</div>
@endsection
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('.simulator-form');
    const match = document.getElementById('simulator-match');
    if (!form || !match) return;
    const resetLevels = () => {
        form.querySelectorAll('input[name="levels[]"]').forEach(input => input.disabled = true);
        form.querySelector('input[name="levels_submitted"]').disabled = true;
    };
    match.addEventListener('change', () => { resetLevels(); form.requestSubmit(); });
    form.addEventListener('submit', event => {
        if (event.submitter?.id === 'simulator-search-button') { match.value = ''; resetLevels(); }
    });
});
</script>
@endpush
