<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\SpendCategory;
use App\Models\User;
use App\Models\Vendor;
use App\UserRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MasterDataAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function nonAdminRoles(): array
    {
        return [
            'finance' => [UserRole::Finance],
            'employee' => [UserRole::Employee],
        ];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_roles_cannot_access_master_data_routes(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $department = Department::factory()->create();
        $category = SpendCategory::factory()->create();
        $vendor = Vendor::factory()->create();

        $this->actingAs($user)->get(route('departments.index'))->assertForbidden();
        $this->actingAs($user)->post(route('departments.store'), [])->assertForbidden();
        $this->actingAs($user)->put(route('departments.update', $department), [])->assertForbidden();
        $this->actingAs($user)->get(route('spend-categories.index'))->assertForbidden();
        $this->actingAs($user)->post(route('spend-categories.store'), [])->assertForbidden();
        $this->actingAs($user)->put(route('spend-categories.update', $category), [])->assertForbidden();
        $this->actingAs($user)->get(route('vendors.index'))->assertForbidden();
        $this->actingAs($user)->post(route('vendors.store'), [])->assertForbidden();
        $this->actingAs($user)->put(route('vendors.update', $vendor), [])->assertForbidden();
        $this->actingAs($user)->get(route('users.index'))->assertForbidden();
        $this->actingAs($user)->post(route('users.store'), [])->assertForbidden();
        $this->actingAs($user)->put(route('users.update', $user), [])->assertForbidden();
    }

    public function test_admin_policy_matrix_allows_management_but_never_deletion(): void
    {
        $admin = User::factory()->admin()->create();
        $models = [Department::factory()->create(), SpendCategory::factory()->create(), Vendor::factory()->create(), User::factory()->create()];

        foreach ($models as $model) {
            $this->assertTrue($admin->can('viewAny', $model::class));
            $this->assertTrue($admin->can('create', $model::class));
            $this->assertTrue($admin->can('update', $model));
            $this->assertFalse($admin->can('delete', $model));
        }
    }

    public function test_master_data_has_no_delete_routes(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->delete('/departments/1')->assertMethodNotAllowed();
        $this->actingAs($admin)->delete('/spend-categories/1')->assertMethodNotAllowed();
        $this->actingAs($admin)->delete('/vendors/1')->assertMethodNotAllowed();
        $this->actingAs($admin)->delete('/users/1')->assertMethodNotAllowed();
    }

    public function test_non_admin_does_not_see_administration_navigation(): void
    {
        $this->withoutVite();

        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertDontSee('Administration')->assertDontSee(route('users.index'));
    }
}
