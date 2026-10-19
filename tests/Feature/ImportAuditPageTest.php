<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\DataSource;
use App\Jobs\RunMindmapImport;
use App\Services\ImportAuditService;
use App\Services\Mindmap\ImportManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportAuditPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_view_import_audit_page(): void
    {
        $this->get(route('admin.import-audit'))->assertRedirect(route('user.login'));

        $user = User::factory()->create(['is_admin' => false]);
        $this->withSession(['admin_user_id' => $user->id])
            ->get(route('admin.import-audit'))
            ->assertRedirect(route('user.login'));

        $admin = User::factory()->create(['is_admin' => true]);
        $this->withSession(['admin_user_id' => $admin->id])
            ->get(route('admin.import-audit'))
            ->assertOk()
            ->assertSee('Audit importuri');
    }

    public function test_admin_page_displays_import_run_and_execution_source(): void
    {
        config()->set('betlens.import_execution_source', 'Laravel-DO');
        app(ImportAuditService::class)->record('sportmonks', 'fixtures', 'healthy', ['new' => 2, 'updated' => 1], 'Trei înregistrări sincronizate');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->withSession(['admin_user_id' => $admin->id])
            ->get(route('admin.import-audit'))
            ->assertOk()
            ->assertSee('sportmonks')
            ->assertSee('Laravel-DO')
            ->assertSee('Trei înregistrări sincronizate');
    }

    public function test_l5_reimport_creates_one_audit_entry_and_updates_it_on_completion(): void
    {
        Queue::fake();
        Http::fake(['api.open-meteo.com/*' => Http::response(['timezone' => 'Europe/Bucharest', 'latitude' => 44.4, 'longitude' => 26.1], 200)]);
        $source = DataSource::where('slug', 'open-meteo')->firstOrFail();
        $run = app(ImportManager::class)->enqueue($source);
        $this->assertDatabaseHas('import_audits', ['import_run_id' => $run->id, 'provider' => 'open-meteo', 'overall_status' => 'queued']);

        app()->call([new RunMindmapImport($run->id), 'handle']);
        $this->assertDatabaseCount('import_audits', 1);
        $this->assertDatabaseHas('import_audits', ['import_run_id' => $run->id, 'overall_status' => 'success', 'records_new' => 1]);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->withSession(['admin_user_id' => $admin->id])->get(route('admin.import-audit'))
            ->assertOk()->assertSee('open-meteo')->assertSee('RunMindmapImport');
    }

    public function test_only_admin_can_delete_a_completed_audit_entry_without_deleting_imported_data(): void
    {
        app(ImportAuditService::class)->record('sportmonks', 'fixtures', 'healthy', ['new' => 2]);
        $auditId = \Illuminate\Support\Facades\DB::table('import_audits')->value('id');
        $this->delete(route('admin.import-audit.destroy', $auditId))->assertRedirect(route('user.login'));
        $user = User::factory()->create(['is_admin' => false]);
        $this->withSession(['admin_user_id' => $user->id])->delete(route('admin.import-audit.destroy', $auditId))
            ->assertRedirect(route('user.login'));

        $admin = User::factory()->create(['is_admin' => true]);
        $this->withSession(['admin_user_id' => $admin->id])->delete(route('admin.import-audit.destroy', $auditId))
            ->assertRedirect();
        $this->assertDatabaseMissing('import_audits', ['id' => $auditId]);
    }
}
