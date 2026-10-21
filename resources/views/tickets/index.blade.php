@extends('layouts.app')
@section('title','Biletele mele')
@section('content')
@php
    $sortUrl=fn($column)=>route('tickets',array_filter($filters,fn($value)=>$value!=='')+['sort'=>$column,'direction'=>$sort===$column&&$direction==='asc'?'desc':'asc']);
    $sortDirection=fn($column)=>$sort===$column?$direction:null;
    $columns=['reference'=>['Referință','file-text'],'created_at'=>['Creat','calendar-days'],'first_match_at'=>['Data meciului','calendar-days'],'risk_profile'=>['Profil','chart-no-axes-column'],'selections'=>['Selecții','layout-grid'],'total_odds'=>['Cotă','coins'],'combined_probability'=>['Probabilitate','chart-no-axes-combined'],'status'=>['Status','bookmark']];
@endphp
<div class="ticket-archive-page">
<div class="page-head ticket-archive-hero">
    <div class="ticket-archive-title">
        <span class="ticket-archive-icon"><i data-lucide="ticket-check" size="26" aria-hidden="true"></i></span>
        <div>
        <p class="eyebrow">Arhivă personală</p>
        <h1>Biletele mele</h1>
        <p>Istoric informativ pentru selecțiile generate în BetLens și biletele importate.</p>
        </div>
    </div>
    <a class="btn ticket-new-button" href="{{ route('dashboard') }}"><i data-lucide="plus" size="18"></i>Bilet nou</a>
</div>

<form class="card ticket-filter-form" method="get" action="{{ route('tickets') }}" aria-label="Filtrează biletele">
    <label><span class="ticket-filter-label"><i data-lucide="file-text" size="15"></i>Sursă</span>
        <select class="field" name="source"><option value="">Toate sursele</option><option value="betlens" @selected($filters['source']==='betlens')>BetLens</option><option value="import" @selected($filters['source']==='import')>Import</option></select>
    </label>
    <label><span class="ticket-filter-label"><i data-lucide="circle-check" size="15"></i>Status</span>
        <select class="field" name="status"><option value="">Toate statusurile</option>@foreach(['pending'=>'În așteptare','placed'=>'Plasat','won'=>'Câștigat','lost'=>'Pierdut','void'=>'Anulat'] as $value=>$label)<option value="{{ $value }}" @selected($filters['status']===$value)>{{ $label }}</option>@endforeach</select>
    </label>
    <label><span class="ticket-filter-label"><i data-lucide="calendar-days" size="15"></i>De la</span>
        <input class="field" type="date" name="from" value="{{ $filters['from'] }}" aria-label="Data biletului de la">
    </label>
    <label><span class="ticket-filter-label"><i data-lucide="flag" size="15"></i>Până la</span>
        <input class="field" type="date" name="to" value="{{ $filters['to'] }}" aria-label="Data biletului până la">
    </label>
    <div class="ticket-filter-actions"><button class="btn" type="submit"><i data-lucide="filter" size="16"></i>Aplică filtrele</button><a class="btn secondary" href="{{ route('tickets') }}">Resetează filtrele</a></div>
    @error('to')<p class="ticket-filter-error">{{ $message }}</p>@enderror
</form>

<section class="ticket-stats" aria-label="Statistici bilete">
    <div class="card ticket-stat ticket-stat-placed">
        <span class="ticket-stat-icon"><i data-lucide="ticket-check" size="22" aria-hidden="true"></i></span>
        <div class="ticket-stat-copy"><span class="ticket-stat-label">Plasate</span><strong>{{ $ticketStats['placed'] }}</strong><span class="ticket-stat-detail">{{ $ticketStats['total'] ? number_format($ticketStats['placed'] / $ticketStats['total'] * 100, 0, ',', '.') : '0' }}% din biletele afișate</span></div>
    </div>
    <div class="card ticket-stat ticket-stat-won">
        <span class="ticket-stat-icon"><i data-lucide="circle-check" size="22" aria-hidden="true"></i></span>
        <div class="ticket-stat-copy"><span class="ticket-stat-label">Câștigate</span><strong>{{ $ticketStats['won'] }}</strong><span class="ticket-stat-detail">{{ $ticketStats['placed'] ? number_format($ticketStats['won'] / $ticketStats['placed'] * 100, 0, ',', '.') : '0' }}% din biletele plasate</span></div>
    </div>
    <div class="card ticket-stat ticket-stat-lost">
        <span class="ticket-stat-icon"><i data-lucide="circle-x" size="22" aria-hidden="true"></i></span>
        <div class="ticket-stat-copy"><span class="ticket-stat-label">Pierdute</span><strong>{{ $ticketStats['lost'] }}</strong><span class="ticket-stat-detail">{{ $ticketStats['placed'] ? number_format($ticketStats['lost'] / $ticketStats['placed'] * 100, 0, ',', '.') : '0' }}% din biletele plasate</span></div>
    </div>
</section>

