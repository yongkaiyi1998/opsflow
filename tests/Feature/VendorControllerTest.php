<?php

namespace Tests\Feature;

use App\MasterDataStatus;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class VendorControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_can_create_update_and_deactivate_a_vendor(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('vendors.store'), [
            'name' => 'Acme Supplies', 'code' => ' acme ', 'email' => 'orders@acme.test', 'phone' => '+60 3 1234 5678', 'status' => 'ACTIVE',
        ])->assertRedirect(route('vendors.index'));

        $vendor = Vendor::where('code', 'ACME')->firstOrFail();
        $this->actingAs($admin)->put(route('vendors.update', $vendor), [
            'name' => 'Acme Supply Co', 'code' => 'acme', 'email' => null, 'phone' => null, 'status' => 'INACTIVE',
        ])->assertRedirect(route('vendors.index'));

        $vendor->refresh();
        $this->assertSame('Acme Supply Co', $vendor->name);
        $this->assertNull($vendor->email);
        $this->assertSame(MasterDataStatus::Inactive, $vendor->status);
        $this->assertModelExists($vendor);
    }

    public function test_vendor_allows_null_codes_but_rejects_duplicate_codes_and_invalid_contact_data(): void
    {
        $admin = User::factory()->admin()->create();
        Vendor::factory()->create(['code' => 'ACME']);
        Vendor::factory()->create(['code' => null]);

        $this->actingAs($admin)->post(route('vendors.store'), [
            'name' => 'No Code Vendor', 'code' => null, 'email' => null, 'phone' => null, 'status' => 'ACTIVE',
        ])->assertRedirect(route('vendors.index'));

        $this->actingAs($admin)->post(route('vendors.store'), [
            'name' => 'Duplicate', 'code' => 'acme', 'email' => 'invalid', 'phone' => str_repeat('1', 51), 'status' => 'ACTIVE',
        ])->assertSessionHasErrors(['code', 'email', 'phone']);

        $this->assertSame(3, Vendor::count());
    }

    public function test_vendor_search_is_paginated(): void
    {
        $admin = User::factory()->admin()->create();
        Vendor::factory()->create(['name' => 'Needle Supplier', 'email' => 'needle@example.test']);
        Vendor::factory()->create(['name' => 'Hidden Supplier']);
        Vendor::factory()->count(15)->create();

        $this->actingAs($admin)->get(route('vendors.index', ['search' => 'needle@example']))
            ->assertSee('Needle Supplier')->assertDontSee('Hidden Supplier');
        $this->actingAs($admin)->get(route('vendors.index'))
            ->assertViewHas('vendors', fn ($vendors): bool => $vendors->count() === 15 && $vendors->hasMorePages());
    }
}
