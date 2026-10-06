<?php
namespace App\Jobs;
use App\Models\FootballMatch;
use App\Services\SourcePayloadStoreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
class ValidateProviderConflictsJob implements ShouldQueue { use Queueable; public function handle(SourcePayloadStoreService $store): void { $started=microtime(true);$conflicts=FootballMatch::where('data_status','conflict')->get();foreach($conflicts as $match)$match->recommendations()->update(['eligible'=>false]);$store->log('provider-resolver','conflict_validation',$conflicts->isEmpty()?'healthy':'conflict',$conflicts->count(),$conflicts->isEmpty()?'Nu există conflicte active.':$conflicts->count().' meciuri rămân excluse din recomandări din cauza conflictelor între surse.',$started); } }
