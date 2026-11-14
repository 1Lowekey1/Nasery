<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OrganizationDocumentsTest extends TestCase
{
    use InteractsWithOrganizations;

    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/documents');
        config(['nasery.organizer_documents.root' => $this->root, 'nasery.organizer_documents.required' => true]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function upload(User $user, Organization $org, string $name = 'permit.pdf', string $content = self::PDF, string $type = 'business_permit'): TestResponse
    {
        return $this->actingAs($user)->call('POST', "/api/v1/organizations/{$org->id}/documents", ['doc_type' => $type], [],
            ['file' => UploadedFile::fake()->createWithContent($name, $content)],
            $this->transformHeadersToServerVars([...self::SPA, 'Accept' => 'application/json']));
    }

    private function decide(User $admin, Organization $org, string $status = 'approved'): TestResponse
    {
        return $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/organizer-applications/{$org->id}/decision",
            ['status' => $status, 'reason' => null], ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()]);
    }

    public function test_owner_uploads_and_the_admin_reads_it_on_the_application(): void
    {
        $org = $this->onboard($owner = $this->businessUser());

        $id = $this->upload($owner, $org, '../../evil name.pdf')->assertCreated()
            ->assertJsonPath('data.doc_type', 'business_permit')
            ->assertJsonPath('data.original_name', 'evil name.pdf')
            ->assertJsonMissingPath('data.stored_path')
            ->json('data.id');

        $path = DB::table('organization_documents')->where('id', $id)->value('stored_path');
        $this->assertStringStartsWith($org->id.'/', $path);
        $this->assertFileExists($this->root.'/'.$path);

        $admin = $this->adminUser();
        $this->actingAs($admin)->spaJson('GET', "/api/v1/admin/organizer-applications/{$org->id}")->assertOk()
            ->assertJsonPath('data.documents.0.id', $id)->assertJsonPath('data.documents_required', true);

        $this->actingAs($admin)->get("/api/v1/organizations/{$org->id}/documents/{$id}/file", self::SPA)->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_content_decides_not_the_file_name(): void
    {
        $org = $this->onboard($owner = $this->businessUser());

        $this->upload($owner, $org, 'permit.pdf', '<script>alert(1)</script>')->assertUnprocessable()->assertJsonPath('error.details.fields.file.0', fn ($m) => is_string($m));
        $this->upload($owner, $org, 'permit.html', self::PDF, 'nonsense')->assertUnprocessable()->assertJsonPath('error.details.fields.doc_type.0', fn ($m) => is_string($m));

        config(['nasery.organizer_documents.max_bytes' => 10]);
        $this->upload($owner, $org)->assertUnprocessable()->assertJsonPath('error.details.fields.file.0', fn ($m) => is_string($m));
    }

    public function test_only_the_owner_and_admins_reach_documents(): void
    {
        $org = $this->onboard($owner = $this->businessUser());
        $id = $this->upload($owner, $org)->assertCreated()->json('data.id');

        $this->addStaff($org, $staff = $this->businessUser());
        $this->actingAs($staff)->get("/api/v1/organizations/{$org->id}/documents/{$id}/file", self::SPA)->assertForbidden();
        $this->actingAs($staff)->spaJson('GET', "/api/v1/organizations/{$org->id}/documents")->assertForbidden();

        $stranger = $this->businessUser();
        $this->actingAs($stranger)->get("/api/v1/organizations/{$org->id}/documents/{$id}/file", self::SPA)->assertNotFound();
    }

    public function test_a_document_of_another_organization_is_not_found(): void
    {
        $org = $this->onboard($owner = $this->businessUser());
        $id = $this->upload($owner, $org)->assertCreated()->json('data.id');
        $mine = $this->onboard($other = $this->businessUser());

        $this->actingAs($other)->get("/api/v1/organizations/{$mine->id}/documents/{$id}/file", self::SPA)->assertNotFound();
    }

    public function test_approval_waits_for_a_document_but_rejection_does_not(): void
    {
        $org = $this->onboard($owner = $this->businessUser());
        $admin = $this->adminUser();

        $this->decide($admin, $org)->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');

        $this->upload($owner, $org)->assertCreated();
        $this->decide($admin, $org)->assertOk()->assertJsonPath('data.approval_status', 'approved');

        $other = $this->onboard($this->businessUser());
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/organizer-applications/{$other->id}/decision",
            ['status' => 'rejected', 'reason' => 'Please upload your permit.'], ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])
            ->assertOk()->assertJsonPath('data.approval_status', 'rejected');
    }

    public function test_reinstating_a_suspended_organizer_needs_no_new_document(): void
    {
        $org = $this->onboard($owner = $this->businessUser());
        $admin = $this->adminUser();
        $this->upload($owner, $org)->assertCreated();
        $this->decide($admin, $org)->assertOk();

        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/organizer-applications/{$org->id}/decision",
            ['status' => 'suspended', 'reason' => 'Under investigation.'], ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->assertOk();
        DB::table('organization_documents')->where('organization_id', $org->id)->delete();

        $this->decide($admin, $org->refresh())->assertOk()->assertJsonPath('data.approval_status', 'approved');
    }

    public function test_documents_can_be_removed_until_approved(): void
    {
        $org = $this->onboard($owner = $this->businessUser());
        $keep = $this->upload($owner, $org)->assertCreated()->json('data.id');
        $drop = $this->upload($owner, $org, 'old.pdf')->assertCreated()->json('data.id');
        $path = DB::table('organization_documents')->where('id', $drop)->value('stored_path');

        $this->actingAs($owner)->spaJson('DELETE', "/api/v1/organizations/{$org->id}/documents/{$drop}")->assertOk();
        $this->assertFileDoesNotExist($this->root.'/'.$path);

        $this->decide($this->adminUser(), $org)->assertOk();
        $this->actingAs($owner)->spaJson('DELETE', "/api/v1/organizations/{$org->id}/documents/{$keep}")->assertStatus(409);
    }
}
