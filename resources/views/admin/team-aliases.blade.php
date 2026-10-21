@extends('layouts.app')
@section('title', 'Aliasuri globale echipe')
@section('content')
<div class="team-aliases-page" data-team-aliases data-teams-url="{{ route('admin.aliases.teams') }}" data-normalize-url="{{ route('admin.aliases.normalize') }}">
    <header class="aliases-page-head">
        <div><p class="eyebrow">Administrare</p><h1>Aliasuri globale echipe</h1><p>Gestionează aliasurile globale ale echipelor din BetLens. Un alias este asociat direct echipei canonice și poate fi folosit în orice ligă, competiție, operator sau sursă de date.</p></div>
        <div class="aliases-head-actions"><a class="btn secondary" href="{{ route('data-sources') }}"><i data-lucide="arrow-left" size="16"></i>Înapoi la surse</a><a class="btn" href="#alias-create"><i data-lucide="plus" size="16"></i>Adaugă alias</a></div>
    </header>

    @if(session('success'))<p class="aliases-flash" role="status">{{ session('success') }}</p>@endif
    @if($errors->any())<p class="aliases-flash is-error" role="alert">{{ $errors->first() }}</p>@endif

    <section class="aliases-stats" aria-label="Statistici aliasuri">
        <article class="aliases-stat"><span class="aliases-stat-icon blue"><i data-lucide="link" size="21"></i></span><div><small>Total aliasuri globale</small><strong>{{ number_format($totalAliases) }}</strong><span>înregistrări păstrate</span></div></article>
        <article class="aliases-stat"><span class="aliases-stat-icon green"><i data-lucide="users-round" size="21"></i></span><div><small>Echipe canonice</small><strong>{{ number_format($canonicalTeams) }}</strong><span>cu aliasuri active</span></div></article>
        <article class="aliases-stat"><span class="aliases-stat-icon violet"><i data-lucide="zap" size="21"></i></span><div><small>Potriviri automate</small><strong>—</strong><span>metrică indisponibilă</span></div></article>
        <article class="aliases-stat"><span class="aliases-stat-icon orange"><i data-lucide="triangle-alert" size="21"></i></span><div><small>Necesită verificare</small><strong>{{ number_format($reviewCount) }}</strong><span>conflicte globale păstrate</span></div></article>
    </section>

    <div class="aliases-main-grid">
        <div class="aliases-main-column">
            <section class="card aliases-create-card" id="alias-create" aria-labelledby="aliases-add-title">
                <h2 id="aliases-add-title">Adaugă alias global</h2>
                <form class="aliases-add-form" method="post" action="{{ route('admin.aliases.store') }}" data-alias-form>
                    @csrf
                    <input type="hidden" name="team_id" value="{{ old('team_id') }}" data-team-id required>
                    <label class="alias-team-picker"><span>Echipă oficială BetLens</span><span class="alias-picker-control"><i data-lucide="search" size="16"></i><input type="search" autocomplete="off" placeholder="Caută echipa oficială…" data-team-search value="{{ old('team_label') }}" aria-label="Caută echipa oficială"></span><div class="alias-team-options" data-team-options hidden></div><small data-selected-team>Alege o echipă canonică din rezultate.</small></label>
                    <label><span>Alias global</span><input class="field" name="alias" value="{{ old('alias') }}" maxlength="255" placeholder="Ex. FC Koeln" data-alias-input required></label>
                    <label><span>Formă normalizată</span><input class="field alias-normalized" value="" placeholder="Se generează automat…" data-normalized-input readonly></label>
                    <button class="btn" type="submit"><i data-lucide="save" size="15"></i>Salvează alias</button>
                </form>
                <p class="aliases-form-note"><i data-lucide="info" size="15"></i>Aliasul va fi recunoscut indiferent de ligă, operator sau sursă.</p>
            </section>

            <section class="card aliases-list-card" aria-labelledby="aliases-list-title">
                <div class="aliases-list-heading"><h2 id="aliases-list-title">Aliasuri salvate</h2><nav class="aliases-tabs" aria-label="Filtru status aliasuri"><a href="{{ route('admin.aliases.index', ['status' => 'all', 'q' => $filters['q']]) }}" class="{{ ($filters['status'] ?? 'all') === 'all' ? 'active' : '' }}">Toate</a><a href="{{ route('admin.aliases.index', ['status' => 'active', 'q' => $filters['q']]) }}" class="{{ ($filters['status'] ?? 'all') === 'active' ? 'active' : '' }}">Active</a><a href="{{ route('admin.aliases.index', ['status' => 'review', 'q' => $filters['q']]) }}" class="{{ ($filters['status'] ?? 'all') === 'review' ? 'active' : '' }}">Necesită verificare <span>{{ $reviewCount }}</span></a></nav></div>
                <div class="aliases-table-toolbar">
                    <form method="get" action="{{ route('admin.aliases.index') }}" class="aliases-search-form"><input type="hidden" name="status" value="{{ $filters['status'] ?? 'all' }}"><label class="sr-only" for="alias-search">Caută echipă sau alias</label><i data-lucide="search" size="16"></i><input id="alias-search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Caută echipă sau alias…"><button class="btn secondary small" type="submit">Caută</button></form>
                    <a class="btn secondary small" href="{{ route('admin.aliases.index') }}"><i data-lucide="rotate-ccw" size="14"></i>Resetează filtre</a>
                    <a class="btn secondary small" href="{{ route('admin.aliases.export', request()->only('q', 'status')) }}"><i data-lucide="download" size="14"></i>Exportă CSV</a>
                </div>
                <form method="post" action="{{ route('admin.aliases.bulk-destroy') }}" data-bulk-delete>
                    @csrf @method('DELETE')
                    <div class="aliases-bulk-toolbar"><label><input type="checkbox" data-select-all> Selectează pagina</label><button class="btn danger small" type="submit" data-delete-selected disabled><i data-lucide="trash-2" size="14"></i>Șterge selectate</button></div>
                    <div class="aliases-table-wrap"><table class="aliases-table"><thead><tr><th><span class="sr-only">Selectare</span></th><th><a href="{{ route('admin.aliases.index', array_merge(request()->only('q','status'), ['sort'=>'team','direction'=>($filters['sort']==='team' && $filters['direction']==='asc')?'desc':'asc'])) }}">Echipă canonică ↕</a></th><th><a href="{{ route('admin.aliases.index', array_merge(request()->only('q','status'), ['sort'=>'alias','direction'=>($filters['sort']==='alias' && $filters['direction']==='asc')?'desc':'asc'])) }}">Alias global ↕</a></th><th>Formă normalizată</th><th>Apariții</th><th>Ultima utilizare</th><th>Status</th><th>Acțiuni</th></tr></thead><tbody>
                        @forelse($aliases as $alias)
                            <tr>
                                <td><input type="checkbox" name="ids[]" value="{{ $alias->id }}" data-alias-check aria-label="Selectează aliasul {{ $alias->alias }}"></td>
                                <td><div class="aliases-canonical">@if($alias->team?->crest)<img src="{{ $alias->team->crest }}" alt="" loading="lazy">@else<span class="aliases-team-placeholder"><i data-lucide="shield" size="17"></i></span>@endif<div><strong>{{ $alias->team?->name ?? 'Echipă eliminată' }}</strong><small>{{ $alias->team?->league?->name ?? 'Echipă canonică BetLens' }}</small></div></div></td>
                                <td><strong>{{ $alias->alias }}</strong></td><td><code>{{ $alias->normalized_alias }}</code></td>
                                <td>—</td><td>—</td>
                                <td><span class="aliases-status {{ $alias->status === 'review' ? 'review' : 'active' }}"><i data-lucide="{{ $alias->status === 'review' ? 'circle-alert' : 'circle-check' }}" size="13"></i>{{ $alias->status === 'review' ? 'În verificare' : 'Activ' }}</span></td>
                                <td><div class="aliases-row-actions"><button type="button" class="aliases-icon-button" data-edit-alias data-id="{{ $alias->id }}" data-team-id="{{ $alias->team_id }}" data-team-name="{{ $alias->team?->name }}" data-alias="{{ $alias->alias }}" data-normalized="{{ $alias->normalized_alias }}" data-update-url="{{ route('admin.aliases.update', $alias) }}" aria-label="Editează aliasul {{ $alias->alias }}"><i data-lucide="pencil" size="15"></i></button><button type="submit" form="delete-alias-{{ $alias->id }}" class="aliases-icon-button is-danger" aria-label="Șterge aliasul {{ $alias->alias }}"><i data-lucide="trash-2" size="15"></i></button></div></td>
                            </tr>
                        @empty<tr><td colspan="8" class="aliases-empty">Nu există aliasuri pentru filtrele selectate.</td></tr>@endforelse
                    </tbody></table></div>
                </form>
                @foreach($aliases as $alias)<form id="delete-alias-{{ $alias->id }}" class="sr-only" method="post" action="{{ route('admin.aliases.destroy', $alias) }}" onsubmit="return confirm('Ștergi aliasul {{ addslashes($alias->alias) }}? Echipa și datele importate rămân intacte.')">@csrf @method('DELETE')</form>@endforeach
                <div class="aliases-pagination">{{ $aliases->links() }}</div>
            </section>
        </div>

        <aside class="card aliases-howto"><h2>Cum funcționează?</h2><ol><li><span>1</span><div><strong>Aliasul este legat de echipa canonică</strong><p>Fiecare alias este asociat direct unei echipe oficiale din BetLens.</p></div></li><li><span>2</span><div><strong>Se aplică global</strong><p>Același alias poate fi folosit indiferent de ligă, competiție, operator sau sursă.</p></div></li><li><span>3</span><div><strong>Potrivirea manuală are prioritate</strong><p>Aliasurile active sunt aplicate înaintea potrivirii aproximative. Conflictele sunt oprite pentru verificare.</p></div></li></ol></aside>
    </div>

    <div class="aliases-modal" data-edit-modal hidden><section class="aliases-modal-card" role="dialog" aria-modal="true" aria-labelledby="edit-alias-title"><button type="button" class="aliases-modal-close" data-close-modal aria-label="Închide">×</button><p class="eyebrow">Administrare alias</p><h2 id="edit-alias-title">Editează alias global</h2><form method="post" data-edit-form>@csrf @method('PATCH')<input type="hidden" name="team_id" data-edit-team-id><label class="alias-team-picker"><span>Echipă canonică</span><span class="alias-picker-control"><i data-lucide="search" size="16"></i><input type="search" autocomplete="off" data-edit-team-search aria-label="Caută echipă canonică"></span><div class="alias-team-options" data-edit-team-options hidden></div><small data-edit-selected></small></label><label>Alias global<input class="field" name="alias" maxlength="255" data-edit-alias-input required></label><label>Formă normalizată<input class="field alias-normalized" data-edit-normalized readonly></label><button class="btn" type="submit">Salvează modificările</button></form></section></div>
</div>
@endsection
