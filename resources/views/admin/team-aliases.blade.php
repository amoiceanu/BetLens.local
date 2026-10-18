@extends('layouts.app')
@section('title', 'Aliasuri echipe')
@section('content')
<div class="page-head"><div><p class="eyebrow">Administrare</p><h1>Aliasuri echipe</h1><p>Asociază numele folosite de operatori cu echipele din BetLens. Aliasurile au prioritate față de potrivirea aproximativă.</p></div><a class="btn secondary" href="{{ route('admin') }}">Înapoi la administrare</a></div>

<section class="card aliases-card" aria-labelledby="aliases-add-title">
    <h2 id="aliases-add-title">Adaugă alias</h2>
    <form class="aliases-add-form" method="post" action="{{ route('admin.aliases.store') }}">
        @csrf
        <label>Echipă<select class="field" name="team_id" required><option value="">Alege echipa</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(old('team_id') == $team->id)>{{ $team->league->name }} — {{ $team->name }}</option>@endforeach</select></label>
        <label>Operator<select class="field" name="provider" required><option value="all">Toți</option><option value="superbet" @selected(old('provider') === 'superbet')>Superbet</option><option value="winbet" @selected(old('provider') === 'winbet')>Winbet</option></select></label>
        <label>Nume alternativ<input class="field" name="alias" value="{{ old('alias') }}" maxlength="255" placeholder="Ex. Newcastle Utd" required></label>
        <button class="btn" type="submit">Adaugă alias</button>
    </form>
    @if($errors->any())<p class="aliases-error" role="alert">{{ $errors->first() }}</p>@endif
</section>

<section class="card aliases-card" aria-labelledby="aliases-list-title">
    <h2 id="aliases-list-title">Aliasuri salvate</h2>
    @forelse($aliases as $alias)
        <div class="aliases-row">
            <div class="aliases-team"><strong>{{ $alias->team->name }}</strong><small>{{ $alias->league->name }}</small></div>
            <form class="aliases-edit-form" method="post" action="{{ route('admin.aliases.update', $alias) }}">
                @csrf @method('PATCH')
                <input type="hidden" name="team_id" value="{{ $alias->team_id }}">
                <label><span class="sr-only">Operator pentru {{ $alias->team->name }}</span><select class="field" name="provider"><option value="all" @selected($alias->provider === 'all')>Toți</option><option value="superbet" @selected($alias->provider === 'superbet')>Superbet</option><option value="winbet" @selected($alias->provider === 'winbet')>Winbet</option></select></label>
                <label><span class="sr-only">Alias pentru {{ $alias->team->name }}</span><input class="field" name="alias" value="{{ $alias->alias }}" maxlength="255" required></label>
                <button class="btn secondary small" type="submit">Salvează</button>
            </form>
            <form method="post" action="{{ route('admin.aliases.destroy', $alias) }}" onsubmit="return confirm('Ștergi acest alias?')">@csrf @method('DELETE')<button class="btn secondary small" type="submit" aria-label="Șterge aliasul {{ $alias->alias }}">Șterge</button></form>
        </div>
    @empty
        <p class="muted">Nu există aliasuri configurate.</p>
    @endforelse
    {{ $aliases->links() }}
</section>
@endsection
