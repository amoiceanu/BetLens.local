@extends('layouts.app')
@section('title','Audit importuri')
@section('content')
<div class="page-head"><div><p class="eyebrow">Administrare · trasabilitate</p><h1>Audit importuri</h1><p>Istoricul rulărilor de import din baza de date conectată.</p></div><a class="btn secondary" href="{{ route('data-sources') }}"><i data-lucide="arrow-left" size="17"></i>Înapoi la surse</a></div>
@include('admin.partials.import-audit-table')
@endsection
