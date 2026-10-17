<?php

namespace Tests\Feature;

use App\Enums\ProjectRequestStatus;
use App\Models\OrganizationProfile;
use App\Models\ProjectMilestone;
use App\Models\ProjectOpportunity;
use App\Models\ProjectPlanVersion;
use App\Models\ProjectTask;
use App\Models\ProjectTeam;
use App\Models\Skill;
use App\Models\StudentEvidence;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ProjectProposalFixture;
use Tests\Support\ReviewsProjects;
use Tests\TestCase;

/**
 * مرحلة التنفيذ: المراحل من الخطة المتفق عليها، مهام تُسند لأعضاء الفريق فقط، تسليم ومراجعة، إكمال المشروع،
 * تقييم الأعضاء وتقييم المؤسسة، وأدلة الخبرة للمشاركين فقط. مع فحص الصلاحيات عبر الطلبات المباشرة.
 */
class ProjectExecutionTest extends TestCase
{
    use RefreshDatabase;
    use ReviewsProjects;

    private ProjectOpportunity $project;

    private User $owner;

    private User $leader;

    private User $member;

    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (ProjectProposalFixture::STAFFING_SKILLS as $name) {
            Skill::create(['name' => $name]);
        }

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

        $this->member = $this->studentUser('member@example.test', ['Flutter', 'Dart']);
        $application = $this->actingAs($this->member, 'sanctum')->postJson("/api/opportunities/{$this->project->id}/applications")->assertCreated()->json('data.id');
        $mobileRole = $this->project->activeVersion->roles()->where('category', 'mobile_developer')->value('id');

        $this->actingAs($this->leader, 'sanctum');
        $team = $this->postJson("/api/projects/{$this->project->id}/team", ['members' => [['application_id' => $application, 'role_id' => $mobileRole]]])->assertOk()->json('data');
        $this->postJson("/api/teams/{$team['id']}/confirm")->assertOk();
        $this->memberId = ProjectTeam::findOrFail($team['id'])->members()->value('id');

