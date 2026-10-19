<?php

namespace Tests\Feature;

use App\Models\ImportedTicket;
use App\Models\GeneratedTicket;
use App\Models\FootballMatch;
use App\Models\League;
use App\Models\Market;
use App\Models\Recommendation;
use App\Models\Team;
use Database\Seeders\SuperbetScreenshotTicketsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportedSuperbetTicketsTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(\App\Models\User::factory()->create(['is_admin'=>true]));
    }

    public function test_screenshot_import_is_idempotent_and_visible_in_history(): void
    {
        $this->seed(SuperbetScreenshotTicketsSeeder::class);
        $this->seed(SuperbetScreenshotTicketsSeeder::class);

        $this->assertDatabaseCount('imported_tickets', 11);
        $this->assertDatabaseCount('imported_ticket_selections', 11);
        $this->get(route('tickets'))->assertOk()->assertSee('Import SuperBet')->assertSee('899B-2U3TSI')->assertSee('891N-1I7VNA')->assertSee('891C-1I8DS5')
            ->assertSeeInOrder(['data-label="Nr.">11','891C-1I8DS5','data-label="Nr.">10','891N-1I7VNA'], false);
    }

    public function test_import_skips_an_existing_superbet_reference_without_changing_it(): void
    {
        $existing = ImportedTicket::create([
            'source'=>'superbet', 'reference'=>'891N-1I7VNA', 'placed_at'=>'2026-10-17 23:32:00',
            'status'=>'won', 'total_odds'=>1.20, 'stake'=>25, 'payout'=>30, 'currency'=>'RON',
        ]);

        $this->seed(SuperbetScreenshotTicketsSeeder::class);

        $this->assertDatabaseCount('imported_tickets', 11);
        $this->assertDatabaseHas('imported_tickets', ['id'=>$existing->id, 'reference'=>'891N-1I7VNA', 'total_odds'=>1.20]);
        $this->assertDatabaseMissing('imported_ticket_selections', ['imported_ticket_id'=>$existing->id]);
    }

    public function test_history_statistics_include_imported_tickets_and_follow_filters(): void
    {
        $this->seed(SuperbetScreenshotTicketsSeeder::class);
        GeneratedTicket::create([
            'reference'=>'BL-PENDING-STATS','risk_profile'=>'conservator','total_odds'=>1.5,
            'combined_probability'=>0.66,'status'=>'pending','created_at'=>'2026-10-17 12:00:00',
        ]);

        $this->get(route('tickets'))->assertOk()
            ->assertSee('55% din biletele plasate')
            ->assertSee('92% din biletele afișate');
        $this->get(route('tickets',['source'=>'import','status'=>'won']))->assertOk()
            ->assertSee('100% din biletele plasate')
            ->assertDontSee('BL-PENDING-STATS');
    }

    public function test_lost_ticket_explains_the_failed_selection(): void
    {
        $this->seed(SuperbetScreenshotTicketsSeeder::class);
        $ticket = ImportedTicket::where('reference', '891F-1SZJH2')->firstOrFail();

        $this->get(route('tickets.imported.show', $ticket))
            ->assertOk()->assertSee('De ce a pierdut selecția?')->assertSee('7 goluri');
    }

    public function test_missing_explanation_uses_the_requested_fallback(): void
    {
        $this->seed(SuperbetScreenshotTicketsSeeder::class);
        $ticket = ImportedTicket::where('status', 'lost')->firstOrFail();
        $ticket->selections()->firstOrFail()->update(['failure_reason' => null]);

        $this->get(route('tickets.imported.show', $ticket))
            ->assertOk()->assertSee('Nu am nicio recomandare pentru acest bilet.');
    }

    public function test_history_filters_source_status_and_inclusive_date_range(): void
    {
        $this->seed(SuperbetScreenshotTicketsSeeder::class);
        GeneratedTicket::create([
            'reference'=>'BL-FILTER-TEST','risk_profile'=>'conservator','total_odds'=>1.5,
            'combined_probability'=>0.66,'status'=>'lost','created_at'=>'2026-10-17 12:00:00',
        ]);

        $this->get(route('tickets',['source'=>'import','status'=>'lost','from'=>'2026-10-17','to'=>'2026-10-17']))
            ->assertOk()->assertSee('891F-1SZJH2')->assertDontSee('BL-FILTER-TEST')
            ->assertDontSee('898S-E5F6TA')->assertDontSee('8914-YQPYBM')
            ->assertSee('source=import',false)->assertSee('status=lost',false);
        $this->get(route('tickets',['source'=>'betlens','status'=>'lost','from'=>'2026-10-17','to'=>'2026-10-17']))
            ->assertOk()->assertSee('BL-FILTER-TEST')->assertDontSee('891F-1SZJH2');
        $this->get(route('tickets'))->assertOk()->assertSee('BL-FILTER-TEST')->assertSee('891F-1SZJH2');
    }

    public function test_date_filters_work_independently_and_reject_reversed_range(): void
    {
        $this->seed(SuperbetScreenshotTicketsSeeder::class);
        $this->get(route('tickets',['to'=>'2026-10-16']))->assertOk()
            ->assertSee('898S-E5F6TA')->assertDontSee('891F-1SZJH2');
        $this->get(route('tickets',['from'=>'2026-10-17']))->assertOk()
            ->assertSee('891F-1SZJH2')->assertDontSee('898S-E5F6TA');
        $this->get(route('tickets',['from'=>'2026-10-18','to'=>'2026-10-17']))
            ->assertSessionHasErrors('to');
    }

    public function test_empty_form_fields_do_not_hide_imported_tickets(): void
    {
        $this->seed(SuperbetScreenshotTicketsSeeder::class);

        $this->get(route('tickets',['source'=>'import','status'=>'','from'=>'','to'=>'']))
            ->assertOk()->assertSee('891F-1SZJH2')->assertSee('898S-E5F6TA')
            ->assertDontSee('Nu există bilete pentru filtrele alese.');
    }

    public function test_imported_details_use_real_values_and_empty_analytics_states(): void
    {
        $this->seed(SuperbetScreenshotTicketsSeeder::class);
        $ticket=ImportedTicket::where('reference','891F-1SZJH2')->firstOrFail();

        $this->get(route('tickets.imported.show',$ticket))->assertOk()
            ->assertSee('Bilet #891F-1SZJH2')->assertSee('Importat din SuperBet')
            ->assertSee('2 – 5')->assertSee('Goluri totale')->assertSee('Linie goluri')
            ->assertDontSee('Nu am nicio recomandare pentru acest bilet.')->assertSee('Nedisponibil')
            ->assertSee('Date insuficiente pentru evaluarea riscului.')
            ->assertDontSee('24%')->assertDontSee('3.90');
    }

    public function test_winning_result_explanation_is_based_on_the_recorded_score(): void
    {
        $this->seed(SuperbetScreenshotTicketsSeeder::class);
        $ticket=ImportedTicket::where('reference','8914-YQPYBM')->firstOrFail();

        $this->get(route('tickets.imported.show',$ticket))->assertOk()
            ->assertSee('De ce a câștigat selecția?')
            ->assertSee('6–1')->assertSee('11.00 RON')
            ->assertDontSee('Selecție cu risc scăzut');
    }

    public function test_history_drill_down_contains_imported_and_betlens_selections(): void
    {
        $this->seed(SuperbetScreenshotTicketsSeeder::class);
        $league=League::create(['name'=>'Liga Test','slug'=>'ticket-drill-test','code'=>'LT','active'=>true]);
        $home=Team::create(['league_id'=>$league->id,'name'=>'Gazdele Test','short_name'=>'Gazdele']);
        $away=Team::create(['league_id'=>$league->id,'name'=>'Oaspeții Test','short_name'=>'Oaspeții']);
        $match=FootballMatch::create(['league_id'=>$league->id,'home_team_id'=>$home->id,'away_team_id'=>$away->id,'kickoff_at'=>'2026-10-20 18:00:00']);
        $market=Market::create(['key'=>'result-test','name'=>'Rezultat final','active'=>true]);
        $recommendation=Recommendation::create(['match_id'=>$match->id,'market_id'=>$market->id,'selection'=>'1X',
            'odds'=>1.45,'model_probability'=>0.7,'implied_probability'=>0.69,'value'=>0.01,
            'confidence'=>'ridicat','score'=>80,'explanation'=>'Test','eligible'=>true]);
        $ticket=GeneratedTicket::create(['reference'=>'BL-DRILL','risk_profile'=>'conservator','total_odds'=>1.45,'combined_probability'=>0.7,'status'=>'pending']);
        $ticket->selections()->create(['recommendation_id'=>$recommendation->id,'odds_at_creation'=>1.45]);
        $imported=ImportedTicket::where('reference','891F-1SZJH2')->firstOrFail();

        $this->get(route('tickets'))->assertOk()
            ->assertSee('ticket-options-import-'.$imported->id)->assertSee('Betis – Barcelona')
            ->assertSee('ticket-options-betlens-'.$ticket->id)->assertSee('Gazdele Test – Oaspeții Test')
            ->assertSee('Rezultat final · ')->assertSee('1X');
    }

    public function test_pending_unknown_and_multiple_matches_render_without_invented_scores(): void
    {
        $ticket=ImportedTicket::create(['source'=>'superbet','reference'=>'MULTI-TEST','placed_at'=>'2026-10-17 12:00:00',
            'status'=>'pending','total_odds'=>2.5,'stake'=>10,'payout'=>null,'currency'=>'RON']);
        $ticket->selections()->create(['position'=>1,'competition'=>'Franța · Ligue 1','kickoff_at'=>'2026-10-18 20:00:00',
            'home_team'=>'Echipă gazdă cu un nume foarte lung','away_team'=>'Echipă oaspete cu nume foarte lung',
            'market_label'=>'Total goluri','selection_label'=>'Peste 2.5','odds'=>null,'result'=>'pending']);
        $ticket->selections()->create(['position'=>2,'competition'=>'Italia · Serie A','kickoff_at'=>'2026-10-19 20:00:00',
            'home_team'=>'Roma','away_team'=>'Lazio','market_label'=>'Final','selection_label'=>'1','odds'=>1.8,'result'=>'unknown']);

        $this->get(route('tickets.imported.show',$ticket))->assertOk()
            ->assertSee('În așteptare')->assertSee('Câștig potențial')->assertSee('25.00 RON')
            ->assertSee('Echipă gazdă cu un nume foarte lung')->assertSee('Roma')
            ->assertSee('SCOR INDISPONIBIL')->assertSee('biletul complet')
            ->assertDontSee('Goluri totale');
        $ticket->update(['status'=>'unrecognized']);
        $this->get(route('tickets.imported.show',$ticket))->assertOk()->assertSee('Necunoscut');
    }
}
