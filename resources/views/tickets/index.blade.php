@extends('layouts.app')
@section('title','Biletele mele')
@section('content')
@php
    $sortUrl=fn($column)=>route('tickets',array_filter($filters,fn($value)=>$value!=='')+['sort'=>$column,'direction'=>$sort===$column&&$direction==='asc'?'desc':'asc']);
    $sortDirection=fn($column)=>$sort===$column?$direction:null;
@endphp
<div class="page-head">
    <div>
        <p class="eyebrow">Arhivă personală</p>
        <h1>Biletele mele</h1>
        <p>Istoric informativ pentru selecțiile generate în BetLens și biletele importate.</p>
    </div>
    <a class="btn" href="{{ route('dashboard') }}"><i data-lucide="plus" size="17"></i>Bilet nou</a>
</div>

<form class="card ticket-filter-form" method="get" action="{{ route('tickets') }}" aria-label="Filtrează biletele">
    <label>Sursă
        <select class="field" name="source"><option value="">Toate sursele</option><option value="betlens" @selected($filters['source']==='betlens')>BetLens</option><option value="import" @selected($filters['source']==='import')>Import</option></select>
    </label>
    <label>Status
        <select class="field" name="status"><option value="">Toate statusurile</option>@foreach(['pending'=>'În așteptare','placed'=>'Plasat','won'=>'Câștigat','lost'=>'Pierdut','void'=>'Anulat'] as $value=>$label)<option value="{{ $value }}" @selected($filters['status']===$value)>{{ $label }}</option>@endforeach</select>
    </label>
    <label>De la
        <input class="field" type="date" name="from" value="{{ $filters['from'] }}" aria-label="Data biletului de la">
    </label>
    <label>Până la
        <input class="field" type="date" name="to" value="{{ $filters['to'] }}" aria-label="Data biletului până la">
    </label>
    <div class="ticket-filter-actions"><button class="btn" type="submit"><i data-lucide="filter" size="16"></i>Aplică filtrele</button><a class="btn secondary" href="{{ route('tickets') }}">Resetează filtrele</a></div>
    @error('to')<p class="ticket-filter-error">{{ $message }}</p>@enderror
</form>

<section class="ticket-stats" aria-label="Statistici bilete">
    <div class="card ticket-stat ticket-stat-placed">
        <span class="ticket-stat-label"><i data-lucide="ticket-check" size="18" aria-hidden="true"></i>Plasate</span>
        <strong>{{ $ticketStats['placed'] }}</strong>
        <span class="ticket-stat-detail">{{ $ticketStats['total'] ? number_format($ticketStats['placed'] / $ticketStats['total'] * 100, 0, ',', '.') : '0' }}% din biletele afișate</span>
    </div>
    <div class="card ticket-stat ticket-stat-won">
        <span class="ticket-stat-label"><i data-lucide="circle-check" size="18" aria-hidden="true"></i>Câștigate</span>
        <strong>{{ $ticketStats['won'] }}</strong>
        <span class="ticket-stat-detail">{{ $ticketStats['placed'] ? number_format($ticketStats['won'] / $ticketStats['placed'] * 100, 0, ',', '.') : '0' }}% din biletele plasate</span>
    </div>
    <div class="card ticket-stat ticket-stat-lost">
        <span class="ticket-stat-label"><i data-lucide="circle-x" size="18" aria-hidden="true"></i>Pierdute</span>
        <strong>{{ $ticketStats['lost'] }}</strong>
        <span class="ticket-stat-detail">{{ $ticketStats['placed'] ? number_format($ticketStats['lost'] / $ticketStats['placed'] * 100, 0, ',', '.') : '0' }}% din biletele plasate</span>
    </div>
</section>