        $url = "/api/projects/{$this->project->id}/plan";
        $versionId = $this->putJson("{$url}/draft", ['content' => $this->plan()])->assertOk()->json('data.versions.0.id');
        $this->postJson("{$url}/versions/{$versionId}/submit")->assertOk();
        $hash = ProjectPlanVersion::findOrFail($versionId)->content_hash;
        $this->actingAs($this->owner, 'sanctum')->postJson("{$url}/versions/{$versionId}/decision", ['decision' => 'approve', 'content_hash' => $hash])->assertOk();
    }

    public function test_execution_actions_are_blocked_before_the_project_starts(): void
    {
        $this->actingAs($this->leader, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/tasks", ['title' => 'مهمة مبكرة'])
            ->assertStatus(409)->assertJsonPath('reason', 'project_not_in_progress');
    }

    public function test_full_execution_lifecycle_from_tasks_to_evidence(): void
    {
        $base = "/api/projects/{$this->project->id}";
        $this->actingAs($this->leader, 'sanctum')->postJson("{$base}/start")->assertOk();

        // المراحل نُسخت من الخطة المتفق عليها
        $dashboard = $this->getJson("{$base}/execution")->assertOk()
            ->assertJsonPath('data.viewer_role', 'leader')
            ->assertJsonCount(2, 'data.milestones')
            ->assertJsonPath('data.milestones.0.title', 'النموذج الأولي')
            ->assertJsonPath('data.permissions.manage', true);
        $milestoneIds = array_column($dashboard->json('data.milestones'), 'id');

        // تحقق المدخلات: موعد خارج مدة الخطة، وإسناد لغير عضو في الفريق
        $outsider = $this->studentUser('outsider@example.test');
        $this->postJson("{$base}/tasks", ['title' => 'مهمة', 'due_date' => '2028-01-01', 'assignee_member_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors(['due_date', 'assignee_member_id']);
        $this->postJson("{$base}/tasks", ['title' => 'مهمة', 'status' => 'done'])->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $taskId = $this->postJson("{$base}/tasks", [
            'title' => 'شاشة الحجز',
            'description' => 'واجهة اختيار الموعد',
            'priority' => 'high',
            'due_date' => '2027-01-20',
            'milestone_id' => $milestoneIds[0],
            'assignee_member_id' => $this->memberId,
        ])->assertCreated()->assertJsonPath('data.tasks.0.assignee_name', 'member@example.test')->json('data.tasks.0.id');
        $otherTaskId = $this->postJson("{$base}/tasks", ['title' => 'لوحة الاستقبال', 'milestone_id' => $milestoneIds[1]])->assertCreated()->json('data.tasks.1.id');
        $this->actingAs($this->member, 'sanctum')->getJson('/api/notifications')->assertJsonPath('data.0.type', 'task.assigned');

        // من لا ينتمي للمشروع لا يرى شيئًا، والمؤسسة والعضو لا يديرون المهام
        $this->actingAs($outsider, 'sanctum')->getJson("{$base}/execution")->assertNotFound();
        $this->actingAs($this->owner, 'sanctum')->postJson("{$base}/tasks", ['title' => 'مهمة'])->assertNotFound();
        $this->actingAs($this->member, 'sanctum')->postJson("{$base}/tasks", ['title' => 'مهمة'])->assertNotFound();
        $this->actingAs($this->member, 'sanctum')->patchJson("{$base}/tasks/{$taskId}", ['status' => 'done'])->assertNotFound();

        // العضو يسلّم مهمته فقط
        $this->actingAs($this->member, 'sanctum')->getJson("{$base}/execution")->assertOk()
            ->assertJsonPath('data.viewer_role', 'member')
            ->assertJsonPath('data.permissions.submit', true)
            ->assertJsonPath('data.my_member_id', $this->memberId);
        $this->postJson("{$base}/tasks/{$otherTaskId}/deliverables", ['notes' => 'محاولة تسليم مهمة غير مسندة'])->assertNotFound();
        $this->postJson("{$base}/tasks/{$taskId}/deliverables", ['notes' => 'x', 'reference_url' => 'javascript:alert(1)'])
            ->assertUnprocessable()->assertJsonValidationErrors(['notes', 'reference_url']);
        $this->postJson("{$base}/tasks/{$taskId}/deliverables", ['notes' => 'أنجزت شاشة الحجز', 'reference_url' => 'https://github.com/example/pr/1'])
            ->assertCreated()->assertJsonPath('data.tasks.0.status', 'in_review');
        $this->postJson("{$base}/tasks/{$taskId}/deliverables", ['notes' => 'تسليم مكرر'])->assertStatus(409)->assertJsonPath('reason', 'not_submittable');
        $this->actingAs($outsider, 'sanctum')->postJson("{$base}/tasks/{$taskId}/deliverables", ['notes' => 'تسليم من غريب'])->assertNotFound();

        // القائد يطلب تعديلًا بملاحظات، ثم يقبل النسخة الثانية
        $this->actingAs($this->leader, 'sanctum')->getJson('/api/notifications')->assertJsonPath('data.0.type', 'deliverable.submitted');
        $this->patchJson("{$base}/tasks/{$taskId}", ['status' => 'done'])->assertStatus(409)->assertJsonPath('reason', 'pending_review');
        $firstDeliverable = ProjectTask::findOrFail($taskId)->deliverables()->value('id');
        $this->postJson("{$base}/deliverables/{$firstDeliverable}/review", ['decision' => 'request_revision'])->assertStatus(422)->assertJsonPath('reason', 'feedback_required');
        $this->actingAs($this->owner, 'sanctum')->postJson("{$base}/deliverables/{$firstDeliverable}/review", ['decision' => 'accept'])->assertNotFound();
        $this->actingAs($this->leader, 'sanctum')
            ->postJson("{$base}/deliverables/{$firstDeliverable}/review", ['decision' => 'request_revision', 'feedback' => 'أضف رسالة تأكيد بعد الحجز.'])
            ->assertOk()->assertJsonPath('data.tasks.0.status', 'changes_requested');
        $this->postJson("{$base}/deliverables/{$firstDeliverable}/review", ['decision' => 'accept'])->assertStatus(409)->assertJsonPath('reason', 'already_reviewed');

        $this->actingAs($this->member, 'sanctum')->getJson("{$base}/execution")
            ->assertJsonPath('data.tasks.0.deliverables.0.feedback', 'أضف رسالة تأكيد بعد الحجز.');
        $this->postJson("{$base}/tasks/{$taskId}/deliverables", ['notes' => 'أضفت رسالة التأكيد', 'reference_url' => 'https://github.com/example/pr/2'])->assertCreated();
        $second = ProjectTask::findOrFail($taskId)->deliverables()->where('revision', 2)->value('id');
        $this->actingAs($this->leader, 'sanctum')->postJson("{$base}/deliverables/{$second}/review", ['decision' => 'accept', 'feedback' => 'ممتاز'])
            ->assertOk()->assertJsonPath('data.tasks.0.status', 'done');

        // المؤسسة ترى التقدم والتسليم المقبول فقط، دون ملاحظات المراجعة
        $orgView = $this->actingAs($this->owner, 'sanctum')->getJson("{$base}/execution")->assertOk()
            ->assertJsonPath('data.viewer_role', 'organization')
            ->assertJsonCount(1, 'data.tasks.0.deliverables')
            ->assertJsonPath('data.tasks.0.deliverables.0.revision', 2)
            ->assertJsonPath('data.tasks.0.deliverables.0.feedback', null)
            ->assertJsonPath('data.permissions.manage', false);
        $this->assertSame(1, $orgView->json('data.progress.tasks_done'));

        // الإكمال يتطلب إغلاق المهام والمراحل
        $this->actingAs($this->leader, 'sanctum')->postJson("{$base}/complete")->assertStatus(409)->assertJsonPath('reason', 'open_tasks');
        $this->patchJson("{$base}/milestones/{$milestoneIds[1]}", ['status' => 'completed'])->assertStatus(409)->assertJsonPath('reason', 'open_tasks');
        $this->patchJson("{$base}/tasks/{$otherTaskId}", ['status' => 'cancelled'])->assertOk();
        $this->postJson("{$base}/complete")->assertStatus(409)->assertJsonPath('reason', 'open_milestones');
        foreach ($milestoneIds as $id) {
            $this->patchJson("{$base}/milestones/{$id}", ['status' => 'completed'])->assertOk();
        }
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/notifications')->assertJsonPath('data.0.type', 'milestone.completed');

        // التقييمات قبل الإكمال مرفوضة
        $this->actingAs($this->leader, 'sanctum')->postJson("{$base}/evaluations", $this->evaluation())->assertStatus(409)->assertJsonPath('reason', 'evaluation_not_open');
        $this->actingAs($this->owner, 'sanctum')->postJson("{$base}/feedback", $this->feedback())->assertStatus(409)->assertJsonPath('reason', 'feedback_not_open');

        $this->actingAs($this->leader, 'sanctum')->postJson("{$base}/complete")->assertOk()
            ->assertJsonPath('data.project.project_status', 'completed')
            ->assertJsonPath('data.permissions.evaluate', true);
        $this->assertSame(ProjectTeam::STATUS_COMPLETED, ProjectTeam::query()->sole()->status);

        // أدلة الخبرة للعضو والقائد فقط
        $this->assertSame(2, StudentEvidence::count());
        $this->assertFalse(StudentEvidence::where('student_profile_id', $outsider->studentProfile->id)->exists());
        $this->actingAs($this->member, 'sanctum')->getJson('/api/student/evidence')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.participation', 'member')
            ->assertJsonPath('data.0.completed_tasks', ['شاشة الحجز'])
            ->assertJsonPath('data.0.leader_evaluation', null);
        $this->actingAs($this->leader, 'sanctum')->getJson('/api/student/evidence')->assertJsonPath('data.0.participation', 'leader');
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/student/evidence')->assertForbidden();

        // تقييم القائد للعضو مرة واحدة، يظهر للعضو ولا يظهر للمؤسسة
        $invalid = $this->evaluation();
        $invalid['criteria']['communication'] = 9;
        $invalid['comments'] = '';
        $this->actingAs($this->leader, 'sanctum')->postJson("{$base}/evaluations", $invalid)->assertUnprocessable()->assertJsonValidationErrors(['criteria.communication', 'comments']);
        $this->postJson("{$base}/evaluations", $this->evaluation())->assertCreated()->assertJsonPath('data.team.members.0.evaluation.overall_score', 4);
        $this->postJson("{$base}/evaluations", $this->evaluation())->assertStatus(409)->assertJsonPath('reason', 'already_evaluated');
        $this->actingAs($this->owner, 'sanctum')->getJson("{$base}/execution")->assertJsonPath('data.team.members.0.evaluation', null);
        $this->actingAs($this->member, 'sanctum')->getJson('/api/student/evidence')->assertJsonPath('data.0.leader_evaluation.overall_score', 4);

        // تقييم المؤسسة النهائي مرة واحدة ويُضاف إلى الأدلة
        $this->actingAs($this->leader, 'sanctum')->postJson("{$base}/feedback", $this->feedback())->assertNotFound();
        $this->actingAs($this->owner, 'sanctum')->postJson("{$base}/feedback", $this->feedback())->assertCreated()
            ->assertJsonPath('data.client_feedback.satisfaction_score', 5)
            ->assertJsonPath('data.permissions.feedback', false);
        $this->postJson("{$base}/feedback", $this->feedback())->assertStatus(409)->assertJsonPath('reason', 'already_submitted');
        $this->actingAs($this->member, 'sanctum')->getJson('/api/student/evidence')->assertJsonPath('data.0.client_satisfaction_score', 5);
        $this->actingAs($this->member, 'sanctum')->getJson('/api/student/projects')->assertOk()
            ->assertJsonPath('data.0.project_status', 'completed')
            ->assertJsonPath('data.0.done_tasks', 1);

        // مدير المنصة يقرأ ولا يدير
        $this->actingAs($this->platformAdmin(), 'sanctum')->getJson("{$base}/execution")->assertOk()->assertJsonPath('data.viewer_role', 'admin');
        $this->postJson("{$base}/tasks", ['title' => 'مهمة'])->assertNotFound();
    }

    public function test_milestones_are_backfilled_for_projects_started_before_execution_tracking(): void
    {
        $base = "/api/projects/{$this->project->id}";
        $this->actingAs($this->leader, 'sanctum')->postJson("{$base}/start")->assertOk();
        ProjectMilestone::query()->delete();

        $this->getJson("{$base}/execution")->assertOk()->assertJsonCount(2, 'data.milestones');
        $this->getJson("{$base}/execution")->assertOk()->assertJsonCount(2, 'data.milestones');
    }

    public function test_application_decisions_notify_the_student(): void
    {
        $applicant = $this->studentUser('applicant@example.test', ['Flutter', 'Dart']);
        $other = ProjectOpportunity::query()->sole();
        $other->forceFill(['project_status' => ProjectOpportunity::PROJECT_OPEN])->save();
        $application = $this->actingAs($applicant, 'sanctum')->postJson("/api/opportunities/{$other->id}/applications")->assertCreated()->json('data.id');

        $this->actingAs($this->leader, 'sanctum')->patchJson("/api/projects/{$other->id}/applications/{$application}", ['status' => 'rejected'])->assertOk();
        $this->actingAs($applicant, 'sanctum')->getJson('/api/notifications')->assertJsonPath('data.0.type', 'application.rejected');
    }

    private function evaluation(): array
    {
        return [
            'member_id' => $this->memberId,
            'criteria' => ['technical_quality' => 5, 'communication' => 4, 'reliability' => 4, 'teamwork' => 4],
            'comments' => 'التزم بالمواعيد وقدم عملًا نظيفًا.',
        ];
    }

    private function feedback(): array
    {
        return ['satisfaction_score' => 5, 'leader_score' => 4, 'would_recommend' => true, 'comments' => 'نتيجة ممتازة وتواصل واضح.'];
    }

    private function plan(): array
    {
        return [
            'scope' => 'نظام حجز مواعيد للعيادة مع لوحة تحكم للاستقبال.',
            'deliverables' => [['title' => 'تطبيق الحجز']],
            'acceptance_criteria' => ['يحجز المريض موعدًا خلال دقيقة'],
            'execution_budget' => 2000,
            'currency' => 'USD',
            'payment_schedule' => [['label' => 'دفعة واحدة', 'percentage' => 100]],
            'timeline' => ['start_date' => '2027-01-01', 'end_date' => '2027-03-31'],
            'milestones' => [
                ['title' => 'النموذج الأولي', 'due_date' => '2027-02-01'],
                ['title' => 'الإطلاق', 'due_date' => '2027-03-30'],
            ],
            'sprints' => [],
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
            'weekly_availability_hours' => 30,
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
