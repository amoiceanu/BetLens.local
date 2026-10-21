<?php

namespace Tests\Feature;

use App\Jobs\ImportUpcomingMatchesJob;
use App\Models\DataSource;
use App\Services\ImportAuditService;
use App\Services\OpenLigaDataImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpcomingImportJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_upcoming_job_persists_progress_and_finishes_with_import_counters(): void
    {
        $source=DataSource::where('slug','openligadb')->firstOrFail();
        $run=$source->importRuns()->create(['status'=>'queued','import_method'=>'existing','execution_source'=>'Laravel-local']);
        $metrics=['leagues'=>1,'read'=>2,'matches_new'=>1,'matches_updated'=>1,'matches_unchanged'=>0,
            'predictions_new'=>1,'predictions_unchanged'=>0,'errors'=>[]];
        $importer=\Mockery::mock(OpenLigaDataImporter::class);
        $importer->shouldReceive('importUpcomingMatches')->once()->withArgs(function($days,$progress){
            $this->assertSame(7,$days);
            $progress('connecting','Bundesliga',['leagues'=>1,'read'=>2,'matches_new'=>1,'matches_updated'=>0,
                'matches_unchanged'=>0,'predictions_new'=>0,'predictions_unchanged'=>0,'errors'=>[]]);
            return is_callable($progress);
        })->andReturn($metrics);
        $audit=\Mockery::mock(ImportAuditService::class);
        $audit->shouldReceive('syncRun')->times(3);

        (new ImportUpcomingMatchesJob($run->id,7))->handle($importer,$audit);

        $run->refresh();
        $this->assertSame('completed',$run->status);
        $this->assertSame(2,$run->records_fetched);
        $this->assertSame(1,$run->records_new);
        $this->assertSame(1,$run->records_updated);
        $this->assertSame('finished',$run->metadata['stage']);
        $this->assertSame(1,$run->metadata['predictions_new']);
    }

    public function test_failed_job_marks_a_run_failed_once_and_preserves_completed_runs(): void
    {
        $source=DataSource::where('slug','openligadb')->firstOrFail();
        $run=$source->importRuns()->create(['status'=>'running','import_method'=>'existing','execution_source'=>'Laravel-local','records_errors'=>0]);
        $audit=\Mockery::mock(ImportAuditService::class);
        $audit->shouldReceive('syncRun')->once();
        app()->instance(ImportAuditService::class,$audit);

        (new ImportUpcomingMatchesJob($run->id,7))->failed(new \RuntimeException('private provider details'));

        $run->refresh();
        $this->assertSame('failed',$run->status);
        $this->assertSame(1,$run->records_errors);
        $this->assertSame('failed',$run->metadata['stage']);
        $this->assertStringNotContainsString('private provider details',$run->message);

        $run->update(['status'=>'completed','finished_at'=>now()]);
        (new ImportUpcomingMatchesJob($run->id,7))->failed(new \RuntimeException('ignored'));
        $this->assertSame('completed',$run->fresh()->status);
    }
}
