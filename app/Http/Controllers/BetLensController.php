<?php
namespace App\Http\Controllers;

use App\Jobs\SyncFootballData;
use App\Jobs\VerifyDataSource;
use App\Models\FootballMatch;
use App\Models\GeneratedTicket;
use App\Models\GeneratedTicketSelection;
use App\Models\ApplicationSetting;
use App\Models\DataSource;
use App\Models\League;
use App\Models\Market;
use App\Models\Odd;
use App\Models\Recommendation;
use App\Services\FootballStatsService;
use App\Services\MatchDataService;
use App\Services\TicketBuilderService;
use Illuminate\Http\Request;
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
        $ticket=GeneratedTicket::create(['league_id'=>$leagueId,'reference'=>'BL-'.strtoupper(Str::random(8)),'risk_profile'=>$data['profile'],'total_odds'=>$totals['odds'],'combined_probability'=>$totals['probability']/100,'stake'=>$data['stake']??null,'status'=>'pending']);
        foreach($items as $item) $ticket->selections()->create(['recommendation_id'=>$item->id,'odds_at_creation'=>$item->odds]);
        return redirect()->route('tickets.show',$ticket)->with('success','Biletul a fost generat și salvat.');
    }

    public function matches(Request $request, MatchDataService $matches)
    {
        $items=$matches->upcoming();
        if($request->filled('league')) $items=$items->where('league_id',(int)$request->league);
        if($request->filled('team')) $items=$items->filter(fn($m)=>str_contains(strtolower($m->homeTeam->name.' '.$m->awayTeam->name),strtolower($request->team)));
        $leagues=League::where('active',true)
            ->withCount(['matches as upcoming_matches_count'=>fn($query)=>$query->where('kickoff_at','>',now())])
            ->orderBy('name')
            ->get();
        return view('matches.index',['matches'=>$items,'leagues'=>$leagues]);
    }

    public function match(Request $request, FootballMatch $match, FootballStatsService $stats)
    {
        $match->load(['league','homeTeam','awayTeam','recommendations.market']);
        $ranked=$match->recommendations->sortByDesc(fn($recommendation)=>($recommendation->eligible?1000:0)+$recommendation->score+max(0,$recommendation->value*100));
        $bestRecommendation=$ranked->firstWhere('eligible',true)??$ranked->first();
        $alternatives=$ranked->filter(fn($recommendation)=>$recommendation->id!==$bestRecommendation?->id && ($recommendation->model_version==='poisson-v1'||$bestRecommendation?->model_version!=='poisson-v1'))->values();
        $bestOdd=null;
        if($bestRecommendation){$provider=$bestRecommendation->factors['bookmaker']??null;if($provider)$bestOdd=Odd::where(['match_id'=>$match->id,'market_id'=>$bestRecommendation->market_id,'selection'=>$bestRecommendation->selection,'provider'=>$provider])->first();}
        $ticketQuery=GeneratedTicket::whereHas('selections.recommendation',fn($query)=>$query->where('match_id',$match->id));
        $backTicket=$request->integer('ticket')?(clone $ticketQuery)->whereKey($request->integer('ticket'))->first():null;
        $backTicket??=$ticketQuery->latest()->first();
        return view('matches.show',['match'=>$match,'homeStats'=>$stats->summary($match->homeTeam),'awayStats'=>$stats->summary($match->awayTeam),'bestRecommendation'=>$bestRecommendation,'alternatives'=>$alternatives,'bestOdd'=>$bestOdd,'backTicket'=>$backTicket]);
    }

    public function tickets()
    {
        return view('tickets.index',['tickets'=>GeneratedTicket::with(['league','selections.recommendation.match.homeTeam','selections.recommendation.match.awayTeam'])->latest()->get()]);
    }

    public function ticket(GeneratedTicket $ticket)
    {
        $ticket->load(['league','selections.recommendation.match.league','selections.recommendation.match.homeTeam','selections.recommendation.match.awayTeam','selections.recommendation.match.providerMappings','selections.recommendation.market']);
        return view('tickets.show',compact('ticket'));
    }

    public function updateTicket(Request $request,GeneratedTicket $ticket)
    {
        if($request->has('reference')) $request->merge(['reference'=>trim((string)$request->input('reference'))]);
        $data=$request->validate([
            'status'=>'sometimes|required|in:pending,placed_winbet,won,lost,void',
            'reference'=>'sometimes|required|string|max:100|unique:generated_tickets,reference,'.$ticket->id,
        ],[
            'reference.required'=>'Introdu o referință pentru bilet.',
            'reference.unique'=>'Această referință este deja folosită de alt bilet.',
            'reference.max'=>'Referința poate avea maximum 100 de caractere.',
        ]);
        abort_if($data===[],422);
        $ticket->update($data);
        return back()->with('success',isset($data['reference'])?'Referința biletului a fost actualizată.':'Status actualizat.');
    }

    public function destroyTicket(GeneratedTicket $ticket)
    {
        $ticket->delete();
        return redirect()->route('tickets')->with('success','Biletul a fost șters.');
    }

    public function removeSelection(GeneratedTicketSelection $selection, TicketBuilderService $builder)
    {
        $ticket=$selection->generated_ticket_id; $selection->delete(); $this->refreshTicket($ticket,$builder); return back()->with('success','Selecția a fost eliminată.');
    }

    public function replaceSelection(GeneratedTicketSelection $selection, TicketBuilderService $builder)
    {
        $ticket=GeneratedTicket::with('selections.recommendation')->findOrFail($selection->generated_ticket_id);
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
        $sources=DataSource::orderByRaw('LOWER(name) ASC')->orderBy('id')->get();
        return view('data-sources.index',compact('sources'));
    }

    public function verifyDataSource(DataSource $source)
    {
        VerifyDataSource::dispatchSync($source->id);
        $source->refresh();
        $message=$source->status==='healthy' ? "{$source->name} a fost verificată cu succes." : "Verificarea pentru {$source->name} s-a încheiat: {$source->status_label}.";
        $result=['name'=>$source->name,'status'=>$source->status,'status_label'=>$source->status_label,'checked_at'=>$source->last_checked_at?->format('d.m.Y · H:i:s'),'duration'=>$source->last_duration_ms,'detected'=>$source->records_checked,'database'=>$source->last_database_records,'new'=>$source->last_new_records,'updated'=>$source->last_updated_records,'unchanged'=>$source->last_unchanged_records,'errors'=>$source->last_error_records,'message'=>$source->last_message,'verification_only'=>(int)$source->records_checked===0];
        return back()->with($source->status==='healthy'?'success':'warning',$message)->with('verification_result',$result);
    }

    public function adminLogin(Request $request){return $request->session()->get('betlens_admin')===true?redirect()->route('admin'):view('admin.login');}
    public function adminAuthenticate(Request $request)
    {
        $data=$request->validate(['password'=>'required|string|max:255']);
        $hash=(string)config('services.betlens.admin_password_hash');
        abort_if($hash==='',503,'Autentificarea de administrator nu este configurată.');
        if(!Hash::check($data['password'],$hash)) return back()->withErrors(['password'=>'Date de autentificare incorecte.']);
        $request->session()->regenerate();
        $request->session()->put('betlens_admin',true);
        return redirect()->intended(route('admin'));
    }
    public function adminLogout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('admin.login');
    }
    public function admin(){return view('admin.index',['leagues'=>League::withCount(['teams','matches'])->get(),'markets'=>Market::get(),'settings'=>ApplicationSetting::where('group','risk')->get(),'recommendations'=>Recommendation::with(['match.homeTeam','match.awayTeam'])->latest()->take(10)->get()]);}
    public function sync(){SyncFootballData::dispatch(); return back()->with('success','Sincronizarea a fost adăugată în coadă.');}
    public function toggleLeague(League $league){$league->update(['active'=>!$league->active]);return back()->with('success','Statusul ligii a fost actualizat.');}
    public function toggleMarket(Market $market){$market->update(['active'=>!$market->active]);return back()->with('success','Piața a fost actualizată.');}
    public function settings(Request $request){$data=$request->validate(['profile'=>'required|in:conservator,echilibrat,agresiv','min_probability'=>'required|numeric|min:0|max:1','min_value'=>'required|numeric|min:-1|max:1','min_confidence'=>'required|integer|min:0|max:100']);ApplicationSetting::updateOrCreate(['key'=>'risk.'.$data['profile']],['group'=>'risk','value'=>collect($data)->except('profile')->all()]);return back()->with('success','Pragurile au fost salvate.');}
}
