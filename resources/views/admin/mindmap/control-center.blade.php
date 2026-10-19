@extends('layouts.app')
@section('title','BetLens – Data Mindmap')
@section('content')
<div class="import-center" id="import-center">
    <div class="ic-main">
        <header class="ic-heading">
            <div><p class="ic-eyebrow">ADMIN / DATE & IMPORT</p><h1>BetLens – Data Mindmap</h1><p>Import și gestionare surse de date</p></div>
            <form class="ic-scope" method="get" action="{{ route('admin.mindmap') }}">
                <label for="ic-league">Ligă</label><select id="ic-league" name="league"><option value="">Toate competițiile</option>@foreach($leagues as $league)<option value="{{ $league->id }}" @selected($leagueId==$league->id)>{{ $league->name }}</option>@endforeach</select>
                <label for="ic-season">Sezon</label><select id="ic-season" name="season"><option value="">Toate sezoanele</option>@foreach($seasons as $season)<option value="{{ $season->id }}" @selected($seasonId==$season->id)>{{ $season->name }}</option>@endforeach</select>
                <button class="ic-button ic-outline" type="submit">Aplică</button>
            </form>
        </header>

        <div class="ic-toolbar">
            <label class="ic-search"><i data-lucide="search" size="17"></i><input id="ic-search" type="search" placeholder="Caută sursă" aria-label="Caută sursă"></label>
            <label>Nivel<select id="ic-level-filter"><option value="">Toate nivelurile</option>@foreach($levels as $number=>$level)<option value="{{ $number }}">L{{ $number }} · {{ $level['name'] }}</option>@endforeach</select></label>
            <label>Stare<select id="ic-status-filter"><option value="">Toate stările</option><option value="healthy">Disponibilă</option><option value="checking">În curs</option><option value="never_checked">Neverificată</option><option value="not_configured">Neconfigurată</option><option value="inactive">Inactivă</option><option value="unsupported">Nesuportată</option><option value="unavailable">Eroare</option></select></label>
            <button type="button" class="ic-button ic-outline" id="ic-add-source"><i data-lucide="plus" size="16"></i>Adaugă sursă</button>
            <button type="button" class="ic-button ic-primary" id="ic-import-all" @disabled($leagueId) title="{{ $leagueId ? 'Adaptoarele externe actuale nu suportă importul filtrat pe ligă.' : 'Confirmă pornirea tuturor surselor importabile.' }}"><i data-lucide="download" size="16"></i>Importă toate sursele</button>
        </div>
        @if($leagueId)<p class="ic-scope-note">Acoperirea specifică ligii este neconfirmată unde nu există o mapare verificată. Importurile externe globale sunt dezactivate pentru această selecție; recalcularea modelului intern rămâne disponibilă.</p>@endif

        <div class="ic-metrics" aria-label="Indicatori de calitate ai datelor">
            @foreach(['Surse active / înregistrate','Acoperirea câmpurilor','Calitatea datelor','Conflicte de date'] as $metric)
                <div><span>{{ $metric }}</span><strong>{{ $metrics[$metric][0] }}</strong><small title="{{ $metrics[$metric][1] }}">{{ $metric === 'Acoperirea câmpurilor' ? 'Din câmpurile meciurilor selectate' : ($metric === 'Calitatea datelor' ? 'Payload și checksum validate' : 'Date observate') }}</small></div>
            @endforeach
        </div>

        <div class="ic-workspace">
            <div class="ic-map-panel" id="ic-map-panel">
                <div class="ic-map-panel-actions"><button type="button" class="ic-button ic-outline" id="ic-fullscreen" aria-label="Afișează harta pe ecran complet" aria-pressed="false" title="Ecran complet"><i class="ic-enter-icon" data-lucide="maximize" size="16" aria-hidden="true"></i><i class="ic-exit-icon" data-lucide="minimize" size="16" aria-hidden="true"></i><span>Ecran complet</span></button></div>
            <div class="ic-map" id="ic-map" aria-label="Șase niveluri de surse de date">
                <div class="ic-core"><span class="ic-core-mark"><i data-lucide="chart-no-axes-column" size="26"></i></span><strong>{{ $leagueId ? $leagues->firstWhere('id',$leagueId)?->name : 'BetLens' }}</strong><small>{{ $leagueId ? 'Competiția selectată' : 'Toate competițiile' }}</small><span>Surse de date integrate</span></div>
                @foreach($levels as $number=>$level)
                    @php($levelRows=collect($rows)->filter(fn($row)=>in_array($number,$row['levels']))->values())
                    <section class="ic-level ic-level-{{ $number }}" data-level="{{ $number }}">
                        <header><span class="ic-level-icon"><i data-lucide="{{ $level['icon'] }}" size="19"></i></span><div><h2>L{{ $number }} – {{ $level['name'] }}</h2><p>{{ $level['description'] }}</p></div><button type="button" class="ic-collapse" aria-expanded="true" aria-label="Restrânge nivelul L{{ $number }}"><i data-lucide="chevron-up" size="17"></i></button></header>
                        <div class="ic-level-body">
                            @forelse($levelRows as $row)
                            <div class="ic-source" data-source-id="{{ $row['id'] }}" data-source-status="{{ $row['status'] }}" data-source-name="{{ mb_strtolower($row['name']) }}">
                                <button type="button" class="ic-source-name" data-select-source="{{ $row['id'] }}" aria-label="Detalii {{ $row['name'] }}"><span class="ic-source-icon">{{ mb_substr($row['name'],0,1) }}</span><span>{{ $row['name'] }}</span></button>
                                <span class="ic-status ic-status-{{ $row['status'] }}">{{ $row['statusLabel'] }}</span>
                                <time title="Ultimul import reușit">{{ $row['lastSync'] ? \Illuminate\Support\Carbon::parse($row['lastSync'])->format('d.m H:i') : 'Niciodată' }}</time>
                                @if($row['canImport'] && !in_array($row['method'],['manual_json','manual_csv']))<button type="button" class="ic-import-link {{ $row['activeRunId'] ? 'is-loading' : '' }}" data-import-source="{{ $row['id'] }}" data-idle-label="{{ $row['method']==='internal_model' ? 'Recalculează' : 'Importă' }}" @disabled($row['activeRunId']) aria-busy="{{ $row['activeRunId'] ? 'true' : 'false' }}">{{ $row['activeRunId'] ? ($row['activeRunStatus']==='running' ? 'Se importă' : 'În coadă') : ($row['method']==='internal_model' ? 'Recalculează' : 'Importă') }}</button>
                                @else<button type="button" class="ic-import-link" data-select-source="{{ $row['id'] }}">Detalii</button>@endif
                            </div>
                            @empty<p class="ic-empty-level">Nicio sursă înregistrată.</p>@endforelse
                        </div>
                        <footer><span>{{ $levelRows->count() }} surse · {{ $levelRows->sum('recordsCount') }} înregistrări · {{ $levelRows->filter(fn($row)=>$row['canImport'] && !in_array($row['method'],['manual_json','manual_csv']))->count() }} importabile</span><button type="button" data-import-level="{{ $number }}" @disabled(!$levelRows->contains(fn($row)=>$row['canImport'] && !in_array($row['method'],['manual_json','manual_csv'])))>Importă nivelul <i data-lucide="arrow-right" size="13"></i></button></footer>
                    </section>
                @endforeach
            </div>
            </div>

            <aside class="ic-details" id="ic-details" aria-labelledby="ic-details-title">
                <header><h2 id="ic-details-title">Detalii sursă</h2><button type="button" id="ic-details-toggle" aria-expanded="true" aria-label="Restrânge detaliile"><i data-lucide="panel-right-close" size="17"></i></button></header>
                <div class="ic-details-body">
                    <div class="ic-source-identity"><span class="ic-source-icon" id="ic-detail-icon">?</span><div><strong id="ic-detail-name">Selectează o sursă</strong><small id="ic-detail-level"></small></div><span class="ic-status" id="ic-detail-status">—</span></div>
                    <div class="ic-tabs" role="tablist" aria-label="Detalii sursă"><button type="button" role="tab" aria-selected="true" data-tab="general">General</button><button type="button" role="tab" aria-selected="false" data-tab="mapping">Mapping</button><button type="button" role="tab" aria-selected="false" data-tab="history">Istoric</button></div>
                    <div class="ic-tab-panel" data-panel="general"><dl class="ic-facts"><div><dt>Website</dt><dd id="ic-detail-website">—</dd></div><div><dt>Endpoint aprobat</dt><dd id="ic-detail-endpoint">—</dd></div><div><dt>Metodă</dt><dd id="ic-detail-method">—</dd></div><div><dt>Acoperire ligă</dt><dd id="ic-detail-coverage">—</dd></div><div><dt>Ultimul import reușit</dt><dd id="ic-detail-sync">—</dd></div><div><dt>Următoarea rulare programată</dt><dd id="ic-detail-next">—</dd></div><div><dt>Înregistrări stocate</dt><dd id="ic-detail-count">0</dd></div></dl>
                        <form id="ic-config-form" method="post">@csrf @method('PUT')<input type="hidden" name="level" id="ic-config-level"><label>Frecvență<select name="import_frequency" id="ic-config-frequency"><option value="manual">Doar manual</option><option value="hourly">La o oră</option><option value="six_hourly">La 6 ore</option><option value="twelve_hourly">La 12 ore</option><option value="daily">Zilnic</option><option value="weekly">Săptămânal</option></select></label><label>Timeout (secunde)<input name="request_timeout" id="ic-config-timeout" type="number" min="5" max="30"></label><input type="hidden" name="active" value="0"><label class="ic-checkbox"><input type="checkbox" id="ic-config-active" name="active" value="1">Sursă activă</label><input type="hidden" name="import_enabled" value="0"><label class="ic-checkbox"><input type="checkbox" id="ic-config-enabled" name="import_enabled" value="1">Import activat</label><button type="submit" class="ic-button ic-primary">Salvează</button></form>
                        <form id="ic-upload-form" method="post" enctype="multipart/form-data" hidden>@csrf<label>Fișier CSV/JSON<input type="file" name="file" required></label><input type="hidden" name="league" value="{{ $leagueId }}"><input type="hidden" name="season" value="{{ $seasonId }}"><button type="submit" class="ic-button ic-primary">Încarcă și importă</button></form>
                        @if($leagueId)<form id="ic-coverage-form" method="post">@csrf<input type="hidden" name="league_id" value="{{ $leagueId }}"><label>Acoperire pentru liga selectată<select name="coverage_status" id="ic-coverage-status"><option value="unverified">Neconfirmată</option><option value="supported">Confirmată de admin</option><option value="unsupported">Nesuportată</option></select></label><label>ID competiție la furnizor<input name="external_competition_id" id="ic-coverage-id" maxlength="100" pattern="[A-Za-z0-9_.-]+"></label><button type="submit" class="ic-button ic-outline">Salvează acoperirea</button><small>Această mapare nu activează un adaptor de import filtrat pe ligă.</small></form>@endif
                        <div class="ic-detail-actions"><button type="button" id="ic-test" class="ic-button ic-outline">Testează conexiunea</button><button type="button" id="ic-preview" class="ic-button ic-outline">Previzualizează date</button><button type="button" id="ic-import" class="ic-button ic-primary" data-idle-label="Importă acum">Importă acum</button></div><pre id="ic-preview-output" hidden></pre>
                    </div>
                    <div class="ic-tab-panel" data-panel="mapping" hidden><p>Mapările pentru importurile manuale adaugă câmpurile standardizate în payloadul sursei. Nu suprascriu automat identitatea sau rezultatul meciurilor canonice.</p><ul id="ic-mapping-list"></ul><form id="ic-mapping-form" method="post" hidden>@csrf<label>Câmp extern (ex. homeTeam.name)<input name="external_field" required maxlength="160" pattern="[A-Za-z0-9_.-]+"></label><label>Câmp standard<select name="internal_field"><option value="home_team_name">Echipa gazdă</option><option value="away_team_name">Echipa oaspete</option><option value="kickoff_at">Ora startului</option><option value="competition_name">Competiție</option><option value="home_score">Goluri gazde</option><option value="away_score">Goluri oaspeți</option></select></label><button class="ic-button ic-outline">Salvează maparea</button></form></div>
                    <div class="ic-tab-panel" data-panel="history" hidden><div id="ic-function-summary" class="ic-function-summary" hidden><b>Ultimul import API-Football · România</b><span>Competiții identificate: <strong id="ic-competitions-received">—</strong></span><span>Competiții salvate în MySQL: <strong id="ic-competitions-saved">—</strong></span><span>Sezoane salvate în MySQL: <strong id="ic-seasons-saved">—</strong></span></div><div id="ic-source-history"></div><a href="{{ route('admin.import-audit') }}">Vezi auditul complet <i data-lucide="arrow-right" size="14"></i></a></div>
                    <p class="ic-feedback" id="ic-feedback" role="status" aria-live="polite"></p>
                </div>
            </aside>
        </div>

    </div>
    <dialog class="ic-dialog" id="ic-add-dialog"><form method="dialog"><button class="ic-dialog-close" aria-label="Închide">×</button></form><h2>Adaugă sursă</h2><p>Sursele manuale pot importa CSV/JSON; celelalte necesită un adaptor aprobat.</p><form method="post" action="{{ route('admin.mindmap.store') }}">@csrf<label>Nume<input name="name" required maxlength="100"></label><label>Nivel<select name="level" id="ic-add-level">@foreach($levels as $number=>$level)<option value="{{ $number }}">L{{ $number }} · {{ $level['name'] }}</option>@endforeach</select></label><input type="hidden" name="group" value="football"><input type="hidden" name="type" value="manual"><label>Metodă<select name="import_method"><option value="manual_json">JSON încărcat manual</option><option value="manual_csv">CSV încărcat manual</option><option value="unsupported">Fără adaptor</option></select></label><button class="ic-button ic-primary" type="submit">Salvează sursa</button></form></dialog>
    <script type="application/json" id="ic-data">{!! json_encode(['rows'=>$rows,'levels'=>$levels,'leagueId'=>$leagueId,'seasonId'=>$seasonId,'runs'=>$historyRuns->map(fn($run)=>['id'=>$run->id,'source_id'=>$run->data_source_id,'status'=>$run->status,'message'=>$run->message,'duration_ms'=>$run->duration_ms,'records_fetched'=>$run->records_fetched,'records_new'=>$run->records_new,'records_updated'=>$run->records_updated,'records_unchanged'=>$run->records_unchanged,'records_errors'=>$run->records_errors,'metadata'=>$run->metadata])->all(),'urls'=>['sourceBase'=>url('/admin/mindmap/sources'),'levelBase'=>url('/admin/mindmap/levels'),'runBase'=>url('/admin/mindmap/runs'),'all'=>route('admin.mindmap.import-all')]],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) !!}</script>
</div>
@endsection
