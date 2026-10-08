@extends('layouts.app')
@section('title','Biletele mele')
@section('content')
@php
    $sortUrl=fn($column)=>route('tickets',['sort'=>$column,'direction'=>$sort===$column&&$direction==='asc'?'desc':'asc']);
    $sortDirection=fn($column)=>$sort===$column?$direction:null;
@endphp
<div class="page-head">
    <div>
        <p class="eyebrow">Arhivă personală</p>
        <h1>Biletele mele</h1>
        <p>Istoric informativ pentru selecțiile generate în BetLens.</p>
    </div>
    <a class="btn" href="{{ route('dashboard') }}"><i data-lucide="plus" size="17"></i>Bilet nou</a>
</div>

<section class="card ticket-list-card"><div class="ticket-list-table-wrap">
    <table class="ticket-history-table">
        <thead><tr>
            @foreach(['reference'=>'Referință','created_at'=>'Creat','first_match_at'=>'Primul meci','last_match_at'=>'Ultimul meci','risk_profile'=>'Profil','selections'=>'Selecții','total_odds'=>'Cotă','combined_probability'=>'Prob. combinată','status'=>'Status'] as $column=>$label)
                <th><a class="ticket-sort {{ $sort===$column?'active':'' }}" href="{{ $sortUrl($column) }}">{{ $label }}<i data-lucide="{{ $sortDirection($column)==='asc'?'arrow-up':($sortDirection($column)==='desc'?'arrow-down':'arrow-up-down') }}" size="12"></i></a></th>
            @endforeach
            <th><span class="visually-hidden">Acțiuni</span></th>
        </tr></thead>
        <tbody>@forelse($tickets as $ticket)
            <tr>
                <td data-label="Referință"><b>{{ $ticket->reference }}</b></td>
                <td data-label="Creat">{{ $ticket->created_at->format('d.m.Y') }}</td>
                <td data-label="Primul meci" class="ticket-match-date">{{ $ticket->effective_first_match_at?->format('d.m.Y H:i') ?? '—' }}</td>
                <td data-label="Ultimul meci" class="ticket-match-date">{{ $ticket->effective_last_match_at?->format('d.m.Y H:i') ?? '—' }}</td>
                <td data-label="Profil">{{ ucfirst($ticket->risk_profile) }}</td>
                <td data-label="Selecții">{{ $ticket->selections->count() }}</td>
                <td data-label="Cotă">{{ number_format($ticket->total_odds,2) }}</td>
                <td data-label="Prob. combinată">{{ number_format($ticket->combined_probability*100,2) }}%</td>
                <td data-label="Status"><span class="status {{ $ticket->status }}">{{ $ticket->status_label }}</span></td>
                <td class="ticket-actions-cell">
                    <div class="ticket-list-actions">
                        <a class="btn secondary small" href="{{ route('tickets.show',$ticket) }}">Deschide</a>
                        <form method="post" action="{{ route('tickets.destroy',$ticket) }}" data-confirm="Ștergi definitiv biletul {{ $ticket->reference }}?" onsubmit="return confirm(this.dataset.confirm)">
                            @csrf @method('DELETE')
                            <button class="btn danger small ticket-delete-button" type="submit" aria-label="Șterge biletul {{ $ticket->reference }}" title="Șterge biletul">
                                <i data-lucide="trash-2" size="15"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="10" class="empty">Nu ai generat încă niciun bilet.</td></tr>
        @endforelse</tbody>
    </table></div>
</section>
@endsection
