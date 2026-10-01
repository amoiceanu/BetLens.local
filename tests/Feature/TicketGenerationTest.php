<?php
namespace Tests\Feature;
use App\Models\GeneratedTicket;
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

        $this->patch(route('tickets.update',$ticket),['status'=>'placed_winbet'])
            ->assertRedirect()
            ->assertSessionHas('success','Status actualizat.');

        $this->assertDatabaseHas('generated_tickets',['id'=>$ticket->id,'status'=>'placed_winbet']);
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
}
