<?php

namespace Tests\Feature;

use App\Enums\ProjectRequestStatus;
use App\Models\AuditLog;
use App\Models\OrganizationProfile;
use App\Models\ProjectLeaderAssignment;
use App\Models\ProjectOpportunity;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProjectProposalFixture;
use Tests\Support\ReviewsProjects;
use Tests\TestCase;

/**
 * مدير المنصة: لا يُنشأ بالتسجيل، يراجع طلبات المؤسسات قبل النشر، يعيّن قائد الفريق لكل مشروع مع سجل تدقيق،
 * ويوقف الحسابات. كل قاعدة تُفحص عبر طلبات API مباشرة لا عبر الواجهة.
 */
class AdminWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use ReviewsProjects;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (ProjectProposalFixture::STAFFING_SKILLS as $name) {
            Skill::create(['name' => $name]);
        }
    }

    public function test_admin_role_cannot_be_self_registered_and_is_bootstrapped_by_promotion_only(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Sneaky', 'email' => 'sneaky@example.test', 'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123', 'role' => 'admin',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $user = User::create(['name' => 'Ops', 'email' => 'ops@example.test', 'password' => 'SecurePass123', 'role' => 'organization']);
        $this->artisan('gotrainee:promote-admin', ['emails' => ['ops@example.test', 'missing@example.test']])->assertFailed();
        $this->assertTrue($user->fresh()->isAdmin());
        $this->artisan('gotrainee:promote-admin', ['emails' => ['ops@example.test']])->assertSuccessful();
        $this->assertSame(1, AuditLog::query()->where('action', 'user.promoted_to_admin')->count());

        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/me')
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.onboarding_completed', true);
    }

    public function test_only_admins_reach_admin_endpoints(): void
    {
        $opportunity = $this->pendingProject();
        $owner = $opportunity->organization->user;
        $student = $this->student('nosy@example.test');

        foreach ([$owner, $student] as $user) {
            $this->actingAs($user, 'sanctum');
            $this->getJson('/api/admin/overview')->assertForbidden();
            $this->getJson('/api/admin/projects')->assertForbidden();
            $this->postJson("/api/admin/projects/{$opportunity->id}/review", ['decision' => 'approve'])->assertForbidden();
            $this->postJson("/api/admin/projects/{$opportunity->id}/leader", ['user_id' => $student->id])->assertForbidden();
            $this->patchJson("/api/admin/users/{$student->id}", ['is_active' => false])->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/overview')->assertUnauthorized();
        $this->assertSame(ProjectOpportunity::STATUS_PENDING_REVIEW, $opportunity->fresh()->status);
    }

    public function test_admin_reviews_the_queue_with_justified_decisions_and_valid_transitions(): void
    {
        $opportunity = $this->pendingProject();
        $student = $this->student('waiting@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);
        $admin = $this->platformAdmin();

        // قبل المراجعة لا يراه الطلاب
        $this->actingAs($student, 'sanctum')->getJson('/api/opportunities')->assertJsonCount(0, 'data.opportunities');

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/projects?status=pending_review')
            ->assertOk()
            ->assertJsonPath('data.0.id', $opportunity->id)
            ->assertJsonPath('data.0.organization.name', 'Transit Co');
        $this->getJson('/api/admin/overview')->assertOk()->assertJsonPath('data.projects.pending_review', 1);
        $this->getJson("/api/admin/projects/{$opportunity->id}")->assertOk()
            ->assertJsonPath('data.proposal.project_overview.project_name', ProjectProposalFixture::withStaffing()['project_overview']['project_name']);

        // طلب التعديل يحتاج سببًا ويعيد الطلب للمؤسسة
        $this->postJson("/api/admin/projects/{$opportunity->id}/review", ['decision' => 'request_changes'])
            ->assertStatus(422)->assertJsonPath('reason', 'note_required');
        $this->postJson("/api/admin/projects/{$opportunity->id}/review", ['decision' => 'request_changes', 'note' => 'وضّحوا الميزانية المتوقعة.'])
            ->assertOk()->assertJsonPath('data.status', ProjectOpportunity::STATUS_CHANGES_REQUESTED);
        $this->assertSame(ProjectRequestStatus::REVISION_REQUESTED, $opportunity->projectRequest->fresh()->status);
        // لا قبول من حالة "مطلوب تعديل" قبل أن تعيد المؤسسة الاعتماد
        $this->postJson("/api/admin/projects/{$opportunity->id}/review", ['decision' => 'approve'])
            ->assertStatus(409)->assertJsonPath('reason', 'invalid_transition');

        $owner = $opportunity->organization->user;
        $this->actingAs($owner, 'sanctum')->getJson('/api/notifications')
            ->assertOk()->assertJsonPath('unread_count', 1)->assertJsonPath('data.0.type', 'project.review.request_changes');
        $this->postJson("/api/organization/project-requests/{$opportunity->project_request_id}/approve", ProjectProposalFixture::approval())
            ->assertOk()->assertJsonPath('data.publication.status', ProjectOpportunity::STATUS_PENDING_REVIEW);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/projects/{$opportunity->id}/review", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', ProjectOpportunity::STATUS_PUBLISHED);
        $this->actingAs($student, 'sanctum')->getJson('/api/opportunities')->assertJsonCount(1, 'data.opportunities');

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/projects/{$opportunity->id}/review", ['decision' => 'suspend', 'note' => 'مراجعة شكوى'])
            ->assertOk()->assertJsonPath('data.status', ProjectOpportunity::STATUS_SUSPENDED);
        $this->actingAs($student, 'sanctum')->getJson("/api/opportunities/{$opportunity->id}")->assertNotFound();

        $this->assertEqualsCanonicalizing(
            ['proposal.submitted_for_review', 'project.review.request_changes', 'project.review.approve', 'project.review.suspend'],
            AuditLog::query()->where('entity_type', 'ProjectOpportunity')->pluck('action')->unique()->values()->all()
        );
    }

    public function test_admin_assigns_replaces_and_revokes_project_scoped_leaders_with_audit_history(): void
    {
        $opportunity = $this->adminApprove($this->pendingProject());
        $admin = $this->platformAdmin();
        $applicant = $this->student('applicant@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);
        $this->actingAs($applicant, 'sanctum')->postJson("/api/opportunities/{$opportunity->id}/applications")->assertCreated();
        $organization = $opportunity->organization->user;
        $unverified = $this->student('unverified@example.test');
        $strong = $this->student('strong-leader@example.test', ['Git' => 'passed']);
        $strong->studentProfile->update(['weekly_availability_hours' => 25]);

        $this->actingAs($admin, 'sanctum');
        // تعارضات لا تُتجاوز حتى بمبرر: حساب مؤسسة، أو متقدم إلى المشروع نفسه
        foreach ([$organization, $applicant] as $candidate) {
            $this->postJson("/api/admin/projects/{$opportunity->id}/leader", ['user_id' => $candidate->id, 'justification' => str_repeat('مبرر كافٍ ', 5)])
                ->assertStatus(422)->assertJsonPath('reason', 'leader_conflict');
        }
        // مرشح لا يستوفي التوصيات يحتاج مبررًا مكتوبًا
        $this->postJson("/api/admin/projects/{$opportunity->id}/leader", ['user_id' => $unverified->id])
            ->assertStatus(422)->assertJsonPath('reason', 'justification_required')
            ->assertJsonPath('eligibility.blocked', false);
        $this->getJson("/api/admin/projects/{$opportunity->id}/leader-candidates?search=unverified")
            ->assertOk()->assertJsonPath('data.0.eligibility.eligible', false);

        $this->postJson("/api/admin/projects/{$opportunity->id}/leader", ['user_id' => $unverified->id, 'justification' => 'خبرة قيادية سابقة موثقة خارج المنصة'])
            ->assertOk()->assertJsonPath('data.override', true);
        // الاستبدال يلغي التعيين السابق ويحفظ التاريخ
        $this->postJson("/api/admin/projects/{$opportunity->id}/leader", ['user_id' => $strong->id])
            ->assertOk()->assertJsonPath('data.override', false);
        $this->assertSame(1, ProjectLeaderAssignment::query()->active()->count());
        $this->assertSame('replaced', ProjectLeaderAssignment::query()->where('leader_user_id', $unverified->id)->value('revocation_reason'));
        $this->getJson("/api/admin/projects/{$opportunity->id}")->assertOk()
            ->assertJsonCount(2, 'data.leader_history')
            ->assertJsonPath('data.leader.id', $strong->id);

        // القائد يرى مشروعه، والسابق لم يعد يراه
        $this->actingAs($strong, 'sanctum')->getJson('/api/leader/projects')->assertOk()->assertJsonPath('data.0.id', $opportunity->id);
        $this->getJson('/api/me')->assertJsonPath('user.leading_projects_count', 1);
        $this->actingAs($unverified, 'sanctum')->getJson("/api/projects/{$opportunity->id}/matches")->assertNotFound();
        $this->actingAs($strong, 'sanctum')->getJson("/api/projects/{$opportunity->id}/matches")->assertOk();

        // المؤسسة: عرض للقراءة بالقائد والمرحلة، دون ترتيب المرشحين أو نتائج التقييم أو بيانات التواصل
        $overview = $this->actingAs($organization, 'sanctum')->getJson("/api/organization/projects/{$opportunity->id}")
            ->assertOk()->assertJsonPath('data.leader.name', $strong->name);
        foreach (['score', 'factors', 'ranking', 'assessment', 'email'] as $private) {
            $this->assertStringNotContainsString("\"{$private}\"", $overview->getContent());
        }
        $this->getJson("/api/projects/{$opportunity->id}/matches")->assertNotFound();

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/admin/projects/{$opportunity->id}/leader", ['reason' => 'انتهت مدة التكليف'])->assertOk();
        $this->assertSame(0, ProjectLeaderAssignment::query()->active()->count());
        $this->actingAs($strong, 'sanctum')->getJson('/api/leader/projects')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(
            ['project.leader.assigned', 'project.leader.replaced', 'project.leader.revoked'],
            AuditLog::query()->where('action', 'like', 'project.leader.%')->orderBy('id')->pluck('action')->all()
        );
    }

    public function test_a_leader_can_still_apply_to_other_projects_but_not_the_one_they_lead(): void
    {
        $led = $this->adminApprove($this->pendingProject());
        $other = $this->adminApprove($this->pendingProject('second-org@example.test'));
        $leader = $this->assignedLeader($led, 'dual@example.test');
        foreach (['Flutter', 'Dart'] as $name) {
            $skill = Skill::where('name', $name)->first();
            $leader->studentProfile->studentSkills()->create(['skill_id' => $skill->id, 'proficiency' => 'intermediate']);
            $leader->studentProfile->skillAssessments()->create(['skill_id' => $skill->id, 'status' => 'passed', 'level' => 'beginner', 'source' => 'admin_review', 'assessed_at' => now()]);
        }
        $leader->studentProfile->update(['professional_specialization' => 'mobile_development']);

        $this->actingAs($leader, 'sanctum')->postJson("/api/opportunities/{$led->id}/applications")
            ->assertStatus(409)->assertJsonPath('reason', 'leader_of_project');
        $this->postJson("/api/opportunities/{$other->id}/applications")->assertCreated();
    }

    public function test_deactivated_accounts_lose_access_immediately(): void
    {
        $student = $this->student('paused@example.test');
        $token = $student->createToken('auth_token')->plainTextToken;
        $admin = $this->platformAdmin();

        $this->actingAs($admin, 'sanctum')->patchJson("/api/admin/users/{$admin->id}", ['is_active' => false])->assertStatus(422);
        $this->patchJson("/api/admin/users/{$student->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $student->createToken('stale');
        $this->actingAs($student->fresh(), 'sanctum')->getJson('/api/me')->assertForbidden()->assertJsonPath('code', 'account_deactivated');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'paused@example.test', 'password' => 'SecurePass123'])
            ->assertForbidden()->assertJsonPath('code', 'account_deactivated');

        $this->actingAs($admin, 'sanctum')->patchJson("/api/admin/users/{$student->id}", ['is_active' => true])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'paused@example.test', 'password' => 'SecurePass123'])->assertOk();
    }

    private function pendingProject(string $email = 'transit-org@example.test'): ProjectOpportunity
    {
        $user = User::create(['name' => 'Organization', 'email' => $email, 'password' => 'SecurePass123', 'role' => 'organization']);
        $profile = OrganizationProfile::create(['user_id' => $user->id, 'organization_name' => 'Transit Co', 'organization_type' => 'شركة', 'industry' => 'نقل']);
        $projectRequest = $profile->projectRequests()->create([
            'title' => 'نظام المواصلات',
            'proposal' => ProjectProposalFixture::withStaffing(),
            'status' => ProjectRequestStatus::PROPOSAL_READY,
        ]);
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/approve", ProjectProposalFixture::approval())
            ->assertOk();
        $this->app['auth']->forgetGuards();

        return ProjectOpportunity::query()->where('project_request_id', $projectRequest->id)->sole();
    }

    /** @param array<string, string> $skills */
    private function student(string $email, array $skills = []): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'SecurePass123', 'role' => 'student']);
        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'academic_major' => 'هندسة البرمجيات',
            'professional_specialization' => array_intersect(['Flutter', 'Dart'], array_keys($skills)) !== [] ? 'mobile_development' : 'backend_development',
            'onboarding_completed' => true,
        ]);
        foreach ($skills as $name => $status) {
            $skill = Skill::query()->firstOrCreate(['name' => $name]);
            $profile->studentSkills()->create(['skill_id' => $skill->id, 'proficiency' => 'intermediate']);
            $profile->skillAssessments()->create(['skill_id' => $skill->id, 'status' => $status, 'level' => 'beginner', 'source' => 'admin_review', 'assessed_at' => now()]);
        }

        return $user->load('studentProfile');
    }
}
