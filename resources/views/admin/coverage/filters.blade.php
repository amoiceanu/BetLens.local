<form class="coverage-filters" method="get" action="{{ $action ?? route('admin.coverage') }}" data-coverage-filter>
    <label>Ligă<select class="field" name="league"><option value="">Toate ligile</option>@foreach($leagues as $league)<option value="{{ $league->id }}" @selected(($filters['league']??'')==$league->id)>{{ $league->name }}</option>@endforeach</select></label>
    <label>Sezon<select class="field" name="season"><option value="">Toate sezoanele</option>@foreach($seasons as $season)<option value="{{ $season->id }}" @selected(($filters['season']??'')==$season->id)>{{ $season->name }}</option>@endforeach</select></label>
    <label>Sursă<select class="field" name="source"><option value="">Toate sursele</option>@foreach($sources as $source)<option value="{{ $source->id }}" @selected(($filters['source']??'')==$source->id)>{{ $source->name }}</option>@endforeach</select></label>
    <label>Câmp<select class="field" name="field"><option value="">Toate câmpurile</option>@foreach($fields as $field=>$definition)<option value="{{ $field }}" @selected(($filters['field']??'')===$field)>{{ $definition['label'] }}</option>@endforeach</select></label>
    @if($discrepancyFilters??false)
        <label>Severitate<select class="field" name="severity"><option value="">Toate</option>@foreach(['info'=>'Informație lipsă','warning'=>'Avertizare','critical'=>'Critică'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['severity']??'')===$key)>{{ $label }}</option>@endforeach</select></label>
        <label>Status<select class="field" name="status"><option value="">Toate</option>@foreach(['new'=>'Nouă','ignored'=>'Ignorată','resolved'=>'Rezolvată'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['status']??'')===$key)>{{ $label }}</option>@endforeach</select></label>
        @if(!empty($filters['game_id']))<input type="hidden" name="game_id" value="{{ $filters['game_id'] }}">@endif
    @else
        <label>Stare<select class="field" name="state"><option value="">Toate stările</option>@foreach($states as $key=>$label)<option value="{{ $key }}" @selected(($filters['state']??'')===$key)>{{ $label }}</option>@endforeach</select></label>
    @endif
    <label class="coverage-search">Caută<input class="field" type="search" name="q" maxlength="100" value="{{ $filters['q']??'' }}" placeholder="Sursă, ligă sau câmp"></label>
    <label>Rânduri<select name="per_page" class="field">@foreach([15,30,60] as $size)<option @selected(($filters['per_page']??15)==$size)>{{ $size }}</option>@endforeach</select></label>
    <div class="coverage-actions"><button class="btn">Aplică filtrele</button><a class="btn secondary" href="{{ $action ?? route('admin.coverage') }}">Resetează</a><span hidden role="status" data-loading>Se încarcă datele locale…</span></div>
</form>
