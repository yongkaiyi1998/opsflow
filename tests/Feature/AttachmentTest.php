<?php

namespace Tests\Feature;

use App\MasterDataStatus;
use App\Models\Attachment;
use App\Models\Department;
use App\Models\User;
use App\Services\AttachmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Gate::define('addAttachment', fn (User $user, Department $department): bool => $user->isAdmin());
        Gate::define('deleteAttachment', fn (User $user, Department $department): bool => $user->isAdmin());
    }

    public function test_authorized_upload_is_stored_privately_with_server_generated_metadata(): void
    {
        $admin = User::factory()->admin()->create();
        $department = Department::factory()->create();
        $attachment = app(AttachmentService::class)->store(
            $department,
            UploadedFile::fake()->create('supplier quote.pdf', 100, 'application/pdf'),
            $admin,
        );

        $this->assertSame('supplier quote.pdf', $attachment->original_name);
        $this->assertNotSame($attachment->original_name, $attachment->stored_name);
        $this->assertSame('local', $attachment->disk);
        $this->assertSame($admin->id, $attachment->uploaded_by);
        $this->assertTrue($attachment->attachable->is($department));
        $this->assertStringStartsWith('attachments/', $attachment->path);
        Storage::disk('local')->assertExists($attachment->path);
        Storage::disk('public')->assertMissing($attachment->path);
    }

    public function test_unauthorized_user_cannot_upload_to_a_parent_record(): void
    {
        $this->expectException(AuthorizationException::class);

        app(AttachmentService::class)->store(
            Department::factory()->create(),
            UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf'),
            User::factory()->create(),
        );
    }

    public function test_upload_rechecks_locked_parent_state_before_persisting_metadata(): void
    {
        $admin = User::factory()->admin()->create();
        $department = Department::factory()->create();
        $authorizationChecks = 0;

        Gate::define('addAttachment', function (User $user, Department $checkedDepartment) use (&$authorizationChecks, $department): bool {
            $authorizationChecks++;

            if ($authorizationChecks === 1) {
                Department::whereKey($department->id)->update(['status' => MasterDataStatus::Inactive->value]);

                return true;
            }

            return $user->isAdmin() && $checkedDepartment->status === MasterDataStatus::Active;
        });

        try {
            app(AttachmentService::class)->store(
                $department,
                UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf'),
                $admin,
            );
            $this->fail('Expected the locked parent authorization check to reject the upload.');
        } catch (AuthorizationException) {
            $this->assertSame(2, $authorizationChecks);
            $this->assertDatabaseCount('attachments', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_attachment_type_and_size_are_validated(): void
    {
        $admin = User::factory()->admin()->create();
        $department = Department::factory()->create();

        foreach ([
            UploadedFile::fake()->create('script.php', 1, 'application/x-php'),
            UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
        ] as $file) {
            try {
                app(AttachmentService::class)->store($department, $file, $admin);
                $this->fail('Expected attachment validation to fail.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('attachments', 0);
            }
        }
    }

    public function test_public_disk_configuration_is_rejected(): void
    {
        config(['attachments.disk' => 'public']);
        $this->expectException(LogicException::class);

        app(AttachmentService::class)->store(
            Department::factory()->create(),
            UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf'),
            User::factory()->admin()->create(),
        );
    }

    public function test_authorized_user_can_download_an_attachment_using_its_original_name(): void
    {
        $admin = User::factory()->admin()->create();
        $attachment = $this->storeAttachment($admin);

        $this->actingAs($admin)->get(route('attachments.download', $attachment))
            ->assertOk()
            ->assertDownload('quote.pdf');
    }

    public function test_known_attachment_id_does_not_bypass_parent_authorization(): void
    {
        $attachment = $this->storeAttachment(User::factory()->admin()->create());

        $this->actingAs(User::factory()->create())->get(route('attachments.download', $attachment))->assertForbidden();
    }

    public function test_missing_private_file_returns_not_found_to_an_authorized_user(): void
    {
        $admin = User::factory()->admin()->create();
        $attachment = $this->storeAttachment($admin);
        Storage::disk('local')->delete($attachment->path);

        $this->actingAs($admin)->get(route('attachments.download', $attachment))->assertNotFound();
    }

    public function test_authorized_delete_removes_both_file_and_metadata(): void
    {
        $admin = User::factory()->admin()->create();
        $attachment = $this->storeAttachment($admin);
        $path = $attachment->path;

        $this->actingAs($admin)->from(route('departments.index'))
            ->delete(route('attachments.destroy', $attachment))
            ->assertRedirect(route('departments.index'));

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_unauthorized_delete_leaves_file_and_metadata_intact(): void
    {
        $attachment = $this->storeAttachment(User::factory()->admin()->create());

        $this->actingAs(User::factory()->create())->delete(route('attachments.destroy', $attachment))->assertForbidden();

        $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_delete_rechecks_locked_parent_state_before_removing_attachment(): void
    {
        $admin = User::factory()->admin()->create();
        $attachment = $this->storeAttachment($admin);
        $department = $attachment->attachable;
        $authorizationChecks = 0;

        Gate::define('deleteAttachment', function (User $user, Department $checkedDepartment) use (&$authorizationChecks, $department): bool {
            $authorizationChecks++;

            if ($authorizationChecks === 1) {
                Department::whereKey($department->id)->update(['status' => MasterDataStatus::Inactive->value]);

                return true;
            }

            return $user->isAdmin() && $checkedDepartment->status === MasterDataStatus::Active;
        });

        try {
            app(AttachmentService::class)->delete($attachment, $admin);
            $this->fail('Expected the locked parent authorization check to reject deletion.');
        } catch (AuthorizationException) {
            $this->assertSame(2, $authorizationChecks);
            $this->assertDatabaseHas('attachments', ['id' => $attachment->id]);
            Storage::disk('local')->assertExists($attachment->path);
        }
    }

    private function storeAttachment(User $admin): Attachment
    {
        return app(AttachmentService::class)->store(
            Department::factory()->create(),
            UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf'),
            $admin,
        );
    }
}
