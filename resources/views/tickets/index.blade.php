@extends('layouts.app')
@section('title','Biletele mele')
@section('content')
<div class="page-head">
    <div>
        <p class="eyebrow">Arhivă personală</p>
        <h1>Biletele mele</h1>
        <p>Istoric informativ pentru selecțiile generate în BetLens.</p>
    </div>
    <a class="btn" href="{{ route('dashboard') }}"><i data-lucide="plus" size="17"></i>Bilet nou</a>
</div>

<section class="card">
    <table>
        <tr><th>Referință</th><th>Creat</th><th>Profil</th><th>Selecții</th><th>Cotă</th><th>Prob. combinată</th><th>Status</th><th></th></tr>
        @forelse($tickets as $ticket)
            <tr>
                <td><b>{{ $ticket->reference }}</b></td>
                <td>{{ $ticket->created_at->format('d.m.Y') }}</td>
                <td>{{ ucfirst($ticket->risk_profile) }}</td>
                <td>{{ $ticket->selections->count() }}</td>
                <td>{{ number_format($ticket->total_odds,2) }}</td>
                <td>{{ number_format($ticket->combined_probability*100,2) }}%</td>
                <td><span class="status {{ $ticket->status }}">{{ ['pending'=>'În așteptare','placed_winbet'=>'Plasat pe Winbet','won'=>'Câștigat','lost'=>'Pierdut','void'=>'Anulat'][$ticket->status] }}</span></td>
                <td>
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
            <tr><td colspan="8" class="empty">Nu ai generat încă niciun bilet.</td></tr>
        @endforelse
    </table>
</section>
@endsection
