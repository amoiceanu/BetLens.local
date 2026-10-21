<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FailedJobsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_jobs_page_requires_admin_access(): void
    {
        $this->get(route('admin.failed-jobs'))->assertRedirect(route('user.login'));

        $user = User::factory()->create(['is_admin' => false]);
        $this->withSession(['admin_user_id' => $user->id])
            ->get(route('admin.failed-jobs'))
            ->assertRedirect(route('user.login'));
    }

    public function test_admin_sees_failed_job_details_and_live_sidebar_count_without_raw_payload(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'failed-job-test-uuid',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\SyncFootballData',
                'data' => ['command' => 'serialized-secret-value'],
            ]),
            'exception' => "QueryException: Table missing\nStack trace line",
            'failed_at' => now(),
        ]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->withSession(['admin_user_id' => $admin->id])
            ->get(route('admin.failed-jobs'))
            ->assertOk()
            ->assertSee('Failed jobs')
            ->assertSee('SyncFootballData')
            ->assertSee('Table missing')
            ->assertSee('Stack trace line')
            ->assertSee('failed-job-test-uuid')
            ->assertSee('admin-failed-job-count', false)
            ->assertSee('>1</span>', false)
            ->assertDontSee('serialized-secret-value');

        $this->withSession(['admin_user_id' => $admin->id])
            ->delete(route('admin.failed-jobs.destroy', DB::table('failed_jobs')->value('id')))
            ->assertRedirect(route('admin.failed-jobs'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'failed-job-test-uuid']);
    }
}
