<?php

namespace Tests\Feature;

use App\MasterDataStatus;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_created_events_record_actor_subject_context_and_safe_values(): void
    {
        $actor = User::factory()->create();
        $subject = Department::factory()->create();
        $request = Request::create('/departments', 'POST', server: [
            'REMOTE_ADDR' => '192.0.2.10',
            'HTTP_USER_AGENT' => 'OpsFlow Test Agent',
        ]);

        $log = app(AuditService::class)->logCreated(
            $subject,
            $actor,
            ['name' => $subject->name, 'budget' => Money::of('1250.5'), 'password' => 'do-not-store'],
            $request,
            ['source' => 'master-data'],
        );

        $this->assertSame('DEPARTMENT_CREATED', $log->action);
        $this->assertTrue($log->subject->is($subject));
        $this->assertTrue($log->user->is($actor));
        $this->assertSame(['name' => $subject->name, 'budget' => '1250.50'], $log->new_values);
        $this->assertNull($log->old_values);
        $this->assertSame(['source' => 'master-data'], $log->metadata);
        $this->assertSame('192.0.2.10', $log->ip_address);
        $this->assertSame('OpsFlow Test Agent', $log->user_agent);
    }

    public function test_updated_events_include_only_values_that_changed(): void
    {
        $subject = Department::factory()->create();

        $log = app(AuditService::class)->logUpdated(
            $subject,
            User::factory()->create(),
            ['name' => 'Operations', 'code' => 'OPS', 'status' => MasterDataStatus::Active],
            ['name' => 'Operations', 'code' => 'FIN', 'status' => MasterDataStatus::Active],
        );

        $this->assertNotNull($log);
        $this->assertSame('DEPARTMENT_UPDATED', $log->action);
        $this->assertSame(['code' => 'OPS'], $log->old_values);
        $this->assertSame(['code' => 'FIN'], $log->new_values);
    }

    public function test_unchanged_update_does_not_create_noise(): void
    {
        $subject = Department::factory()->create();

        $log = app(AuditService::class)->logUpdated($subject, null, ['name' => 'Finance'], ['name' => 'Finance']);

        $this->assertNull($log);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_status_changes_and_system_actions_are_explicitly_recorded(): void
    {
        $subject = Department::factory()->create();

        $log = app(AuditService::class)->logStatusChange(
            $subject,
            null,
            MasterDataStatus::Active,
            MasterDataStatus::Inactive,
        );

        $this->assertSame('DEPARTMENT_STATUS_CHANGED', $log->action);
        $this->assertNull($log->user_id);
        $this->assertSame(['status' => 'ACTIVE'], $log->old_values);
        $this->assertSame(['status' => 'INACTIVE'], $log->new_values);
    }

    public function test_activity_logs_have_no_edit_or_delete_routes(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->delete('/activity-logs/1')
            ->assertNotFound();
    }
}