<section class="card ticket-list-card"><div class="ticket-list-table-wrap">
    <table class="ticket-history-table">
        <thead><tr>
            <th scope="col" class="ticket-row-number">Nr.</th>
            @foreach(['reference'=>'Referință','created_at'=>'Creat','first_match_at'=>'Primul meci','last_match_at'=>'Ultimul meci','risk_profile'=>'Profil','selections'=>'Selecții','total_odds'=>'Cotă','combined_probability'=>'Prob. combinată','status'=>'Status'] as $column=>$label)
                <th><a class="ticket-sort {{ $sort===$column?'active':'' }}" href="{{ $sortUrl($column) }}">{{ $label }}<i data-lucide="{{ $sortDirection($column)==='asc'?'arrow-up':($sortDirection($column)==='desc'?'arrow-down':'arrow-up-down') }}" size="12"></i></a></th>
            @endforeach
            <th><span class="visually-hidden">Acțiuni</span></th>
        </tr></thead>
        <tbody x-data="{open:null}">@forelse($tickets as $ticket)
            @php($imported=$ticket instanceof \App\Models\ImportedTicket)
            @php($rowKey=($imported?'import-':'betlens-').$ticket->id)
            <tr>
                <td class="ticket-row-number" data-label="Nr.">{{ $tickets->count() - $loop->index }}</td>
                <td data-label="Referință"><b>{{ $ticket->reference }}</b><span class="ticket-source-label">{{ $imported?'Import SuperBet':'BetLens' }}</span></td>
                <td data-label="Creat">{{ ($imported?$ticket->placed_at:$ticket->created_at)->format('d.m.Y') }}</td>
                <td data-label="Primul meci" class="ticket-match-date">{{ $ticket->effective_first_match_at?->format('d.m.Y H:i') ?? '—' }}</td>
                <td data-label="Ultimul meci" class="ticket-match-date">{{ $ticket->effective_last_match_at?->format('d.m.Y H:i') ?? '—' }}</td>
                <td data-label="Profil">{{ $imported?'Import':ucfirst($ticket->risk_profile) }}</td>
                <td data-label="Selecții">{{ $ticket->selections->count() }}</td>
                <td data-label="Cotă">{{ number_format($ticket->total_odds,2) }}</td>
                <td data-label="Prob. combinată">{{ $imported?'—':number_format($ticket->combined_probability*100,2).'%' }}</td>
                <td data-label="Status"><span class="status {{ $ticket->status }}">{{ $ticket->status_label }}</span></td>
                <td class="ticket-actions-cell">
                    <div class="ticket-list-actions">
                        <button class="btn secondary small ticket-options-toggle" type="button" @click="open=open==='{{ $rowKey }}'?null:'{{ $rowKey }}'" :aria-expanded="(open==='{{ $rowKey }}').toString()" aria-controls="ticket-options-{{ $rowKey }}" aria-label="Arată opțiunile biletului {{ $ticket->reference }}" title="Opțiunile biletului"><i data-lucide="chevron-down" size="15"></i></button>
                        <a class="btn secondary small" href="{{ $imported?route('tickets.imported.show',$ticket):route('tickets.show',$ticket) }}">Deschide</a>
                        <form method="post" action="{{ $imported?route('tickets.imported.destroy',$ticket):route('tickets.destroy',$ticket) }}" data-confirm="Ștergi definitiv biletul {{ $ticket->reference }}?" onsubmit="return confirm(this.dataset.confirm)">
                            @csrf @method('DELETE')
                            <button class="btn danger small ticket-delete-button" type="submit" aria-label="Șterge biletul {{ $ticket->reference }}" title="Șterge biletul">
                                <i data-lucide="trash-2" size="15"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <tr id="ticket-options-{{ $rowKey }}" class="ticket-drill-row" x-show="open==='{{ $rowKey }}'" x-cloak><td colspan="11" data-label="Opțiunile biletului">@include('tickets.partials.ticket-options', ['ticket'=>$ticket,'imported'=>$imported])</td></tr>
        @empty
            <tr><td colspan="11" class="empty">{{ collect($filters)->contains(fn($value)=>$value!=='')?'Nu există bilete pentru filtrele alese.':'Nu ai încă bilete.' }}</td></tr>
        @endforelse</tbody>
    </table></div>
</section>
@endsection
