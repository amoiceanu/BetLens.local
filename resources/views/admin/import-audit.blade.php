@extends('layouts.app')
@section('title','Audit importuri')
@section('content')
<div class="page-head"><div><p class="eyebrow">Administrare · trasabilitate</p><h1>Audit importuri</h1><p>Istoricul rulărilor de import din baza de date conectată.</p></div><a class="btn secondary" href="{{ route('data-sources') }}"><i data-lucide="arrow-left" size="17"></i>Înapoi la surse</a></div>
<form class="import-audit-filters card" method="get" action="{{ route('admin.import-audit') }}">
    <div class="import-audit-filter-field">
        <label for="audit-job-filter">Job</label>
        <select class="field" id="audit-job-filter" name="job">
            <option value="">Toate joburile</option>
            @foreach($jobOptions as $job)
                <option value="{{ $job }}" @selected(($filters['job'] ?? '') === $job)>{{ class_basename($job) }}</option>
            @endforeach
        </select>
    </div>
    <div class="import-audit-filter-field">
        <label for="audit-provider-filter">Furnizor</label>
        <select class="field" id="audit-provider-filter" name="provider">
            <option value="">Toți furnizorii</option>
            @foreach($providerOptions as $provider)
                <option value="{{ $provider }}" @selected(($filters['provider'] ?? '') === $provider)>{{ $providerNames[$provider] ?? $provider }}</option>
            @endforeach
        </select>
    </div>
    <button class="btn import-audit-filter-submit" type="submit"><i data-lucide="filter" size="15"></i>Aplică filtrul</button>
    @if(filled($filters['job'] ?? null) || filled($filters['provider'] ?? null))<a class="btn secondary import-audit-filter-reset" href="{{ route('admin.import-audit') }}"><i data-lucide="rotate-ccw" size="15"></i>Resetează</a>@endif
</form>
@include('admin.partials.import-audit-table')
@endsection
