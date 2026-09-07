<?php

namespace Tests\Feature;

use App\Models\User;
use App\UserRole;
use App\UserStatus;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_users_default_to_active_employees(): void
    {
        $user = User::factory()->create()->refresh();

        $this->assertSame(UserRole::Employee, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertTrue($user->isActive());
    }

    public function test_database_defaults_do_not_grant_admin_access(): void
    {
        $id = DB::table('users')->insertGetId(['name' => 'Default user', 'email' => 'default@example.com', 'password' => 'unused']);

        $user = User::findOrFail($id);
        $this->assertSame(UserRole::Employee, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
    }

    public function test_ordinary_mass_assignment_cannot_change_role_or_status(): void
    {
        $user = User::factory()->inactive()->create();

        $user->fill(['name' => 'Updated name', 'role' => 'ADMIN', 'status' => 'ACTIVE'])->save();

        $user->refresh();
        $this->assertSame('Updated name', $user->name);
        $this->assertSame(UserRole::Employee, $user->role);
        $this->assertSame(UserStatus::Inactive, $user->status);
    }
}
