<?php
namespace App\Jobs;
use App\Models\DataSource;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Support\Facades\Log;
class SyncFootballData implements ShouldQueue { use FoundationQueueable; public int $tries=3; public array $backoff=[60,300,900]; public function handle(): void { DataSource::where('active',true)->each(fn($source)=>VerifyDataSource::dispatchSync($source->id)); Log::info('BetLens real data sources verification completed'); } }
