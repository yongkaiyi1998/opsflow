<?php

namespace Tests\Feature;

use App\MasterDataStatus;
use App\Models\Department;
use App\Models\User;
use App\UserRole;
use App\UserStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_can_create_a_user_with_organization_assignments(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->create();
        $department = Department::factory()->create();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Finance User', 'email' => ' Finance@Example.test ', 'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass', 'role' => 'FINANCE', 'status' => 'ACTIVE',
            'department_id' => $department->id, 'manager_id' => $manager->id,
        ])->assertRedirect(route('users.index'))->assertSessionHas('success');

        $user = User::where('email', 'finance@example.test')->firstOrFail();
        $this->assertSame(UserRole::Finance, $user->role);
        $this->assertTrue(Hash::check('secret-pass', $user->password));
        $this->assertTrue($user->department->is($department));
        $this->assertTrue($user->manager->is($manager));
        $this->assertTrue($manager->directReports->contains($user));
    }

    public function test_admin_can_update_and_deactivate_a_user_without_deleting_or_replacing_password(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $password = $user->password;

        $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => 'Inactive Employee', 'email' => $user->email, 'password' => '', 'password_confirmation' => '',
            'role' => 'EMPLOYEE', 'status' => 'INACTIVE', 'department_id' => null, 'manager_id' => null,
        ])->assertRedirect(route('users.index'));

        $user->refresh();
        $this->assertSame(UserStatus::Inactive, $user->status);
        $this->assertSame($password, $user->password);
        $this->assertModelExists($user);
    }

    public function test_user_validation_rejects_duplicate_email_self_management_and_inactive_relationships(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create(['email' => 'taken@example.test']);
        $inactiveManager = User::factory()->inactive()->create();
        $inactiveDepartment = Department::factory()->create(['status' => MasterDataStatus::Inactive]);

        $this->actingAs($admin)->put(route('users.update', $other), [
            'name' => 'Invalid', 'email' => $admin->email, 'role' => 'UNKNOWN', 'status' => 'ACTIVE',
            'department_id' => $inactiveDepartment->id, 'manager_id' => $inactiveManager->id,
        ])->assertSessionHasErrors(['email', 'role', 'department_id', 'manager_id']);

        $this->actingAs($admin)->put(route('users.update', $admin), [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'EMPLOYEE', 'status' => 'ACTIVE',
            'department_id' => null, 'manager_id' => $admin->id,
        ])->assertSessionHasErrors(['manager_id', 'status']);

        $this->assertSame(UserRole::Admin, $admin->refresh()->role);
    }

    public function test_existing_inactive_relationships_can_be_preserved_during_an_update(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->inactive()->create();
        $department = Department::factory()->create(['status' => MasterDataStatus::Inactive]);
        $user = User::factory()->create(['department_id' => $department->id, 'manager_id' => $manager->id]);

        $this->actingAs($admin)->put(route('users.update', $user), [
            'name' => 'Historical User', 'email' => $user->email, 'role' => 'EMPLOYEE', 'status' => 'ACTIVE',
            'department_id' => $department->id, 'manager_id' => $manager->id,
        ])->assertRedirect(route('users.index'));

        $this->assertTrue($user->refresh()->department->is($department));
        $this->assertTrue($user->manager->is($manager));
    }

    public function test_user_search_is_paginated(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Needle Person']);
        User::factory()->create(['name' => 'Hidden Person']);
        User::factory()->count(15)->create();

        $this->actingAs($admin)->get(route('users.index', ['search' => 'Needle']))
            ->assertSee('Needle Person')->assertDontSee('Hidden Person');
        $this->actingAs($admin)->get(route('users.index'))
            ->assertViewHas('users', fn ($users): bool => $users->count() === 15 && $users->hasMorePages());
    }
}
