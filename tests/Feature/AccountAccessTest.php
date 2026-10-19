<?php

namespace Tests\Feature;

use App\Models\GeneratedTicket;
use App\Models\ImportedTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
            $this->get(route($route))->assertRedirect(route('user.login'));
        }
        $this->get(route('admin.login'))->assertRedirect(route('user.login'));
        $this->get(route('user.login'))->assertOk();
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
        $this->get(route('tickets'))->assertOk()->assertDontSee('Surse reale')->assertDontSee('admin-database-status')
            ->assertSee('nav-user')->assertSee('data-lucide="circle-user-round"', false)
            ->assertSee('Utilizator Test')->assertSee('data-lucide="log-out"', false)->assertSee('Ieși din cont');
        $this->get(route('data-sources'))->assertRedirect(route('user.login'));
    }

    public function test_one_login_page_routes_admin_and_regular_accounts_to_their_own_areas(): void
    {
        $admin = User::factory()->create([
            'username' => 'admin@example.test', 'email' => 'admin@example.test',
            'is_admin' => true, 'password' => Hash::make('admin-password-123'),
        ]);
        $regular = User::factory()->create([
            'email' => 'regular@example.test', 'is_admin' => false,
            'password' => Hash::make('regular-password-123'),
        ]);

        $this->get(route('admin.login'))->assertRedirect(route('user.login'));
        $this->get(route('user.login'))->assertOk()->assertSee('Cont BetLens');
        $this->post(route('user.authenticate'), [
            'email' => $admin->email, 'password' => 'admin-password-123',
        ])->assertRedirect(route('data-sources'))->assertSessionHas('admin_user_id', $admin->id);
        $this->get(route('admin'))->assertRedirect(route('data-sources'));
        $this->post(route('admin.logout'))->assertRedirect(route('user.login'));

        $this->post(route('user.authenticate'), [
            'email' => $regular->email, 'password' => 'regular-password-123',
        ])->assertRedirect(route('tickets'))->assertSessionMissing('admin_user_id');
        $this->assertAuthenticatedAs($regular);
        $this->get(route('admin'))->assertRedirect(route('user.login'));
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

    public function test_header_uses_email_when_account_name_is_missing(): void
    {
        $user=User::factory()->create(['name'=>'','email'=>'cont@example.test','is_admin'=>false]);

        $this->actingAs($user)->get(route('tickets'))
            ->assertOk()
            ->assertSee('nav-account-name')
            ->assertSee('cont@example.test');
    }

    public function test_admin_header_keeps_account_actions_on_right_and_real_sources_below_menu(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'username' => 'header-admin']);

        $this->withSession(['admin_user_id' => $admin->id])->get(route('data-sources'))
            ->assertOk()
            ->assertSee('nav-admin')
            ->assertSeeInOrder(['id="main-navigation"', 'admin-header-actions', 'header-admin', 'Ieși din cont', 'admin-header-status', 'Surse reale'], false);
    }

    public function test_admin_cannot_access_or_place_tickets_and_regular_user_cannot_see_admin_pages(): void
    {
        $admin=User::factory()->create(['is_admin'=>true,'username'=>'audit-admin']);
        $regular=User::factory()->create(['is_admin'=>false]);
        $legacy = GeneratedTicket::create(['reference'=>'BL-LEGACY','risk_profile'=>'conservator','total_odds'=>1.5,'combined_probability'=>0.7,'status'=>'pending']);
        $this->actingAs($regular)->get(route('performance'))->assertRedirect(route('user.login'));
        $this->withSession(['admin_user_id'=>$admin->id])->get(route('tickets'))->assertForbidden();
        $this->post(route('generate'), ['profile' => 'conservator'])->assertForbidden();
        $this->get(route('tickets.show', $legacy))->assertForbidden();
        $this->patch(route('tickets.update', $legacy), ['status' => 'won'])->assertForbidden();
        $this->delete(route('tickets.destroy', $legacy))->assertForbidden();
        $this->assertDatabaseHas('generated_tickets', ['id' => $legacy->id, 'status' => 'pending']);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Biletele mele')->assertDontSee('Generează biletul')
            ->assertSee('Surse reale')->assertSee('admin-database-status')->assertSee('audit-admin');
        $this->get(route('performance'))->assertRedirect(route('data-sources'));
    }

    public function test_admin_sidebar_is_shared_across_admin_and_public_pages_without_duplicate_mindmap_menu(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertDontSee('aria-label="Meniu administrator"', false);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->withSession(['admin_user_id' => $admin->id]);
        foreach (['data-sources', 'admin.mindmap', 'dashboard', 'matches'] as $name) {
            $response = $this->get(route($name))->assertOk()
                ->assertSee('aria-label="Meniu administrator"', false)
                ->assertSee('Data Mindmap')
                ->assertDontSee('Biletele mele')
                ->assertDontSee('>Dashboard</a>', false)
                ->assertDontSee('>Admin</a>', false)
                ->assertDontSee('>Performanță</a>', false);
            $this->assertSame(1, substr_count($response->getContent(), 'aria-label="Meniu administrator"'));
            $this->assertSame(1, substr_count($response->getContent(), '>Meciuri</a>'));
        }
    }
}
