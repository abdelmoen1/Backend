<?php

namespace Tests\Feature;

use App\Contracts\ProjectProposalGenerator;
use App\Enums\ProjectRequestStatus;
use App\Models\IntakeSession;
use App\Models\OrganizationProfile;
use App\Models\User;
use Database\Seeders\ProjectRequestDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProjectProposalFixture;
use Tests\TestCase;

class ProjectRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_can_create_list_show_and_update_its_request(): void
    {
        $organization = $this->organization('one@example.com');

        $created = $this->actingAs($organization, 'sanctum')
            ->postJson('/api/organization/project-requests', [
                'title' => 'Clinic appointments',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Clinic appointments')
            ->assertJsonPath('data.status', ProjectRequestStatus::DRAFT);

        $requestId = $created->json('data.id');

        $this->getJson('/api/organization/project-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/organization/project-requests/{$requestId}")
            ->assertOk()
            ->assertJsonPath('data.id', $requestId);

        $this->patchJson("/api/organization/project-requests/{$requestId}", [
            'title' => 'Clinic appointment system',
            'priority' => 'high',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Clinic appointment system')
            ->assertJsonPath('data.priority', 'high');
    }

    public function test_organization_cannot_access_another_organizations_request(): void
    {
        $owner = $this->organization('owner@example.com');
        $otherOrganization = $this->organization('other@example.com');
        $projectRequest = $owner->organizationProfile->projectRequests()->create([
            'title' => 'Private request',
            'problem_description' => 'A sufficiently detailed problem description.',
            'proposal' => ['summary' => 'Private proposal'],
            'status' => ProjectRequestStatus::PROPOSAL_READY,
        ]);

        $this->actingAs($otherOrganization, 'sanctum')
            ->getJson("/api/organization/project-requests/{$projectRequest->id}")
            ->assertNotFound();

        $this->patchJson("/api/organization/project-requests/{$projectRequest->id}", [
            'title' => 'Stolen update',
        ])->assertNotFound();

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/approve")
            ->assertNotFound();

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/revision", [
            'note' => 'Please revise this private proposal.',
        ])->assertNotFound();
    }

    public function test_intake_is_saved_and_retrieved_and_proposal_can_be_approved(): void
    {
        $organization = $this->organization('intake@example.com');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'Clinic appointments',
        ]);
        IntakeSession::create([
            'project_request_id' => $projectRequest->id,
            'status' => IntakeSession::STATUS_COMPLETED,
        ]);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/intake", [
                'title' => 'Clinic appointment system',
                'problem_description' => 'Appointments are tracked in WhatsApp and paper.',
                'current_process' => ['WhatsApp', 'الأوراق والدفاتر'],
                'target_users' => ['المرضى', 'الموظفون'],
                'desired_outcomes' => ['توفير الوقت'],
                'responses' => ['main_challenge' => 'تنظيم العمل'],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ProjectRequestStatus::INTAKE_IN_PROGRESS)
            ->assertJsonPath('data.intake_data.main_challenge', 'تنظيم العمل');

        $this->getJson("/api/organization/project-requests/{$projectRequest->id}/intake")
            ->assertOk()
            ->assertJsonPath('data.project_request.title', 'Clinic appointment system')
            ->assertJsonPath('data.project_request.intake_data.main_challenge', 'تنظيم العمل')
            ->assertJsonPath('data.questions.0.key', 'main_challenge');

        $generator = \Mockery::mock(ProjectProposalGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andReturn(ProjectProposalFixture::valid());
        $this->app->instance(ProjectProposalGenerator::class, $generator);

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/analyze")
            ->assertOk()
            ->assertJsonPath('data.status', ProjectRequestStatus::PROPOSAL_READY)
            ->assertJsonPath('data.proposal.project_overview.project_name', 'نظام إدارة مواعيد العيادة')
            ->assertJsonPath('data.proposal.estimated_cost', 'تقدير مبدئي: يحدد بعد اعتماد النطاق');

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', ProjectRequestStatus::APPROVED);

        $this->assertDatabaseHas('project_requests', [
            'id' => $projectRequest->id,
            'status' => ProjectRequestStatus::APPROVED,
        ]);
    }

    public function test_revision_preserves_intake_and_proposal_until_approval(): void
    {
        $organization = $this->organization('revision@example.com');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'Project with draft',
            'problem_description' => 'A sufficiently detailed problem description.',
            'intake_data' => ['main_challenge' => 'تنظيم العمل'],
            'proposal' => ['summary' => 'Initial draft'],
            'status' => ProjectRequestStatus::PROPOSAL_READY,
        ]);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/revision", [
                'note' => 'Please clarify the intended scope.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ProjectRequestStatus::REVISION_REQUESTED)
            ->assertJsonPath('data.proposal.summary', 'Initial draft')
            ->assertJsonPath('data.intake_data.main_challenge', 'تنظيم العمل');
    }

    public function test_changing_intake_clears_stale_proposal_and_approved_request_is_locked(): void
    {
        $organization = $this->organization('locked@example.com');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'Request with old proposal',
            'problem_description' => 'An earlier detailed description.',
            'proposal' => ['summary' => 'Old draft'],
            'status' => ProjectRequestStatus::PROPOSAL_READY,
        ]);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/intake", [
                'problem_description' => 'An updated detailed problem description.',
            ])
            ->assertOk()
            ->assertJsonPath('data.proposal', null)
            ->assertJsonPath('data.status', ProjectRequestStatus::INTAKE_IN_PROGRESS);

        $projectRequest->refresh()->update([
            'status' => ProjectRequestStatus::APPROVED,
            'proposal' => ['summary' => 'Approved draft'],
        ]);

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/intake", [
            'problem_description' => 'A different detailed problem description.',
        ])->assertUnprocessable();
    }

    public function test_project_request_validation_and_role_protection_are_enforced(): void
    {
        $organization = $this->organization('validation@example.com');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'Request',
        ]);
        $student = User::create([
            'name' => 'Student',
            'email' => 'student@example.com',
            'password' => 'SecurePass123',
            'role' => 'student',
        ]);

        $this->actingAs($organization, 'sanctum')
            ->postJson('/api/organization/project-requests', ['title' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/organization/project-requests')
            ->assertForbidden();

    }

    public function test_unauthenticated_users_cannot_access_project_requests(): void
    {
        $this->getJson('/api/organization/project-requests')->assertUnauthorized();
    }

    public function test_demo_seeder_creates_a_local_clinic_project_example(): void
    {
        $this->seed(ProjectRequestDemoSeeder::class);

        $this->assertDatabaseHas('users', [
            'email' => 'demo-clinic@example.test',
            'role' => 'organization',
        ]);
        $this->assertDatabaseHas('project_requests', [
            'title' => 'نظام إدارة مواعيد العيادة',
            'problem_description' => 'إدارة المواعيد تتم حاليًا عبر واتساب ودفتر ورقي، مما يسبب تداخلًا في المواعيد وصعوبة في المتابعة.',
        ]);
    }

    public function test_intake_and_revision_reject_invalid_data(): void
    {
        $organization = $this->organization('invalid@example.com');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'Request',
        ]);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/intake", [
                'problem_description' => 'short',
                'priority' => 'impossible',
                'budget_range' => 'invalid',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['problem_description', 'priority', 'budget_range']);

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/revision", [
            'note' => 'no',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note');
    }

    private function organization(string $email): User
    {
        $user = User::create([
            'name' => 'Organization',
            'email' => $email,
            'password' => 'SecurePass123',
            'role' => 'organization',
        ]);

        OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Test organization',
            'onboarding_completed' => true,
        ]);

        return $user->load('organizationProfile');
    }
}
