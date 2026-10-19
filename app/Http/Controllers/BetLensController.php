<?php
namespace App\Http\Controllers;

use App\Jobs\SyncFootballData;
use App\Jobs\VerifyDataSource;
use App\Models\FootballMatch;
use App\Models\GeneratedTicket;
use App\Models\GeneratedTicketSelection;
use App\Models\ImportedTicket;
use App\Models\ApplicationSetting;
use App\Models\DataSource;
use App\Models\League;
use App\Models\Market;
use App\Models\Odd;
use App\Models\Operator;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\FootballStatsService;
use App\Services\MatchDataService;
use App\Services\MatchUrlParser;
use App\Services\TeamAliasMatcher;
use App\Services\TicketBuilderService;
use App\Support\TicketAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BetLensController extends Controller
{
    public function dashboard()
    {
        $leagues=League::where('active',true)->withCount(['matches as upcoming_matches_count'=>fn($q)=>$q->where('kickoff_at','>',now())])->get();
        $recommendations=Recommendation::with(['match.league','match.homeTeam','match.awayTeam','market'])->where('eligible',true)->orderByDesc('score')->get();
        $upcomingMatches=FootballMatch::with(['league','homeTeam','awayTeam'])->where('kickoff_at','>',now())->orderBy('kickoff_at')->take(6)->get();
        return view('dashboard',compact('leagues','recommendations','upcomingMatches'));
    }

    public function generate(Request $request, TicketBuilderService $builder)
    {
        $data=$request->validate(['league_id'=>'nullable|exists:leagues,id','profile'=>'required|in:conservator,echilibrat,agresiv','stake'=>'nullable|numeric|min:0|max:100000']);
        $leagueId=$data['league_id'] ?? null;
        $query=Recommendation::with(['match.league','match.homeTeam','match.awayTeam','market'])->where('eligible',true)->whereHas('match',fn($q)=>$q->where('kickoff_at','>',now()));
        if($leagueId) $query->whereHas('match',fn($q)=>$q->where('league_id',$leagueId));
        $items=$builder->build($query->get(),$data['profile']);
        if($items->isEmpty()) {
            $matchesQuery=FootballMatch::where('kickoff_at','>',now());
            if($leagueId)$matchesQuery->where('league_id',$leagueId);
            if(!$matchesQuery->exists())$message='Nu există meciuri viitoare pentru criteriile alese.';
            elseif(!(clone $matchesQuery)->whereHas('odds')->exists())$message='Meciurile sunt disponibile, dar nu există încă cote reale. Configurează ODDS_API_KEY și verifică The Odds API din Sursa datelor.';
            else $message='Există cote, dar nu sunt suficiente selecții care trec pragurile profilului ales.';
            return back()->withInput()->with('warning',$message);
        }
        $totals=$builder->totals($items);
        $kickoffs=$items->pluck('match.kickoff_at')->filter()->sort()->values();
        $ticket=GeneratedTicket::create(['user_id'=>TicketAccess::userId($request),'league_id'=>$leagueId,'reference'=>'BL-'.strtoupper(Str::random(8)),'risk_profile'=>$data['profile'],'total_odds'=>$totals['odds'],'combined_probability'=>$totals['probability']/100,'stake'=>$data['stake']??null,'status'=>'pending','first_match_at'=>$kickoffs->first(),'last_match_at'=>$kickoffs->last()]);
        foreach($items as $item) $ticket->selections()->create(['recommendation_id'=>$item->id,'odds_at_creation'=>$item->odds]);
        return redirect()->route('tickets.show',$ticket)->with('success','Biletul a fost generat și salvat.');
    }

    public function matches(Request $request, MatchDataService $matches, MatchUrlParser $urlParser, TeamAliasMatcher $aliasMatcher)
    {
        $team1=trim((string) $request->query('team1', $request->query('team','')));
        $team2=trim((string) $request->query('team2',''));
        $matchUrl=trim((string) $request->query('match_url',''));
        $urlError=null;
        if ($matchUrl!=='') {
            $parsed=$urlParser->parse($matchUrl);
            if ($parsed) [$team1,$team2]=$parsed;
            else $urlError='Introdu un URL valid de meci Superbet sau Winbet, de forma echipa-1-vs-echipa-2-ID.';
        }
        $first=$aliasMatcher->normalize($team1);
        $second=$aliasMatcher->normalize($team2);
        $provider=$matchUrl!=='' ? $urlParser->provider($matchUrl) : null;
        $firstAliases=$aliasMatcher->aliasesFor($team1,$provider);
        $secondAliases=$aliasMatcher->aliasesFor($team2,$provider);
        $includeStarted=$first!=='' || $second!=='' || $matchUrl!=='';
        $items=$matches->searchable($includeStarted);
        if ($urlError) $items=$items->take(0);
        if($request->filled('league')) $items=$items->where('league_id',(int)$request->league);
        if($includeStarted) $items=$items->filter(function($match)use($team1,$team2,$firstAliases,$secondAliases,$aliasMatcher,$provider,$matchUrl){
            $sameOrder=$aliasMatcher->matches($match->homeTeam,$team1,$firstAliases,$provider)
                && $aliasMatcher->matches($match->awayTeam,$team2,$secondAliases,$provider);
            return $matchUrl!=='' ? $sameOrder : $sameOrder
                || ($aliasMatcher->matches($match->awayTeam,$team1,$firstAliases,$provider)
                    && $aliasMatcher->matches($match->homeTeam,$team2,$secondAliases,$provider));
        });
        $leagues=League::where('active',true)
            ->withCount(['matches as listed_matches_count'=>fn($query)=>$includeStarted ? $query : $query->where('kickoff_at','>',now())])
            ->orderBy('name')
            ->get();
        return view('matches.index',['matches'=>$items->values(),'leagues'=>$leagues,'team1'=>$team1,'team2'=>$team2,'includeStarted'=>$includeStarted,'matchUrl'=>$matchUrl,'urlError'=>$urlError]);
    }

    public function match(Request $request, FootballMatch $match, FootballStatsService $stats)
    {
        $match->load(['league','homeTeam','awayTeam','recommendations.market','latestWeatherSnapshot']);
        $ranked=$match->recommendations->sortByDesc(fn($recommendation)=>($recommendation->eligible?1000:0)+$recommendation->score+max(0,$recommendation->value*100));
        $bestRecommendation=$ranked->firstWhere('eligible',true)??$ranked->first();
        $alternatives=$ranked->filter(fn($recommendation)=>$recommendation->id!==$bestRecommendation?->id && ($recommendation->model_version==='poisson-v1'||$bestRecommendation?->model_version!=='poisson-v1'))->values();
        $bestOdd=null;
        if($bestRecommendation){$provider=$bestRecommendation->factors['bookmaker']??null;if($provider)$bestOdd=Odd::where(['match_id'=>$match->id,'market_id'=>$bestRecommendation->market_id,'selection'=>$bestRecommendation->selection,'provider'=>$provider])->first();}
        $ticketQuery=GeneratedTicket::whereHas('selections.recommendation',fn($query)=>$query->where('match_id',$match->id));
        if (! TicketAccess::admin($request)) {
            $ticketQuery->where('user_id', $request->user()?->id ?? 0);
        }
        $backTicket=$request->integer('ticket')?(clone $ticketQuery)->whereKey($request->integer('ticket'))->first():null;
        $backTicket??=$ticketQuery->latest()->first();
        return view('matches.show',['match'=>$match,'homeStats'=>$stats->summary($match->homeTeam),'awayStats'=>$stats->summary($match->awayTeam),'bestRecommendation'=>$bestRecommendation,'alternatives'=>$alternatives,'bestOdd'=>$bestOdd,'backTicket'=>$backTicket]);
    }

    public function tickets(Request $request)
    {
        $filters=$request->validate([
            'source'=>'nullable|in:betlens,import',
            'status'=>'nullable|in:pending,placed,won,lost,void',
            'from'=>'nullable|date_format:Y-m-d',
            'to'=>array_filter(['nullable','date_format:Y-m-d',$request->filled('from')?'after_or_equal:from':null]),
        ]);
        $filters=array_map(fn($value)=>$value??'',array_merge(['source'=>'','status'=>'','from'=>'','to'=>''],$filters));
        $sort=in_array($request->query('sort'),['reference','created_at','first_match_at','last_match_at','risk_profile','selections','total_odds','combined_probability','status'],true)?$request->query('sort'):'created_at';
        $direction=$request->query('direction')==='asc'?'asc':'desc';
        $admin=TicketAccess::admin($request)!==null;
        $generated=$filters['source']==='import'?collect():GeneratedTicket::with(['league','operator','selections.recommendation.market','selections.recommendation.match.league','selections.recommendation.match.homeTeam','selections.recommendation.match.awayTeam'])->when(! $admin,fn($query)=>$query->where('user_id',$request->user()->id))->get();
        $imported=$filters['source']==='betlens'?collect():ImportedTicket::with('selections')->when(! $admin,fn($query)=>$query->where('user_id',$request->user()->id))->get();
        $tickets=$generated->concat($imported)->filter(function($ticket)use($filters){
            $status=$ticket->status==='placed_winbet'?'placed':$ticket->status;
            $date=($ticket instanceof ImportedTicket?$ticket->placed_at:$ticket->created_at)->toDateString();
            return ($filters['status']===''||$status===$filters['status'])
                &&($filters['from']===''||$date>=$filters['from'])
                &&($filters['to']===''||$date<=$filters['to']);
        });
        $ticketStats=[
            'total'=>$tickets->count(),
            'placed'=>$tickets->filter(fn($ticket)=>in_array($ticket->status,['placed','placed_winbet','won','lost'],true))->count(),
            'won'=>$tickets->where('status','won')->count(),
            'lost'=>$tickets->where('status','lost')->count(),
        ];
        $value=fn($ticket)=>match($sort){'reference'=>strtolower($ticket->reference),'created_at'=>($ticket instanceof ImportedTicket?$ticket->placed_at:$ticket->created_at)->timestamp,'first_match_at'=>$ticket->effective_first_match_at?->timestamp??0,'last_match_at'=>$ticket->effective_last_match_at?->timestamp??0,'risk_profile'=>$ticket instanceof ImportedTicket?'Import':$ticket->risk_profile,'selections'=>$ticket->selections->count(),'total_odds'=>$ticket->total_odds,'combined_probability'=>$ticket instanceof ImportedTicket?-1:$ticket->combined_probability,'status'=>strtolower($ticket->status_label)};
        $tickets=($direction==='asc'?$tickets->sortBy($value,SORT_NATURAL|SORT_FLAG_CASE):$tickets->sortByDesc($value,SORT_NATURAL|SORT_FLAG_CASE))->values();
        return view('tickets.index',compact('tickets','ticketStats','sort','direction','filters'));
    }

    public function ticket(GeneratedTicket $ticket)
    {
        abort_unless(TicketAccess::canAccess(request(), $ticket->user_id), 404);
        $ticket->load(['league','selections.recommendation.match.league','selections.recommendation.match.homeTeam','selections.recommendation.match.awayTeam','selections.recommendation.match.providerMappings','selections.recommendation.market']);
        $operators=Operator::orderBy('name')->get();
        return view('tickets.show',compact('ticket','operators'));
    }

    public function importedTicket(ImportedTicket $importedTicket, \App\Services\ImportedTicketPresentationService $presenter)
    {
        abort_unless(TicketAccess::canAccess(request(), $importedTicket->user_id), 404);
        $importedTicket->load('selections');
        return view('tickets.imported-show', ['ticket' => $importedTicket,
            'selectionCards' => $importedTicket->selections->map(fn($selection) => $presenter->selection($selection))]);
    }

    public function destroyImportedTicket(ImportedTicket $importedTicket)
    {
        abort_unless(TicketAccess::canAccess(request(), $importedTicket->user_id), 404);
        $importedTicket->delete();
        return redirect()->route('tickets')->with('success', 'Biletul importat a fost șters.');
    }

    public function updateTicket(Request $request,GeneratedTicket $ticket)
    {
        abort_unless(TicketAccess::canAccess($request, $ticket->user_id), 404);
        if($request->has('reference')) $request->merge(['reference'=>trim((string)$request->input('reference'))]);
        $allowedStatuses=['pending','won','lost','void',...Operator::pluck('id')->map(fn($id)=>'placed:'.$id)->all()];
        $data=$request->validate([
            'status'=>['sometimes','required',\Illuminate\Validation\Rule::in($allowedStatuses)],
            'reference'=>'sometimes|required|string|max:100|unique:generated_tickets,reference,'.$ticket->id,
            'first_match_at'=>'sometimes|nullable|date',
            'last_match_at'=>'sometimes|nullable|date|after_or_equal:first_match_at',
        ],[
            'reference.required'=>'Introdu o referință pentru bilet.',
            'reference.unique'=>'Această referință este deja folosită de alt bilet.',
            'reference.max'=>'Referința poate avea maximum 100 de caractere.',
            'last_match_at.after_or_equal'=>'Ultimul meci nu poate fi înaintea primului meci.',
        ]);
        $submittedSections=(int)isset($data['status'])+(int)isset($data['reference'])+(int)(array_key_exists('first_match_at',$data)||array_key_exists('last_match_at',$data));
        if(isset($data['status'])&&str_starts_with($data['status'],'placed:')){$data['operator_id']=(int)str($data['status'])->after('placed:')->toString();$data['status']='placed';}
        elseif(isset($data['status']))$data['operator_id']=null;
        abort_if($data===[],422);
        $ticket->update($data);
        $message=$submittedSections>1?'Detaliile biletului au fost actualizate.':(isset($data['reference'])?'Referința biletului a fost actualizată.':(array_key_exists('first_match_at',$data)||array_key_exists('last_match_at',$data)?'Intervalul meciurilor a fost actualizat.':'Status actualizat.'));
        return back()->with('success',$message);
    }

    public function destroyTicket(GeneratedTicket $ticket)
    {
        abort_unless(TicketAccess::canAccess(request(), $ticket->user_id), 404);
        $ticket->delete();
        return redirect()->route('tickets')->with('success','Biletul a fost șters.');
    }

    public function removeSelection(GeneratedTicketSelection $selection, TicketBuilderService $builder)
    {
        $ownerId=GeneratedTicket::whereKey($selection->generated_ticket_id)->value('user_id');
        abort_unless(TicketAccess::canAccess(request(), $ownerId), 404);
        $ticket=$selection->generated_ticket_id; $selection->delete(); $this->refreshTicket($ticket,$builder); return back()->with('success','Selecția a fost eliminată.');
    }

    public function replaceSelection(GeneratedTicketSelection $selection, TicketBuilderService $builder)
    {
        $ticket=GeneratedTicket::with('selections.recommendation')->findOrFail($selection->generated_ticket_id);
        abort_unless(TicketAccess::canAccess(request(), $ticket->user_id), 404);
        $usedMatches=$ticket->selections->pluck('recommendation.match_id');
        $replacement=Recommendation::where('eligible',true)->whereNotIn('match_id',$usedMatches)->whereHas('match',fn($q)=>$q->where('kickoff_at','>',now()))->orderByDesc('score')->first();
        if(!$replacement) return back()->with('warning','Nu există o selecție alternativă eligibilă acum.');
        $selection->update(['recommendation_id'=>$replacement->id,'odds_at_creation'=>$replacement->odds]); $this->refreshTicket($ticket->id,$builder); return back()->with('success','Selecția a fost înlocuită.');
    }

    private function refreshTicket(int $ticketId,TicketBuilderService $builder): void
    {
        $ticket=GeneratedTicket::with('selections.recommendation')->findOrFail($ticketId); $items=$ticket->selections->pluck('recommendation'); $totals=$builder->totals($items); $ticket->update(['total_odds'=>$totals['odds'],'combined_probability'=>$totals['probability']/100]);
    }

    public function performance()
    {
        $tickets=GeneratedTicket::get(); $resolved=$tickets->whereIn('status',['won','lost']); $won=$resolved->where('status','won')->count();
        return view('performance',['leagues'=>League::withCount('matches')->get(),'tickets'=>$tickets,'recommendationCount'=>Recommendation::count(),'successRate'=>$resolved->count()?round($won/$resolved->count()*100,1):null]);
    }

    public function dataSources()
    {
        $sources=DataSource::withCount('records')->get()
            ->sort(function (DataSource $first, DataSource $second): int {
                $configuredOrder=(int) $second->is_configured <=> (int) $first->is_configured;

                return $configuredOrder ?: (strnatcasecmp($first->name, $second->name) ?: $first->id <=> $second->id);
            })->values();
        $weatherCount=\App\Models\MatchWeatherSnapshot::where('provider','open-meteo')->count();
        if($weather=$sources->firstWhere('slug','open-meteo'))$weather->records_count+=$weatherCount;
        return view('data-sources.index',compact('sources'));
    }

    public function verifyDataSource(DataSource $source)
    {
        if (!$source->is_configured) {
            return back()->with('warning', "Configurează {$source->name} înainte de verificare.");
        }
        $reserved=DataSource::whereKey($source->id)
            ->where(fn($query)=>$query->whereNull('last_manual_verification_at')->orWhere('last_manual_verification_at','<=',now()->subMinutes(5)))
            ->update(['last_manual_verification_at'=>now()]);
        if (!$reserved) {
            return back()->with('warning', "{$source->name} poate fi verificată din nou după 5 minute.");
        }
        if (request()->expectsJson()) {
            $runId=(string)\Illuminate\Support\Str::uuid();
            $source->update(['status'=>'checking','verification_run_id'=>$runId,'verification_progress'=>[
                'stage'=>'queued','message'=>'Verificarea a fost pusă în coadă.','processed'=>0,
                'detected'=>null,'new'=>0,'updated'=>0,'unchanged'=>0,'errors'=>0,
                'started_at'=>now()->toIso8601String(),
            ]]);
            VerifyDataSource::dispatch($source->id,$runId);
            return response()->json(['run_id'=>$runId,'progress_url'=>route('data-sources.progress',$source)],202);
        }
        VerifyDataSource::dispatchSync($source->id);
        $source->refresh();
        $message=$source->status==='healthy' ? "{$source->name} a fost verificată cu succes." : "Verificarea pentru {$source->name} s-a încheiat: {$source->status_label}.";
        $result=['name'=>$source->name,'status'=>$source->status,'status_label'=>$source->status_label,'checked_at'=>$source->last_checked_at?->format('d.m.Y · H:i:s'),'duration'=>$source->last_duration_ms,'detected'=>$source->records_checked,'database'=>$source->last_database_records,'new'=>$source->last_new_records,'updated'=>$source->last_updated_records,'unchanged'=>$source->last_unchanged_records,'errors'=>$source->last_error_records,'message'=>$source->last_message,'verification_only'=>(int)$source->records_checked===0];
        return back()->with($source->status==='healthy'?'success':'warning',$message)->with('verification_result',$result);
    }

    public function dataSourceProgress(DataSource $source)
    {
        $progress=$source->verification_progress??[];
        return response()->json([
            'run_id'=>$source->verification_run_id,
            'name'=>$source->name,
            'status'=>$source->status,
            'status_label'=>$source->status_label,
            'stage'=>$progress['stage']??'idle',
            'message'=>$progress['message']??'Nu există o verificare în curs.',
            'processed'=>$progress['processed']??0,
            'detected'=>$progress['detected']??null,
            'new'=>$progress['new']??0,
            'updated'=>$progress['updated']??0,
            'unchanged'=>$progress['unchanged']??0,
            'errors'=>$progress['errors']??0,
            'database'=>$source->status==='checking'?($progress['database']??$source->records()->count()):$source->last_database_records,
            'duration_ms'=>$source->status==='checking'&&isset($progress['started_at'])?
                (int)\Carbon\Carbon::parse($progress['started_at'])->diffInMilliseconds(now()):$source->last_duration_ms,
            'checked_at'=>$source->last_checked_at?->format('d.m.Y · H:i:s'),
        ])->header('Cache-Control','no-store');
    }

    public function adminLogin(Request $request){$userId=$request->session()->get('admin_user_id');return $userId&&User::whereKey($userId)->where('is_admin',true)->exists()?redirect()->route('admin'):view('admin.login');}
    public function adminAuthenticate(Request $request)
    {
        $data=$request->validate(['username'=>'required|string|max:100','password'=>'required|string|max:255']);
        $username=trim($data['username']);
        $user=User::where('is_admin',true)->where(fn($query)=>$query->where('username',$username)->orWhere('email',$username))->first();
        if(!$user||!Hash::check($data['password'],$user->password)) return back()->withErrors(['username'=>'Date de autentificare incorecte.'])->onlyInput('username');
        $request->session()->regenerate();
        Auth::logout();
        $request->session()->put('admin_user_id',$user->id);
        return redirect()->intended(route('admin'));
    }
    public function adminLogout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('admin.login');
    }
    public function admin(){return view('admin.index',['leagues'=>League::withCount(['teams','matches'])->get(),'markets'=>Market::get(),'settings'=>ApplicationSetting::where('group','risk')->get(),'sources'=>DataSource::whereNotNull('credential_env')->orderBy('name')->get(),'recommendations'=>Recommendation::with(['match.homeTeam','match.awayTeam'])->latest()->take(10)->get()]);}
    public function updateDataSourceKey(Request $request, DataSource $source)
    {
        abort_unless(in_array($source->credential_env, ['FOOTBALL_DATA_API_KEY','API_FOOTBALL_KEY','ODDS_API_KEY','ODDS_API_IO_KEY','ODDSPAPI_API_KEY','SPORTMONKS_API_TOKEN'], true), 404);
        $data=$request->validate(['credential_secret'=>'nullable|string|max:512','remove'=>'nullable|boolean']);
        if($request->boolean('remove')) {
            $source->update(['credential_secret'=>null,'status'=>filled(config(match($source->credential_env){
                'FOOTBALL_DATA_API_KEY'=>'services.football_data.key','API_FOOTBALL_KEY'=>'services.api_football.key',
                'ODDS_API_KEY'=>'services.odds_api.key','ODDS_API_IO_KEY'=>'services.odds_api_io.key',
                'ODDSPAPI_API_KEY'=>'services.oddspapi.key','SPORTMONKS_API_TOKEN'=>'services.sportmonks.token',
            }))?'never_checked':'not_configured']);
            return back()->with('success',"Cheia salvată în Admin pentru {$source->name} a fost eliminată.");
        }
        $secret=trim((string)($data['credential_secret']??''));
        if($secret==='')return back()->with('warning','Introdu o cheie nouă sau selectează eliminarea cheii din Admin.');
        $source->update(['credential_secret'=>$secret,'status'=>'never_checked']);
        return back()->with('success',"Cheia pentru {$source->name} a fost salvată. Verifică sursa pentru a confirma conexiunea.");
    }
    public function sync(){SyncFootballData::dispatch(); return back()->with('success','Sincronizarea a fost adăugată în coadă.');}
    public function toggleLeague(League $league){$league->update(['active'=>!$league->active]);return back()->with('success','Statusul ligii a fost actualizat.');}
    public function toggleMarket(Market $market){$market->update(['active'=>!$market->active]);return back()->with('success','Piața a fost actualizată.');}
    public function settings(Request $request){$data=$request->validate(['profile'=>'required|in:conservator,echilibrat,agresiv','min_probability'=>'required|numeric|min:0|max:1','min_value'=>'required|numeric|min:-1|max:1','min_confidence'=>'required|integer|min:0|max:100']);ApplicationSetting::updateOrCreate(['key'=>'risk.'.$data['profile']],['group'=>'risk','value'=>collect($data)->except('profile')->all()]);return back()->with('success','Pragurile au fost salvate.');}
}
