<?php

namespace Tests\Feature;

use App\MasterDataStatus;
use App\Models\SpendCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SpendCategoryControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_can_create_update_and_deactivate_a_spend_category(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('spend-categories.store'), [
            'name' => 'Software', 'code' => ' sw ', 'status' => 'ACTIVE',
        ])->assertRedirect(route('spend-categories.index'));

        $category = SpendCategory::where('code', 'SW')->firstOrFail();
        $this->actingAs($admin)->put(route('spend-categories.update', $category), [
            'name' => 'Software subscriptions', 'code' => 'sw', 'status' => 'INACTIVE',
        ])->assertRedirect(route('spend-categories.index'));

        $category->refresh();
        $this->assertSame('Software subscriptions', $category->name);
        $this->assertSame(MasterDataStatus::Inactive, $category->status);
        $this->assertModelExists($category);
    }

    public function test_spend_category_requires_unique_valid_fields(): void
    {
        $admin = User::factory()->admin()->create();
        SpendCategory::factory()->create(['code' => 'TRAVEL']);

        $this->actingAs($admin)->post(route('spend-categories.store'), [
            'name' => '', 'code' => 'travel', 'status' => 'REMOVED',
        ])->assertSessionHasErrors(['name', 'code', 'status']);

        $this->assertSame(1, SpendCategory::count());
    }

    public function test_spend_category_search_is_paginated(): void
    {
        $admin = User::factory()->admin()->create();
        SpendCategory::factory()->create(['name' => 'Needle category']);
        SpendCategory::factory()->create(['name' => 'Hidden category']);
        SpendCategory::factory()->count(15)->create();

        $this->actingAs($admin)->get(route('spend-categories.index', ['search' => 'Needle']))
            ->assertSee('Needle category')->assertDontSee('Hidden category');
        $this->actingAs($admin)->get(route('spend-categories.index'))
            ->assertViewHas('spendCategories', fn ($categories): bool => $categories->count() === 15 && $categories->hasMorePages());
    }
}
