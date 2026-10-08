<?php
namespace Tests\Feature;
use App\Models\GeneratedTicket;
use App\Models\Operator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class TicketGenerationTest extends TestCase
{
    use RefreshDatabase;
    public function test_ticket_is_not_fabricated_when_real_recommendations_are_missing(): void
    {
        $this->seed();
        $response=$this->post('/genereaza',['profile'=>'conservator']);
        $response->assertRedirect()->assertSessionHas('warning','Nu există meciuri viitoare pentru criteriile alese.');
        $this->assertDatabaseCount('generated_tickets',0);
    }

    public function test_a_ticket_can_be_deleted_from_history(): void
    {
        $ticket=GeneratedTicket::create([
            'reference'=>'BL-DELETE1',
            'risk_profile'=>'conservator',
            'total_odds'=>1.5,
            'combined_probability'=>0.66,
            'status'=>'pending',
        ]);

        $this->delete(route('tickets.destroy',$ticket))
            ->assertRedirect(route('tickets'))
            ->assertSessionHas('success','Biletul a fost șters.');

        $this->assertDatabaseMissing('generated_tickets',['id'=>$ticket->id]);
    }

    public function test_a_ticket_can_be_marked_as_placed_on_winbet(): void
    {
        $ticket=GeneratedTicket::create([
            'reference'=>'BL-PLACED1',
            'risk_profile'=>'conservator',
            'total_odds'=>1.5,
            'combined_probability'=>0.66,
            'status'=>'pending',
        ]);

        $winbet=Operator::where('slug','winbet')->firstOrFail();
        $this->patch(route('tickets.update',$ticket),['status'=>'placed:'.$winbet->id])
            ->assertRedirect()
            ->assertSessionHas('success','Status actualizat.');

        $this->assertDatabaseHas('generated_tickets',['id'=>$ticket->id,'status'=>'placed','operator_id'=>$winbet->id]);
    }

    public function test_each_configured_operator_is_available_as_a_placed_status(): void
    {
        $ticket=GeneratedTicket::create(['reference'=>'BL-OPERATORS','risk_profile'=>'conservator','total_odds'=>1.5,'combined_probability'=>0.66,'status'=>'pending']);
        $response=$this->get(route('tickets.show',$ticket))->assertOk();
        foreach(Operator::orderBy('name')->get() as $operator)$response->assertSee('Plasat pe '.$operator->name);
        $superbet=Operator::where('slug','superbet')->firstOrFail();
        $this->patch(route('tickets.update',$ticket),['status'=>'placed:'.$superbet->id])->assertRedirect();
        $this->get(route('tickets'))->assertOk()->assertSee('Plasat pe Superbet');
    }

    public function test_the_generated_reference_can_be_replaced_with_a_personal_reference(): void
    {
        $ticket=GeneratedTicket::create([
            'reference'=>'BL-AUTO123',
            'risk_profile'=>'conservator',
            'total_odds'=>1.5,
            'combined_probability'=>0.66,
            'status'=>'pending',
        ]);

        $this->patch(route('tickets.update',$ticket),['reference'=>'WINBET-987654'])
            ->assertRedirect()
            ->assertSessionHas('success','Referința biletului a fost actualizată.');

        $this->assertDatabaseHas('generated_tickets',['id'=>$ticket->id,'reference'=>'WINBET-987654']);
    }

    public function test_match_window_can_be_edited_and_is_shown_in_ticket_history(): void
    {
        $ticket=GeneratedTicket::create([
            'reference'=>'BL-WINDOW1',
            'risk_profile'=>'conservator',
            'total_odds'=>1.5,
            'combined_probability'=>0.66,
            'status'=>'pending',
        ]);

        $this->patch(route('tickets.update',$ticket),[
            'first_match_at'=>'2026-10-10 18:30',
            'last_match_at'=>'2026-10-11 21:45',
        ])->assertRedirect()->assertSessionHas('success','Intervalul meciurilor a fost actualizat.');

        $this->assertDatabaseHas('generated_tickets',['id'=>$ticket->id,'first_match_at'=>'2026-10-10 18:30:00','last_match_at'=>'2026-10-11 21:45:00']);
        $this->get(route('tickets'))->assertOk()->assertSee('10.10.2026 18:30')->assertSee('11.10.2026 21:45');
    }

    public function test_ticket_details_are_saved_together_from_one_form(): void
    {
        $ticket=GeneratedTicket::create(['reference'=>'BL-ONEFORM','risk_profile'=>'conservator','total_odds'=>1.5,'combined_probability'=>0.66,'status'=>'pending']);
        $operator=Operator::where('slug','winbet')->firstOrFail();

        $this->get(route('tickets.show',$ticket))
            ->assertOk()
            ->assertSee('Salvează')
            ->assertDontSee('Copiază selecțiile');

        $this->patch(route('tickets.update',$ticket),[
            'reference'=>'PERSONAL-ONEFORM',
            'status'=>'placed:'.$operator->id,
            'first_match_at'=>'2026-10-12 18:00',
            'last_match_at'=>'2026-10-13 21:00',
        ])->assertRedirect()->assertSessionHas('success','Detaliile biletului au fost actualizate.');

        $this->assertDatabaseHas('generated_tickets',[
            'id'=>$ticket->id,
            'reference'=>'PERSONAL-ONEFORM',
            'status'=>'placed',
            'operator_id'=>$operator->id,
            'first_match_at'=>'2026-10-12 18:00:00',
            'last_match_at'=>'2026-10-13 21:00:00',
        ]);
    }

    public function test_ticket_history_can_be_sorted_ascending_and_descending_from_the_grid(): void
    {
        foreach(['BL-ZULU','BL-ALFA'] as $reference)GeneratedTicket::create(['reference'=>$reference,'risk_profile'=>'conservator','total_odds'=>1.5,'combined_probability'=>0.66,'status'=>'pending']);
        $this->get(route('tickets',['sort'=>'reference','direction'=>'asc']))->assertOk()->assertSeeInOrder(['BL-ALFA','BL-ZULU']);
        $this->get(route('tickets',['sort'=>'reference','direction'=>'desc']))->assertOk()->assertSeeInOrder(['BL-ZULU','BL-ALFA']);
    }
}
