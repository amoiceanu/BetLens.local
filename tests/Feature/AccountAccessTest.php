<?php

namespace Tests\Feature;

use App\Models\GeneratedTicket;
use App\Models\ImportedTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_remain_open_and_private_pages_require_login(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Surse reale')->assertDontSee('admin-database-status');
        $this->get(route('matches'))->assertOk()->assertDontSee('Surse reale')->assertDontSee('admin-database-status');
        $this->get(route('tickets'))->assertRedirect(route('user.login'));
        $this->post(route('generate'), ['profile'=>'conservator'])->assertRedirect(route('user.login'));
        foreach (['performance','operators.index','data-sources','admin'] as $route) {
            $this->get(route($route))->assertRedirect(route('admin.login'));
        }
        $this->get(route('admin.login'))->assertOk();
    }

    public function test_registration_creates_only_a_regular_account_and_signs_it_in(): void
    {
        $this->post(route('user.register.store'), [
            'name'=>'Utilizator Test', 'email'=>'utilizator@example.test',
            'password'=>'long-secure-password', 'password_confirmation'=>'long-secure-password',
            'is_admin'=>true,
        ])->assertRedirect(route('tickets'));

        $user=User::where('email','utilizator@example.test')->firstOrFail();
        $this->assertFalse($user->is_admin);
        $this->assertNotSame('long-secure-password', $user->password);
        $this->assertAuthenticatedAs($user);
        $this->get(route('tickets'))->assertOk()->assertDontSee('Surse reale')->assertDontSee('admin-database-status');
        $this->get(route('data-sources'))->assertRedirect(route('admin.login'));
    }

    public function test_user_sees_only_owned_tickets_and_cannot_edit_another_users_ticket(): void
    {
        $mine=User::factory()->create(['is_admin'=>false]);
        $other=User::factory()->create(['is_admin'=>false]);
        $myTicket=GeneratedTicket::create(['user_id'=>$mine->id,'reference'=>'BL-MINE','risk_profile'=>'conservator','total_odds'=>1.5,'combined_probability'=>0.7,'status'=>'pending']);
        $otherTicket=GeneratedTicket::create(['user_id'=>$other->id,'reference'=>'BL-OTHER','risk_profile'=>'conservator','total_odds'=>1.5,'combined_probability'=>0.7,'status'=>'pending']);
        $legacy=GeneratedTicket::create(['reference'=>'BL-LEGACY','risk_profile'=>'conservator','total_odds'=>1.5,'combined_probability'=>0.7,'status'=>'pending']);
        $imported=ImportedTicket::create(['source'=>'superbet','reference'=>'SB-OTHER','user_id'=>$other->id,'placed_at'=>now(),'status'=>'won','total_odds'=>1.5,'stake'=>5,'currency'=>'RON']);

        $this->actingAs($mine);
        $this->get(route('tickets'))->assertOk()->assertSee('BL-MINE')->assertDontSee('BL-OTHER')->assertDontSee('BL-LEGACY')->assertDontSee('SB-OTHER');
        $this->get(route('tickets.show',$myTicket))->assertOk();
        $this->get(route('tickets.show',$otherTicket))->assertNotFound();
        $this->patch(route('tickets.update',$otherTicket),['status'=>'won'])->assertNotFound();
        $this->delete(route('tickets.destroy',$legacy))->assertNotFound();
        $this->get(route('tickets.imported.show',$imported))->assertNotFound();
        $this->delete(route('tickets.imported.destroy',$imported))->assertNotFound();
        $this->assertDatabaseHas('generated_tickets',['id'=>$otherTicket->id,'status'=>'pending']);
    }

    public function test_admin_can_see_legacy_tickets_but_regular_user_cannot_see_admin_pages(): void
    {
        $admin=User::factory()->create(['is_admin'=>true]);
        $regular=User::factory()->create(['is_admin'=>false]);
        GeneratedTicket::create(['reference'=>'BL-LEGACY','risk_profile'=>'conservator','total_odds'=>1.5,'combined_probability'=>0.7,'status'=>'pending']);
        $this->actingAs($regular)->get(route('performance'))->assertRedirect(route('admin.login'));
        $this->withSession(['admin_user_id'=>$admin->id])->get(route('tickets'))->assertOk()->assertSee('BL-LEGACY')->assertSee('Surse reale')->assertSee('admin-database-status');
        $this->get(route('performance'))->assertOk();
    }
}
