<?php

namespace Tests\Feature;

use App\Contracts\ProjectProposalGenerator;
use App\Enums\ProjectRequestStatus;
use App\Models\IntakeSession;
use App\Models\OrganizationProfile;
use App\Models\ProjectRequest;
use App\Models\User;
use App\Services\AI\ProjectProposalException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProjectProposalFixture;
use Tests\TestCase;

class ProjectProposalFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_discovery_generates_and_retrieves_all_proposal_sections(): void
    {
        $organization = $this->organization('proposal@example.test');
        $projectRequest = $this->completedProject($organization, 'Clinic appointments');
        $generator = \Mockery::mock(ProjectProposalGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->with(\Mockery::on(fn (ProjectRequest $record): bool => $record->is($projectRequest)))
            ->andReturn(ProjectProposalFixture::valid());
        $this->app->instance(ProjectProposalGenerator::class, $generator);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/analyze")
            ->assertOk()
            ->assertJsonPath('data.status', ProjectRequestStatus::PROPOSAL_READY)
            ->assertJsonPath('data.proposal.project_overview.project_name', 'نظام إدارة مواعيد العيادة')
            ->assertJsonPath('data.proposal.business_problem.impact', 'وقت ضائع وتجربة أقل جودة للمريض.')
            ->assertJsonPath('data.proposal.functional_scope.0.group', 'المواعيد')
            ->assertJsonPath('data.proposal.srs_summary.functional_requirements.0', 'إدارة المواعيد');

        $this->getJson("/api/organization/project-requests/{$projectRequest->id}")
            ->assertOk()
            ->assertJsonPath('data.proposal.required_skills.0', 'تحليل الأعمال')
            ->assertJsonPath('data.proposal.milestones.1.title', 'التطوير');
    }

    public function test_proposal_generation_requires_completed_discovery_and_reuses_existing_proposal(): void
    {
        $organization = $this->organization('not-ready@example.test');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'Incomplete discovery',
            'problem_description' => 'The discovery session is still active.',
        ]);
        IntakeSession::create([
            'project_request_id' => $projectRequest->id,
            'status' => IntakeSession::STATUS_ACTIVE,
        ]);
        $generator = \Mockery::mock(ProjectProposalGenerator::class);
        $generator->shouldNotReceive('generate');
        $this->app->instance(ProjectProposalGenerator::class, $generator);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/analyze")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'يرجى إكمال اكتشاف احتياج المشروع قبل إعداد المقترح.');

        $readyRequest = $this->completedProject($organization, 'Already generated');
        $readyRequest->update([
            'proposal' => ProjectProposalFixture::valid(),
            'status' => ProjectRequestStatus::PROPOSAL_READY,
        ]);
        $this->postJson("/api/organization/project-requests/{$readyRequest->id}/analyze")
            ->assertOk()
            ->assertJsonPath('data.proposal.project_overview.project_name', 'نظام إدارة مواعيد العيادة');
    }

    public function test_revision_preserves_proposal_and_approval_is_protected_from_duplicates(): void
    {
        $organization = $this->organization('review@example.test');
        $projectRequest = $this->completedProject($organization, 'Review project');
        $proposal = ProjectProposalFixture::valid();
        $projectRequest->update([
            'proposal' => $proposal,
            'status' => ProjectRequestStatus::PROPOSAL_READY,
        ]);
        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/revision", [
                'note' => 'يرجى توضيح تكلفة تشغيل النظام.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ProjectRequestStatus::REVISION_REQUESTED)
            ->assertJsonPath('data.proposal.project_overview.project_name', $proposal['project_overview']['project_name'])
            ->assertJsonPath('data.revision_note', 'يرجى توضيح تكلفة تشغيل النظام.');

        $approved = $this->postJson("/api/organization/project-requests/{$projectRequest->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', ProjectRequestStatus::APPROVED);
        $approvedAt = $approved->json('data.approved_at');

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/approve")
            ->assertUnprocessable();

        $this->assertSame(
            $approvedAt,
            $projectRequest->fresh()->approved_at->toISOString()
        );
        $this->assertDatabaseHas('project_requests', [
            'id' => $projectRequest->id,
            'status' => ProjectRequestStatus::APPROVED,
        ]);
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/revision", [
            'note' => 'Please make one more change.',
        ])->assertUnprocessable();
    }

    public function test_failed_revision_generation_preserves_the_existing_proposal(): void
    {
        $organization = $this->organization('failed-revision@example.test');
        $projectRequest = $this->completedProject($organization, 'Revision preservation');
        $proposal = ProjectProposalFixture::valid();
        $projectRequest->update([
            'proposal' => $proposal,
            'revision_note' => 'يرجى تعديل تقدير التكلفة.',
            'status' => ProjectRequestStatus::REVISION_REQUESTED,
        ]);
        $generator = \Mockery::mock(ProjectProposalGenerator::class);
        $generator->shouldReceive('generate')
            ->once()
            ->andThrow(ProjectProposalException::unavailable());
        $this->app->instance(ProjectProposalGenerator::class, $generator);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/analyze")
            ->assertStatus(503)
            ->assertJsonPath('message', 'تعذر إعداد مقترح المشروع حاليًا. حاول مرة أخرى لاحقًا.');

        $this->assertSame($proposal, $projectRequest->fresh()->proposal);
        $this->assertSame(
            ProjectRequestStatus::REVISION_REQUESTED,
            $projectRequest->fresh()->status
        );
    }

    public function test_proposal_endpoints_are_scoped_to_owning_organization(): void
    {
        $owner = $this->organization('owner-proposal@example.test');
        $other = $this->organization('other-proposal@example.test');
        $projectRequest = $this->completedProject($owner, 'Private proposal');
        $projectRequest->update([
            'proposal' => ProjectProposalFixture::valid(),
            'status' => ProjectRequestStatus::PROPOSAL_READY,
        ]);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/organization/project-requests/{$projectRequest->id}")
            ->assertNotFound();
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/analyze")
            ->assertNotFound();
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/revision", [
            'note' => 'Please revise this proposal.',
        ])->assertNotFound();
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/approve")
            ->assertNotFound();
    }

    private function completedProject(User $organization, string $title): ProjectRequest
    {
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => $title,
            'problem_description' => 'Appointments are handled manually and often overlap.',
        ]);
        IntakeSession::create([
            'project_request_id' => $projectRequest->id,
            'status' => IntakeSession::STATUS_COMPLETED,
        ]);

        return $projectRequest;
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
