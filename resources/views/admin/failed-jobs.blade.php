@extends('layouts.app')
@section('title', 'Failed jobs')
@section('content')
<div class="admin-failed-jobs-page">
    <header class="page-head">
        <div>
            <p class="eyebrow">Administrare · coadă</p>
            <h1>Failed jobs</h1>
            <p>Joburile pe care workerul Laravel nu a reușit să le proceseze.</p>
        </div>
        <span class="admin-failed-jobs-total">{{ number_format($failedJobs->total()) }} eșuate</span>
    </header>

    @forelse($failedJobs as $job)
        <article class="admin-failed-job-card">
            <header class="admin-failed-job-head">
                <div class="admin-failed-job-title">
                    <span class="admin-failed-job-icon"><i data-lucide="triangle-alert" size="19" aria-hidden="true"></i></span>
                    <div>
                        <h2>{{ $job->job_name }}</h2>
                        <p>UUID: <code>{{ $job->uuid }}</code></p>
                    </div>
                </div>
                <time datetime="{{ \Illuminate\Support\Carbon::parse($job->failed_at)->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($job->failed_at)->timezone(config('app.timezone'))->format('d.m.Y H:i:s') }}</time>
            </header>

            <div class="admin-failed-job-meta">
                <span><small>Conexiune</small><b>{{ $job->connection }}</b></span>
                <span><small>Queue</small><b>{{ $job->queue }}</b></span>
                <span class="admin-failed-job-error"><small>Eroare</small><b>{{ $job->exception_summary }}</b></span>
            </div>

            <details class="admin-failed-job-details">
                <summary>Vezi detaliile complete ale erorii</summary>
                <pre>{{ $job->exception_details }}</pre>
            </details>
            <footer class="admin-failed-job-actions">
                <form method="post" action="{{ route('admin.failed-jobs.destroy', $job->id) }}" onsubmit="return confirm('Ștergi această intrare din failed_jobs? Jobul nu va fi retrimis.');">
                    @csrf
                    @method('DELETE')
                    <button class="btn secondary danger" type="submit"><i data-lucide="trash-2" size="15" aria-hidden="true"></i>Șterge intrarea</button>
                </form>
            </footer>
        </article>
    @empty
        <section class="admin-failed-jobs-empty card">
            <i data-lucide="circle-check" size="28" aria-hidden="true"></i>
            <h2>Nu există joburi eșuate</h2>
            <p>Coada nu are momentan înregistrări în tabela <code>failed_jobs</code>.</p>
        </section>
    @endforelse

    @if($failedJobs->hasPages())
        <div class="admin-failed-jobs-pagination">{{ $failedJobs->links() }}</div>
    @endif
</div>
@endsection
