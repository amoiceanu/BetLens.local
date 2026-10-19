<?php

namespace Tests\Feature;

use App\Models\DataSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminDataSourceKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_manage_encrypted_source_keys(): void
    {
        $source=DataSource::where('slug','football-data')->firstOrFail();
        $this->put(route('admin.sources.key',$source),['credential_secret'=>'example-secret-123'])->assertRedirect(route('user.login'));
        $this->assertNull($source->fresh()->credential_secret);

        $admin=User::factory()->create(['is_admin'=>true]);
        $this->withSession(['admin_user_id'=>$admin->id]);
        $this->put(route('admin.sources.key',$source),['credential_secret'=>'example-secret-123'])->assertRedirect();
        $this->assertSame('example-secret-123',$source->fresh()->credentialValue());
        $this->assertNotSame('example-secret-123',DB::table('data_sources')->where('id',$source->id)->value('credential_secret'));
        $this->get(route('data-sources'))->assertOk()->assertSee('Cheie salvată în Admin')->assertSee('placeholder="********"', false)->assertDontSee('example-secret-123');
    }

    public function test_blank_submission_preserves_key_and_explicit_removal_restores_env_fallback(): void
    {
        config(['services.football_data.key'=>'env-fallback']);
        $source=DataSource::where('slug','football-data')->firstOrFail();
        $admin=User::factory()->create(['is_admin'=>true]);
        $this->withSession(['admin_user_id'=>$admin->id]);

        $this->put(route('admin.sources.key',$source),['credential_secret'=>'saved-secret'])->assertRedirect();
        $this->put(route('admin.sources.key',$source),['credential_secret'=>''])->assertRedirect();
        $this->assertSame('saved-secret',$source->fresh()->credentialValue());
        $this->put(route('admin.sources.key',$source),['remove'=>'1'])->assertRedirect();
        $this->assertSame('env-fallback',$source->fresh()->credentialValue());
    }

    public function test_unconfigured_source_invites_admin_to_add_a_key(): void
    {
        config(['services.football_data.key' => null]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->withSession(['admin_user_id' => $admin->id])->get(route('data-sources'))
            ->assertOk()
            ->assertSee('placeholder="Adaugă cheie"', false);
    }
}
