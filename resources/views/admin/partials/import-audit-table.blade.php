<section class="card source-table-card" id="import-audit" aria-labelledby="import-audit-heading">
    <div class="source-audit-heading"><h2 id="import-audit-heading">ImportAudit</h2><p>Ultimele rulări ale joburilor de import, indiferent de instanța care le-a executat.</p></div>
    <div class="source-table-wrap"><table class="source-table"><thead><tr><th>Rulat la</th><th>Job</th><th>Furnizor</th><th>Execuție</th><th>Importate</th><th>Nou / actualizat / șters</th><th>Status</th><th>Detalii</th><th>Acțiune</th></tr></thead><tbody>
    @forelse($importAudits as $audit)
        <tr><td data-label="Rulat la">{{ \Illuminate\Support\Carbon::parse($audit->started_at ?? $audit->imported_at)->format('d.m.Y H:i:s') }}</td><td data-label="Job">{{ class_basename($audit->job_name ?? $audit->import_type) }}</td><td data-label="Furnizor">{{ $audit->provider }}</td><td data-label="Execuție">{{ $audit->execution_source ?? 'Necunoscut' }}</td><td data-label="Importate">{{ number_format($audit->records_imported ?? ($audit->records_new + $audit->records_updated)) }}</td><td data-label="Nou / actualizat / șters">{{ $audit->records_new }} / {{ $audit->records_updated }} / {{ $audit->records_deleted }}</td><td data-label="Status"><span class="source-status {{ $audit->overall_status === 'success' ? 'healthy' : ($audit->overall_status === 'failed' ? 'unavailable' : 'degraded') }}"><span></span>{{ $audit->overall_status ?? $audit->status }}</span></td><td data-label="Detalii">{{ $audit->message ?: '—' }}</td><td data-label="Acțiune">@if(!in_array($audit->overall_status,['queued','running'],true))<form method="post" action="{{ route('admin.import-audit.destroy',$audit->id) }}" onsubmit="return confirm('Ștergi această intrare din audit? Datele importate nu vor fi șterse.')">@csrf @method('DELETE')<button class="btn danger small" type="submit"><i data-lucide="trash-2" size="14"></i>Șterge</button></form>@else<span class="source-audit-pending">În curs</span>@endif</td></tr>
    @empty
        <tr><td colspan="9">Nu există încă rulări de import auditate.</td></tr>
    @endforelse
    </tbody></table></div>
    {{ $importAudits->fragment('import-audit')->links() }}
</section>
