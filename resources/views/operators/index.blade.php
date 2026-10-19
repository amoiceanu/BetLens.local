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

<section class="card operator-table-card" aria-label="Lista operatorilor">
    <div class="operator-table-wrap">
        <table class="operator-table">
            <thead><tr><th scope="col">#</th><th scope="col">Operator</th><th scope="col">Site</th><th scope="col">Stare</th><th scope="col">Acțiuni</th></tr></thead>
            <tbody>
            @forelse($operators as $operator)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td><label class="visually-hidden" for="operator-name-{{ $operator->id }}">Nume {{ $operator->name }}</label><input id="operator-name-{{ $operator->id }}" class="field" form="operator-update-{{ $operator->id }}" name="name" value="{{ $operator->name }}" maxlength="100" required></td>
                    <td><div class="operator-site-cell"><label class="visually-hidden" for="operator-site-{{ $operator->id }}">Site {{ $operator->name }}</label><input id="operator-site-{{ $operator->id }}" class="field" type="url" form="operator-update-{{ $operator->id }}" name="website_url" value="{{ $operator->website_url }}" placeholder="https://operator.ro">@if($operator->website_url)<a class="operator-site" href="{{ $operator->website_url }}" target="_blank" rel="noopener noreferrer" aria-label="Deschide site-ul {{ $operator->name }}"><i data-lucide="external-link" size="16"></i></a>@endif</div></td>
                    <td><label class="visually-hidden" for="operator-active-{{ $operator->id }}">Stare {{ $operator->name }}</label><select id="operator-active-{{ $operator->id }}" class="field" form="operator-update-{{ $operator->id }}" name="active"><option value="1" @selected($operator->active)>Activ</option><option value="0" @selected(!$operator->active)>Inactiv</option></select></td>
                    <td><div class="operator-row-actions"><form id="operator-update-{{ $operator->id }}" method="post" action="{{ route('operators.update',$operator) }}">@csrf @method('PATCH')<button class="btn secondary small" type="submit"><i data-lucide="save" size="15"></i>Salvează</button></form><form method="post" action="{{ route('operators.destroy',$operator) }}" onsubmit="return confirm('Ștergi operatorul {{ addslashes($operator->name) }}?')">@csrf @method('DELETE')<button class="btn danger small" type="submit"><i data-lucide="trash-2" size="14"></i>Șterge</button></form></div></td>
                </tr>
            @empty
                <tr><td class="empty" colspan="5">Nu există operatori configurați.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection
