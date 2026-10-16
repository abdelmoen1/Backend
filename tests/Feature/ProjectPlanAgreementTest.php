<?php

namespace Tests\Feature;

use App\Enums\ProjectRequestStatus;
use App\Models\AuditLog;
use App\Models\OrganizationProfile;
use App\Models\ProjectOpportunity;
use App\Models\ProjectPlanVersion;
use App\Models\ProjectTeam;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Projects\ProjectPlanning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProjectProposalFixture;
use Tests\Support\ReviewsProjects;
use Tests\TestCase;

/**
 * مساحة التخطيط والاتفاق: نسخ مرقمة، المقدمة مجمدة ببصمتها، موافقة مزدوجة على النسخة والبصمة نفسيهما،
 * حماية من الموافقات القديمة، ولا تنفيذ قبل خطة متفق عليها وفريق مؤكد.
 */
class ProjectPlanAgreementTest extends TestCase
{
    use RefreshDatabase;
    use ReviewsProjects;

    private ProjectOpportunity $project;

    private User $owner;

    private User $leader;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (ProjectProposalFixture::STAFFING_SKILLS as $name) {
            Skill::create(['name' => $name]);
        }
        config(['gotrainee.platform_commission_rate' => 0.1, 'gotrainee.requirements_gathering_fee' => 150]);

        $this->owner = User::create(['name' => 'Org Owner', 'email' => 'owner@example.test', 'password' => 'SecurePass123', 'role' => 'organization']);
        $profile = OrganizationProfile::create(['user_id' => $this->owner->id, 'organization_name' => 'Clinic', 'organization_type' => 'عيادة', 'industry' => 'صحة']);
        $request = $profile->projectRequests()->create([
            'title' => 'نظام المواعيد',
            'proposal' => ProjectProposalFixture::withStaffing(),
            'status' => ProjectRequestStatus::PROPOSAL_READY,
        ]);
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/organization/project-requests/{$request->id}/approve", ProjectProposalFixture::approval())
            ->assertOk();
        $this->project = $this->adminApprove(ProjectOpportunity::query()->sole());
        $this->leader = $this->assignedLeader($this->project, 'leader@example.test');
    }

    public function test_leader_drafts_and_submits_with_server_computed_financials(): void
    {
        $url = "/api/projects/{$this->project->id}/plan";

        // المؤسسة لا تكتب الخطة، والطالب العادي لا يصل إليها
        $this->actingAs($this->owner, 'sanctum')->putJson("{$url}/draft", ['content' => $this->plan()])->assertNotFound();
        $outsider = $this->studentUser('outsider@example.test');
        $this->actingAs($outsider, 'sanctum')->getJson($url)->assertNotFound();

        $invalid = $this->plan();
        $invalid['payment_schedule'][1]['percentage'] = 30;
        $invalid['milestones'][0]['due_date'] = '2027-12-01';
        $this->actingAs($this->leader, 'sanctum')->putJson("{$url}/draft", ['content' => $invalid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payment_schedule', 'milestones.0.due_date']);

        $draft = $this->plan();
        $draft['financials'] = ['organization_total' => 1]; // أرقام العميل المحسوبة تُتجاهل
        $response = $this->putJson("{$url}/draft", ['content' => $draft])->assertOk()
            ->assertJsonPath('data.versions.0.version', 1)
            ->assertJsonPath('data.versions.0.status', 'draft')
            ->assertJsonPath('data.versions.0.content.financials.execution_budget', 2000)
            ->assertJsonPath('data.versions.0.content.financials.platform_commission', 200)
            ->assertJsonPath('data.versions.0.content.financials.requirements_gathering_fee', 150)
            ->assertJsonPath('data.versions.0.content.financials.organization_total', 2350)
            ->assertJsonPath('data.versions.0.content.payment_schedule.0.amount', 800)
            ->assertJsonPath('data.permissions.submit', true);
        $versionId = $response->json('data.versions.0.id');

        // حفظ آخر يعدّل المسودة نفسها ولا ينشئ نسخة
        $this->putJson("{$url}/draft", ['content' => $this->plan(scope: 'نطاق معدل لنظام حجز المواعيد للعيادة.')])->assertOk()->assertJsonCount(1, 'data.versions');

        $this->postJson("{$url}/versions/{$versionId}/submit")->assertOk()
            ->assertJsonPath('data.versions.0.status', 'submitted')
            ->assertJsonPath('data.permissions.edit', false);
        $hash = ProjectPlanVersion::find($versionId)->content_hash;
        $this->assertSame(64, strlen($hash));
        // النسخة المقدمة مجمدة: لا تقديم ثانٍ ولا مسودة جديدة قبل قرار المؤسسة
        $this->postJson("{$url}/versions/{$versionId}/submit")->assertStatus(409)->assertJsonPath('reason', 'not_draft');
        $this->putJson("{$url}/draft", ['content' => $this->plan()])->assertStatus(409)->assertJsonPath('reason', 'awaiting_decision');
        $this->assertSame($hash, ProjectPlanVersion::find($versionId)->content_hash);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/notifications')->assertJsonPath('data.0.type', 'plan.submitted');
    }

    public function test_dual_approval_requires_the_same_version_and_content_hash(): void
    {
        $url = "/api/projects/{$this->project->id}/plan";
        $v1 = $this->submitted($this->plan());

        $this->actingAs($this->owner, 'sanctum');
        // القائد لا يوافق نيابة عن المؤسسة
        $this->actingAs($this->leader, 'sanctum')
            ->postJson("{$url}/versions/{$v1->id}/decision", ['decision' => 'approve', 'content_hash' => $v1->content_hash])
            ->assertNotFound();

        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("{$url}/versions/{$v1->id}/decision", ['decision' => 'approve', 'content_hash' => str_repeat('0', 64)])
            ->assertStatus(409)->assertJsonPath('reason', 'stale_content');
        $this->postJson("{$url}/versions/{$v1->id}/decision", ['decision' => 'request_changes', 'content_hash' => $v1->content_hash])
            ->assertStatus(422)->assertJsonPath('reason', 'note_required');
        $this->postJson("{$url}/versions/{$v1->id}/comments", ['body' => 'نحتاج دفعة أولى أصغر.'])->assertCreated();
        $this->postJson("{$url}/versions/{$v1->id}/decision", ['decision' => 'request_changes', 'content_hash' => $v1->content_hash, 'note' => 'اجعلوا الدفعة الأولى 20%.'])
            ->assertOk()->assertJsonPath('data.versions.0.status', 'changes_requested');

        // القائد ينشئ النسخة 2 ويقدمها؛ القرار على النسخة 1 أصبح قديمًا
        $revised = $this->plan();
        $revised['payment_schedule'] = [
            ['label' => 'دفعة البداية', 'percentage' => 20],
            ['label' => 'التسليم النهائي', 'percentage' => 80, 'due_date' => '2027-03-30'],
        ];
        $v2 = $this->submitted($revised);
        $this->assertSame(2, $v2->version);
        $this->actingAs($this->leader, 'sanctum')->postJson("{$url}/versions/{$v2->id}/comments", ['body' => 'تم تعديل الدفعات.'])->assertCreated();

        $this->actingAs($this->owner, 'sanctum');
        $this->postJson("{$url}/versions/{$v1->id}/decision", ['decision' => 'approve', 'content_hash' => $v1->content_hash])
            ->assertStatus(409)->assertJsonPath('reason', 'stale_version');
        $this->postJson("{$url}/versions/{$v2->id}/decision", ['decision' => 'approve', 'content_hash' => $v2->content_hash])
            ->assertOk()
            ->assertJsonPath('data.versions.0.status', 'approved')
            ->assertJsonPath('data.versions.0.is_agreed', true)
            ->assertJsonCount(1, 'data.versions.1.comments');
        // موافقة ثانية (نقرة مكررة) لا تغيّر شيئًا
        $this->postJson("{$url}/versions/{$v2->id}/decision", ['decision' => 'approve', 'content_hash' => $v2->content_hash])->assertStatus(409);

        $project = $this->project->fresh();
        $this->assertSame($v2->id, $project->agreed_plan_version_id);
        $this->assertNotNull($project->agreed_at);
        // خط الأساس لا يُعدل بعد الاتفاق
        $this->actingAs($this->leader, 'sanctum')->putJson("{$url}/draft", ['content' => $this->plan()])
            ->assertStatus(409)->assertJsonPath('reason', 'plan_agreed');
        $this->assertSame(ProjectPlanning::hash($v2->fresh()->content), $v2->fresh()->content_hash);
        $this->assertSame(['plan.submitted', 'plan.changes_requested', 'plan.submitted', 'plan.approved'],
            AuditLog::query()->where('action', 'like', 'plan.%')->orderBy('id')->pluck('action')->all());

        // مدير المنصة يقرأ فقط
        $this->actingAs($this->platformAdmin(), 'sanctum')->getJson($url)->assertOk()->assertJsonPath('data.viewer_role', 'admin')->assertJsonPath('data.permissions.edit', false);
        $this->postJson("{$url}/versions/{$v2->id}/comments", ['body' => 'ملاحظة'])->assertForbidden();
        $this->actingAs($this->leader, 'sanctum')->getJson('/api/notifications')->assertJsonPath('data.0.type', 'plan.approve');
    }

    public function test_execution_starts_only_after_agreement_and_a_confirmed_team(): void
    {
        $start = "/api/projects/{$this->project->id}/start";
        $student = $this->studentUser('member@example.test', ['Flutter', 'Dart']);
        $application = $this->actingAs($student, 'sanctum')->postJson("/api/opportunities/{$this->project->id}/applications")->assertCreated()->json('data.id');
        $mobileRole = $this->project->activeVersion->roles()->where('category', 'mobile_developer')->value('id');

        $this->actingAs($this->leader, 'sanctum');
        $teamId = $this->postJson("/api/projects/{$this->project->id}/team", ['members' => [['application_id' => $application, 'role_id' => $mobileRole]]])->assertOk()->json('data.id');
        $this->postJson("/api/teams/{$teamId}/confirm")->assertOk();

        $this->postJson($start)->assertStatus(409)->assertJsonPath('reason', 'plan_not_agreed');
        $plan = $this->submitted($this->plan());
        $this->actingAs($this->owner, 'sanctum')->postJson($start)->assertNotFound();
        $this->postJson("/api/projects/{$this->project->id}/plan/versions/{$plan->id}/decision", ['decision' => 'approve', 'content_hash' => $plan->content_hash])->assertOk();

        $this->actingAs($this->leader, 'sanctum')->postJson($start)->assertOk()->assertJsonPath('data.project_status', 'in_progress');
        $this->postJson($start)->assertStatus(409)->assertJsonPath('reason', 'already_started');
        $this->assertSame(ProjectTeam::STATUS_ACTIVE, ProjectTeam::find($teamId)->status);
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/organization/projects/{$this->project->id}")
            ->assertOk()
            ->assertJsonPath('data.project_status', 'in_progress')
            ->assertJsonPath('data.plan.agreed', true)
            ->assertJsonPath('data.team.members.0.name', 'member@example.test');
    }

    public function test_plan_hash_is_stable_across_json_key_order_and_number_formats(): void
    {
        $content = (new ProjectPlanning)->normalize($this->plan());
        // ما يعيده عمود JSON في MySQL: مفاتيح بترتيب مختلف و 2000.0 => 2000
        $reordered = array_reverse($content, true);
        $reordered['financials'] = array_reverse($content['financials'], true);
        $reordered['execution_budget'] = 2000;
        $this->assertSame(ProjectPlanning::hash($content), ProjectPlanning::hash($reordered));

        $changed = $content;
        $changed['execution_budget'] = 2000.5;
        $this->assertNotSame(ProjectPlanning::hash($content), ProjectPlanning::hash($changed));
    }

    private function submitted(array $content): ProjectPlanVersion
    {
        $url = "/api/projects/{$this->project->id}/plan";
        $id = $this->actingAs($this->leader, 'sanctum')->putJson("{$url}/draft", ['content' => $content])->assertOk()->json('data.versions.0.id');
        $this->postJson("{$url}/versions/{$id}/submit")->assertOk();

        return ProjectPlanVersion::findOrFail($id);
    }

    private function plan(string $scope = 'نظام حجز مواعيد للعيادة مع لوحة تحكم للاستقبال.'): array
    {
        return [
            'scope' => $scope,
            'deliverables' => [['title' => 'تطبيق الحجز', 'description' => 'واجهة المرضى'], ['title' => 'لوحة الاستقبال']],
            'acceptance_criteria' => ['يحجز المريض موعدًا خلال دقيقة', 'تظهر الحجوزات للاستقبال فورًا'],
            'execution_budget' => 2000,
            'currency' => 'USD',
            'payment_schedule' => [
                ['label' => 'دفعة البداية', 'percentage' => 40, 'due_date' => '2027-01-10'],
                ['label' => 'التسليم النهائي', 'percentage' => 60, 'due_date' => '2027-03-30'],
            ],
            'timeline' => ['start_date' => '2027-01-01', 'end_date' => '2027-03-31'],
            'milestones' => [
                ['title' => 'النموذج الأولي', 'due_date' => '2027-02-01'],
                ['title' => 'الإطلاق', 'due_date' => '2027-03-30'],
            ],
            'sprints' => [
                ['name' => 'Sprint 1', 'start_date' => '2027-01-01', 'end_date' => '2027-01-14', 'goal' => 'الحجز الأساسي'],
            ],
        ];
    }

    /** @param array<int, string> $passed */
    private function studentUser(string $email, array $passed = []): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'SecurePass123', 'role' => 'student']);
        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'academic_major' => 'هندسة البرمجيات',
            'professional_specialization' => 'mobile_development',
            'onboarding_completed' => true,
        ]);
        foreach ($passed as $name) {
            $skill = Skill::where('name', $name)->first();
            $profile->studentSkills()->create(['skill_id' => $skill->id, 'proficiency' => 'intermediate']);
            $profile->skillAssessments()->create(['skill_id' => $skill->id, 'status' => 'passed', 'level' => 'beginner', 'source' => 'admin_review', 'assessed_at' => now()]);
        }

        return $user->load('studentProfile');
    }
}
