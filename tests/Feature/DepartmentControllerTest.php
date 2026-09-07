<?php

namespace Tests\Feature;

use App\MasterDataStatus;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DepartmentControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_can_create_a_department_with_an_active_manager(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->create();

        $this->actingAs($admin)->post(route('departments.store'), [
            'name' => 'Information Technology',
            'code' => ' it ',
            'manager_id' => $manager->id,
            'status' => MasterDataStatus::Active->value,
        ])->assertRedirect(route('departments.index'))->assertSessionHas('success');

        $department = Department::where('code', 'IT')->firstOrFail();
        $this->assertSame('Information Technology', $department->name);
        $this->assertTrue($department->manager->is($manager));
    }

    public function test_admin_can_update_and_deactivate_a_department_without_deleting_it(): void
    {
        $admin = User::factory()->admin()->create();
        $department = Department::factory()->create(['code' => 'OPS']);

        $this->actingAs($admin)->put(route('departments.update', $department), [
            'name' => 'Operations', 'code' => 'ops', 'manager_id' => null, 'status' => 'INACTIVE',
        ])->assertRedirect(route('departments.index'));

        $department->refresh();
        $this->assertSame(MasterDataStatus::Inactive, $department->status);
        $this->assertModelExists($department);
    }

    public function test_department_validation_rejects_duplicate_codes_and_inactive_managers(): void
    {
        $admin = User::factory()->admin()->create();
        Department::factory()->create(['code' => 'FIN']);
        $manager = User::factory()->inactive()->create();

        $this->actingAs($admin)->post(route('departments.store'), [
            'name' => 'Finance', 'code' => 'fin', 'manager_id' => $manager->id, 'status' => 'ACTIVE',
        ])->assertSessionHasErrors(['code', 'manager_id']);

        $this->assertSame(1, Department::count());
    }

    public function test_department_search_and_pagination_return_matching_records(): void
    {
        $admin = User::factory()->admin()->create();
        Department::factory()->create(['name' => 'Needle Operations', 'code' => 'NDL']);
        Department::factory()->create(['name' => 'Unrelated Finance', 'code' => 'FIN']);
        Department::factory()->count(15)->create();

        $this->actingAs($admin)->get(route('departments.index', ['search' => 'Needle']))
            ->assertSee('Needle Operations')->assertDontSee('Unrelated Finance')
            ->assertViewHas('departments', fn ($departments): bool => $departments->total() === 1);

        $this->actingAs($admin)->get(route('departments.index'))
            ->assertViewHas('departments', fn ($departments): bool => $departments->count() === 15 && $departments->hasMorePages());
    }

    public function test_department_list_escapes_user_supplied_names(): void
    {
        $admin = User::factory()->admin()->create();
        Department::factory()->create(['name' => '<script>alert(1)</script>']);

        $this->actingAs($admin)->get(route('departments.index'))
            ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
    }
}