<section class="card ticket-list-card"><div class="ticket-list-table-wrap">
    <table class="ticket-history-table" aria-label="Istoricul biletelor">
        <colgroup><col class="col-number"><col class="col-reference"><col class="col-created"><col class="col-match"><col class="col-profile"><col class="col-selections"><col class="col-odds"><col class="col-probability"><col class="col-status"><col class="col-actions"></colgroup>
        <thead><tr>
            <th scope="col" class="ticket-row-number"><span class="ticket-header-content">#</span></th>
            @foreach($columns as $column=>$details)
                @php($sortValue=$sortDirection($column))
                <th scope="col" aria-sort="{{ $sortValue===null?'none':($sortValue==='asc'?'ascending':'descending') }}"><a class="ticket-sort {{ $sort===$column?'active':'' }}" href="{{ $sortUrl($column) }}" aria-label="Sortează după {{ $details[0] }}"><i class="ticket-header-icon" data-lucide="{{ $details[1] }}" size="15" aria-hidden="true"></i><span>{{ $details[0] }}</span><i class="ticket-sort-indicator" data-lucide="{{ $sortValue==='asc'?'arrow-up':($sortValue==='desc'?'arrow-down':'arrow-up-down') }}" size="13" aria-hidden="true"></i></a></th>
            @endforeach
            <th scope="col"><span class="ticket-header-content"><i data-lucide="settings-2" size="15" aria-hidden="true"></i>Acțiuni</span></th>
        </tr></thead>
        <tbody x-data="{open:null}">@forelse($tickets as $ticket)
            @php($imported=$ticket instanceof \App\Models\ImportedTicket)
            @php($rowKey=($imported?'import-':'betlens-').$ticket->id)
            <tr>
                <td class="ticket-row-number" data-label="Nr.">{{ $tickets->count() - $loop->index }}</td>
                <td data-label="Referință"><b class="ticket-reference">{{ $ticket->reference }}</b><span class="ticket-source-label">{{ $imported?'Import SuperBet':'BetLens' }}</span></td>
                <td data-label="Creat"><span class="ticket-date-main">{{ ($imported?$ticket->placed_at:$ticket->created_at)->format('d.m.Y') }}</span><span class="ticket-date-secondary">{{ ($imported?$ticket->placed_at:$ticket->created_at)->format('H:i') }}</span></td>
                <td data-label="Data meciului" class="ticket-match-date">
                    @if($ticket->effective_first_match_at && $ticket->effective_last_match_at && !$ticket->effective_first_match_at->equalTo($ticket->effective_last_match_at))
                        <span class="ticket-date-main">{{ $ticket->effective_first_match_at->format('d.m.Y H:i') }}</span><span class="ticket-date-secondary">până la {{ $ticket->effective_last_match_at->format('d.m.Y H:i') }}</span>
                    @else
                        <span class="ticket-date-main">{{ $ticket->effective_first_match_at?->format('d.m.Y') ?? '—' }}</span><span class="ticket-date-secondary">{{ $ticket->effective_first_match_at?->format('H:i') }}</span>
                    @endif
                </td>
                <td data-label="Profil"><span class="ticket-profile-badge">{{ $imported?'Import':ucfirst($ticket->risk_profile) }}</span></td>
                <td data-label="Selecții" class="ticket-number-cell">{{ $ticket->selections->count() }}</td>
                <td data-label="Cotă" class="ticket-number-cell"><b>{{ number_format($ticket->total_odds,2) }}</b></td>
                <td data-label="Probabilitate" class="ticket-number-cell">{{ $imported?'—':number_format($ticket->combined_probability*100,2).'%' }}</td>
                <td data-label="Status"><span class="status {{ $ticket->status }} ticket-status-badge">{{ $ticket->status_label }}</span></td>
                <td class="ticket-actions-cell">
                    <div class="ticket-list-actions">
                        <button class="btn secondary small ticket-options-toggle" type="button" @click="open=open==='{{ $rowKey }}'?null:'{{ $rowKey }}'" :aria-expanded="(open==='{{ $rowKey }}').toString()" aria-controls="ticket-options-{{ $rowKey }}" aria-label="Arată opțiunile biletului {{ $ticket->reference }}" title="Opțiunile biletului"><i data-lucide="chevron-down" size="15"></i></button>
                        <a class="btn secondary small ticket-open-button" href="{{ $imported?route('tickets.imported.show',$ticket):route('tickets.show',$ticket) }}" title="Deschide biletul"><i data-lucide="eye" size="14" aria-hidden="true"></i>Deschide</a>
                        <form method="post" action="{{ $imported?route('tickets.imported.destroy',$ticket):route('tickets.destroy',$ticket) }}" data-confirm="Ștergi definitiv biletul {{ $ticket->reference }}?" onsubmit="return confirm(this.dataset.confirm)">
                            @csrf @method('DELETE')
                            <button class="btn danger small ticket-delete-button" type="submit" aria-label="Șterge biletul {{ $ticket->reference }}" title="Șterge biletul">
                                <i data-lucide="trash-2" size="15"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <tr id="ticket-options-{{ $rowKey }}" class="ticket-drill-row" x-show="open==='{{ $rowKey }}'" x-cloak><td colspan="10" data-label="Opțiunile biletului">@include('tickets.partials.ticket-options', ['ticket'=>$ticket,'imported'=>$imported])</td></tr>
        @empty
            <tr><td colspan="10" class="empty">{{ collect($filters)->contains(fn($value)=>$value!=='')?'Nu există bilete pentru filtrele alese.':'Nu ai încă bilete.' }}</td></tr>
        @endforelse</tbody>
    </table></div>
</section>
</div>
@endsection
