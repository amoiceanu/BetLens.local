@extends('layouts.app')
@section('title','Operatori')
@section('content')
<div class="page-head">
    <div><p class="eyebrow">Configurare</p><h1>Operatori</h1><p>Administrează operatorii folosiți pentru bilete și redirecționări.</p></div>
</div>

<section class="card operator-create-card">
    <div><p class="eyebrow">Operator nou</p><h2>Adaugă operator</h2></div>
    <form class="operator-form operator-create-form" method="post" action="{{ route('operators.store') }}">
        @csrf
        <input class="field" name="name" value="{{ old('name') }}" placeholder="Nume operator" maxlength="100" required>
        <input class="field" type="url" name="website_url" value="{{ old('website_url') }}" placeholder="https://operator.ro">
        <select class="field" name="active"><option value="1">Activ</option><option value="0">Inactiv</option></select>
        <button class="btn" type="submit"><i data-lucide="plus" size="16"></i>Adaugă</button>
    </form>
</section>

<div class="operator-grid">
@forelse($operators as $operator)
    <article class="card operator-card">
        <div class="operator-card-head"><div><span class="status {{ $operator->active?'won':'void' }}">{{ $operator->active?'Activ':'Inactiv' }}</span><h2>{{ $operator->name }}</h2></div>@if($operator->website_url)<a class="operator-site" href="{{ $operator->website_url }}" target="_blank" rel="noopener noreferrer" title="Deschide site-ul"><i data-lucide="external-link" size="16"></i></a>@endif</div>
        <form class="operator-form" method="post" action="{{ route('operators.update',$operator) }}">
            @csrf @method('PATCH')
            <label>Nume<input class="field" name="name" value="{{ $operator->name }}" maxlength="100" required></label>
            <label>Site<input class="field" type="url" name="website_url" value="{{ $operator->website_url }}" placeholder="https://operator.ro"></label>
            <label>Stare<select class="field" name="active"><option value="1" @selected($operator->active)>Activ</option><option value="0" @selected(!$operator->active)>Inactiv</option></select></label>
            <button class="btn secondary" type="submit"><i data-lucide="save" size="15"></i>Salvează</button>
        </form>
        <form class="operator-delete-form" method="post" action="{{ route('operators.destroy',$operator) }}" onsubmit="return confirm('Ștergi operatorul {{ addslashes($operator->name) }}?')">
            @csrf @method('DELETE')
            <button class="btn danger small" type="submit"><i data-lucide="trash-2" size="14"></i>Șterge</button>
        </form>
    </article>
@empty
    <div class="card empty">Nu există operatori configurați.</div>
@endforelse
</div>
@endsection
