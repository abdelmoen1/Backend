<?php

namespace Tests\Feature;

use App\Enums\ProjectRequestStatus;
use App\Models\OrganizationProfile;
use App\Models\ProjectApplication;
use App\Models\ProjectOpportunity;
use App\Models\ProjectTeam;
use App\Models\Skill;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\ProjectProposalFixture;
use Tests\Support\ReviewsProjects;
use Tests\TestCase;

/**
 * Sprint 4: التقديم على الفرص، ترتيب المرشحين، وتكوين الفريق بيد قائد الفريق الذي يعيّنه مدير المنصة.
 * المشروع من ProjectProposalFixture: دور جوال (Flutter + Dart، مرغوب Git) ودور خلفية (Laravel + REST API، مرغوب MySQL)،
 * كل دور لشخص واحد، والمستوى الأدنى مبتدئ ما لم يُذكر غيره.
 */
class ProjectTeamFormationTest extends TestCase
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

    public function test_an_eligible_student_applies_for_themselves_exactly_once(): void
    {
        $project = $this->project();
        $student = $this->student('apply@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);
        $other = $this->student('other@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);
        $mobileRole = $this->roleId($project, 'mobile_developer');

        Log::spy();
        // معرّف طالب آخر في جسم الطلب يُتجاهل: الطلب دائمًا لصاحب الجلسة
        $this->actingAs($student, 'sanctum')
            ->postJson($this->applyUrl($project), ['student_profile_id' => $other->studentProfile->id, 'note' => 'متحمس للمشروع'])
            ->assertCreated()
            ->assertJsonPath('data.status', ProjectApplication::STATUS_PENDING)
            ->assertJsonPath('data.preferred_role.id', $mobileRole)
            ->assertJsonPath('data.opportunity.id', $project->id);

        $application = ProjectApplication::sole();
        $this->assertSame($student->studentProfile->id, $application->student_profile_id);
        $this->assertNotNull($application->created_at);
        Log::shouldHaveReceived('info')->withArgs(fn (string $message): bool => $message === 'Project application submitted.')->once();

        $this->postJson($this->applyUrl($project))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'already_applied')
            ->assertJsonPath('application.id', $application->id);
        $this->assertSame(1, ProjectApplication::count());

        // الطالب يرى طلبه في الفرصة وفي صفحة طلباتي
        $this->getJson("/api/opportunities/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.application.status', ProjectApplication::STATUS_PENDING);
        $this->getJson('/api/student/applications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.opportunity.title', $project->title);
        $this->actingAs($other, 'sanctum')->getJson('/api/student/applications')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_ineligible_closed_and_unpublished_opportunities_reject_applications(): void
    {
        $project = $this->project();
        $owner = $this->owner($project);
        $leader = $this->assignedLeader($project);
        $ineligible = $this->student('ineligible@example.test', ['Flutter' => 'failed', 'Dart' => null]);
        $eligible = $this->student('eligible@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);

        $this->actingAs($ineligible, 'sanctum')->postJson($this->applyUrl($project))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'not_eligible');
        // دور لا يستوفيه الطالب أو دور من خارج المشروع
        $this->actingAs($eligible, 'sanctum')
            ->postJson($this->applyUrl($project), ['preferred_role_id' => $this->roleId($project, 'backend_developer')])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'role_not_eligible');
        $this->postJson($this->applyUrl($project), ['preferred_role_id' => 99999])->assertStatus(422);

        // المؤسسة والمستخدم غير المسجل لا يقدّمان
        $this->actingAs($owner, 'sanctum')->postJson($this->applyUrl($project))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson($this->applyUrl($project))->assertUnauthorized();

        // قائد الفريق لا يتقدم إلى المشروع الذي يقوده
        $this->actingAs($leader, 'sanctum')->postJson($this->applyUrl($project))->assertStatus(409)->assertJsonPath('reason', 'leader_of_project');

        // إغلاق التقديم بيد القائد: الفرصة تختفي من القائمة ويُرفض التقديم الجديد
        $this->actingAs($leader, 'sanctum')->patchJson("/api/projects/{$project->id}", ['project_status' => 'matching'])
            ->assertOk()
            ->assertJsonPath('data.project_status', 'matching')
            ->assertJsonPath('data.accepting_applications', false);
        $this->actingAs($eligible, 'sanctum')->postJson($this->applyUrl($project))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'closed');
        $this->getJson('/api/opportunities')->assertOk()->assertJsonCount(0, 'data.opportunities');
        $this->getJson("/api/opportunities/{$project->id}")->assertNotFound();

        // انتقال غير مسموح يدويًا
        $this->actingAs($leader, 'sanctum')->patchJson("/api/projects/{$project->id}", ['project_status' => 'completed'])
            ->assertStatus(409);

        // فرصة معلقة = غير موجودة للطالب
        $project->update(['status' => ProjectOpportunity::STATUS_SUSPENDED, 'project_status' => 'open']);
        $this->actingAs($eligible, 'sanctum')->postJson($this->applyUrl($project))->assertNotFound();
        $this->assertSame(0, ProjectApplication::count());
    }

    public function test_listing_is_paginated_filterable_and_applicants_keep_access_after_closing(): void
    {
        $first = $this->project();
        $second = $this->project();
        $student = $this->student('browse@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);
        $this->actingAs($student, 'sanctum')->postJson($this->applyUrl($first))->assertCreated();

        $this->getJson('/api/opportunities?per_page=1&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.opportunities')
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.meta.last_page', 2)
            ->assertJsonPath('data.meta.counts.eligible', 2)
            ->assertJsonPath('data.meta.counts.applied', 1);
        $this->getJson('/api/opportunities?q='.urlencode('غير موجود'))->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/opportunities?filter=bogus')->assertUnprocessable();

        $first->update(['project_status' => 'matching']);
        $this->getJson('/api/opportunities')->assertOk()->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.opportunities.0.id', $second->id);
        $this->getJson("/api/opportunities/{$first->id}")->assertOk()->assertJsonPath('data.application.status', 'pending')
            ->assertJsonPath('data.accepting_applications', false);
    }

    public function test_students_withdraw_only_their_own_active_applications(): void
    {
        $project = $this->project();
        $student = $this->student('withdraw@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);
        $intruder = $this->student('intruder@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);
        $id = $this->actingAs($student, 'sanctum')->postJson($this->applyUrl($project))->json('data.id');

        $this->actingAs($intruder, 'sanctum')->postJson("/api/student/applications/{$id}/withdraw")->assertNotFound();
        $this->actingAs($student, 'sanctum')->postJson("/api/student/applications/{$id}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', ProjectApplication::STATUS_WITHDRAWN);
        $this->postJson("/api/student/applications/{$id}/withdraw")->assertStatus(409);
        // السحب نهائي: لا تقديم ثانٍ على الفرصة نفسها
        $this->postJson($this->applyUrl($project))->assertStatus(409)->assertJsonPath('reason', 'already_applied');
    }

    public function test_matching_ranks_applicants_with_explainable_factors_and_handles_missing_data(): void
    {
        $project = $this->project();
        $leader = $this->assignedLeader($project);
        $project->update(['min_weekly_hours' => 10]);
        $mobileRole = $this->roleId($project, 'mobile_developer');
        $backendRole = $this->roleId($project, 'backend_developer');

        // قوي: مستوى أعلى من المطلوب، مهارة مرغوبة مثبتة، أدلة أعمال، وتفرغ كافٍ
        $strong = $this->student('strong@example.test', ['Flutter' => 'passed:advanced', 'Dart' => 'passed:intermediate', 'Git' => 'passed'], 'advanced');
        $strong->studentProfile->update(['weekly_availability_hours' => 20, 'github_url' => 'https://github.com/strong']);
        $strong->studentProfile->profileDetails()->create(['portfolio' => [['title' => 'تطبيق مواعيد'], ['title' => 'متجر']], 'certifications' => [['name' => 'Flutter Cert']]]);
        // الحد الأدنى فقط وبيانات ناقصة (لا تفرغ ولا أدلة)
        $minimal = $this->student('minimal@example.test', ['Flutter' => 'passed', 'Dart' => 'passed'], 'beginner');
        // مستوفٍ لم يقدّم: يُحتسب في العدد فقط دون هويته
        $this->student('silent@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);

        foreach ([$minimal, $strong] as $student) {
            $this->actingAs($student, 'sanctum')->postJson($this->applyUrl($project))->assertCreated();
        }
        // متقدم لم يعد يستوفي الدور (مثلًا بعد رفع المستوى المطلوب): يظهر في قائمة منفصلة
        $lapsed = $this->student('lapsed@example.test', ['Flutter' => 'failed', 'Dart' => 'passed']);
        ProjectApplication::create(['project_opportunity_id' => $project->id, 'student_profile_id' => $lapsed->studentProfile->id, 'status' => 'pending']);

        $response = $this->actingAs($leader, 'sanctum')->getJson("/api/projects/{$project->id}/matches")->assertOk();

        $response->assertJsonCount(2, 'data.candidates')
            ->assertJsonPath('data.candidates.0.student.name', 'strong@example.test')
            ->assertJsonPath('data.candidates.0.best_role_id', $mobileRole)
            ->assertJsonPath('data.candidates.1.student.name', 'minimal@example.test')
            ->assertJsonPath('data.ranking.'.$mobileRole, [
                ProjectApplication::where('student_profile_id', $strong->studentProfile->id)->value('id'),
                ProjectApplication::where('student_profile_id', $minimal->studentProfile->id)->value('id'),
            ])
            ->assertJsonPath('data.ineligible.0.student.name', 'lapsed@example.test')
            ->assertJsonPath('data.ineligible.0.eligibility.missing_skills.0.name', 'Flutter')
            ->assertJsonPath('data.summary.eligible_applicants', 2)
            ->assertJsonPath('data.summary.ineligible_applicants', 1)
            ->assertJsonPath('data.summary.required_team_size', 2)
            ->assertJsonPath('data.summary.insufficient', true)
            ->assertJsonPath('data.summary.short_roles', ['مطوّر الخدمات الخلفية'])
            ->assertJsonPath('data.roles.0.eligible_applicants', 2)
            ->assertJsonPath('data.roles.0.eligible_students', 3)
            ->assertJsonPath('data.roles.1.id', $backendRole)
            ->assertJsonPath('data.roles.1.insufficient', true);

        $strongScore = $response->json('data.candidates.0.roles.0');
        $minimalScore = $response->json('data.candidates.1.roles.0');
        $this->assertGreaterThan($minimalScore['score'], $strongScore['score']);
        $this->assertSame(['assessment', 'proficiency', 'desirable', 'evidence', 'availability'], array_column($strongScore['factors'], 'key'));
        // تقييم 38/40 (Flutter أعلى بمستويين = 1، Dart أعلى بمستوى = 0.9) + معلن 10 + مرغوب 15
        // + أدلة 11/15 (عملان 6 + شهادة 2 + GitHub 3) + توفر 20 = 94
        $this->assertSame(94, $strongScore['score']);
        $this->assertSame([38, 10, 15, 11, 20], array_column($strongScore['factors'], 'points'));

        $factors = collect($minimalScore['factors'])->keyBy('key');
        $this->assertSame('missing', $factors['availability']['status']);
        $this->assertSame('missing', $factors['evidence']['status']);
        $this->assertSame(0, $factors['evidence']['points']);
        $this->assertStringContainsString('لم يحدد ساعات تفرغه', $factors['availability']['detail']);
        // مقارنة المهارات: الإلزامية بالمستوى المطلوب والمثبت والمعلن، والمرغوبة
        $skills = collect($strongScore['skills'])->keyBy('name');
        $this->assertSame('advanced', $skills['Flutter']['verified_level']);
        $this->assertSame('beginner', $skills['Flutter']['required_level']);
        $this->assertSame('desirable', $skills['Git']['importance']);
        $this->assertTrue($skills['Git']['met']);

        // من لم يقدّم لا تظهر هويته حتى للقائد
        $this->assertStringNotContainsString('silent@example.test', $response->getContent());
    }

    public function test_matching_and_team_management_are_restricted_to_the_assigned_leader(): void
    {
        $project = $this->project();
        $owner = $this->owner($project);
        $leader = $this->assignedLeader($project);
        $otherProject = $this->project();
        $otherLeader = $this->assignedLeader($otherProject);
        $student = $this->student('member@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);
        $applicationId = $this->actingAs($student, 'sanctum')->postJson($this->applyUrl($project))->json('data.id');
        $teamId = $this->actingAs($leader, 'sanctum')->postJson("/api/projects/{$project->id}/team", ['members' => [
            ['application_id' => $applicationId, 'role_id' => $this->roleId($project, 'mobile_developer')],
        ]])->assertOk()->json('data.id');

        $otherOrganization = $this->organization('rival@example.test');
        $calls = [
            ['get', "/api/projects/{$project->id}/matches", []],
            ['patch', "/api/projects/{$project->id}", ['project_status' => 'matching']],
            ['patch', "/api/projects/{$project->id}/applications/{$applicationId}", ['status' => 'rejected']],
            ['get', "/api/projects/{$project->id}/team", []],
            ['post', "/api/projects/{$project->id}/team", ['members' => []]],
            ['post', "/api/teams/{$teamId}/confirm", []],
        ];

        foreach ($calls as [$method, $url, $body]) {
            // لا أحد غير القائد المعيّن: المؤسسة المالكة نفسها، مؤسسة أخرى، قائد مشروع آخر، وعضو الفريق — كلها 404
            // دون كشف وجود المورد (IDOR). المؤسسة لا ترى ترتيب المرشحين ولا تختار الفريق.
            foreach ([$owner, $otherOrganization, $otherLeader, $student] as $intruder) {
                $this->actingAs($intruder, 'sanctum')->json($method, $url, $body)->assertNotFound();
            }
        }
        // مسار تعيين القائد من الفريق أزيل: القائد يعيّنه مدير المنصة فقط
        $this->actingAs($owner, 'sanctum')->postJson("/api/teams/{$teamId}/leader", ['student_profile_id' => $student->studentProfile->id])->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/projects/{$project->id}/matches")->assertUnauthorized();

        $team = ProjectTeam::query()->where('project_opportunity_id', $project->id)->sole();
        $this->assertSame($leader->studentProfile->id, $team->leader_student_profile_id);
        $this->assertSame(ProjectTeam::STATUS_FORMING, $team->status);
        $this->assertSame('team_formation', $project->fresh()->project_status);
    }

    public function test_team_members_must_come_from_the_eligible_applicant_pool_without_duplicates(): void
    {
        $project = $this->project();
        $leader = $this->assignedLeader($project);
        $mobileRole = $this->roleId($project, 'mobile_developer');
        $backendRole = $this->roleId($project, 'backend_developer');

        $mobile = $this->applicant($project, 'mobile@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);
        $mobile2 = $this->applicant($project, 'mobile2@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);
        $rejected = $this->applicant($project, 'rejected@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);
        $this->actingAs($leader, 'sanctum')->patchJson("/api/projects/{$project->id}/applications/{$rejected}", ['status' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        // طلب من مشروع آخر لا يدخل فريق هذا المشروع
        $otherProject = $this->project();
        $outsider = $this->applicant($otherProject, 'outsider@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);

        $url = "/api/projects/{$project->id}/team";
        $this->actingAs($leader, 'sanctum');
        $cases = [
            'duplicate_member' => [['application_id' => $mobile, 'role_id' => $mobileRole], ['application_id' => $mobile, 'role_id' => $mobileRole]],
            'not_in_pool' => [['application_id' => $outsider, 'role_id' => $mobileRole]],
            'unknown_role' => [['application_id' => $mobile, 'role_id' => $this->roleId($otherProject, 'mobile_developer')]],
            'not_eligible' => [['application_id' => $mobile, 'role_id' => $backendRole]],
            'role_full' => [['application_id' => $mobile, 'role_id' => $mobileRole], ['application_id' => $mobile2, 'role_id' => $mobileRole]],
            'application_inactive' => [['application_id' => $rejected, 'role_id' => $mobileRole]],
            'empty_team' => [],
        ];
        foreach ($cases as $reason => $members) {
            $this->postJson($url, ['members' => $members])->assertStatus(422)->assertJsonPath('reason', $reason);
        }
        $this->assertSame(0, ProjectTeam::query()->where('project_opportunity_id', $project->id)->count());

        Log::spy();
        $this->postJson($url, ['members' => [['application_id' => $mobile, 'role_id' => $mobileRole]]])
            ->assertOk()
            ->assertJsonPath('data.status', ProjectTeam::STATUS_FORMING)
            ->assertJsonCount(1, 'data.members')
            ->assertJsonPath('data.members.0.role.id', $mobileRole);
        Log::shouldHaveReceived('info')->withArgs(fn (string $message): bool => $message === 'Project team created.')->once();
        $this->assertSame('accepted', ProjectApplication::find($mobile)->status);
        $this->assertSame('team_formation', $project->fresh()->project_status);

        // استبدال العضو: المُزال يعود طلبه إلى "تمت المراجعة"، ولا ينشأ فريق ثانٍ
        $this->postJson($url, ['members' => [['application_id' => $mobile2, 'role_id' => $mobileRole]]])->assertOk();
        $this->assertSame(1, ProjectTeam::query()->where('project_opportunity_id', $project->id)->count());
        $this->assertSame('reviewed', ProjectApplication::find($mobile)->status);
        $this->assertSame('accepted', ProjectApplication::find($mobile2)->status);
        // لا يُرفض طالب ضمن الفريق قبل إزالته
        $this->patchJson("/api/projects/{$project->id}/applications/{$mobile2}", ['status' => 'rejected'])->assertStatus(409);
    }

    public function test_confirmation_requires_the_admin_assigned_leader_who_is_never_a_team_member(): void
    {
        $project = $this->project();
        $leader = $this->assignedLeader($project);
        $mobile = $this->applicant($project, 'lead-mobile@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);
        $backend = $this->applicant($project, 'lead-backend@example.test', ['Laravel' => 'passed', 'REST API' => 'passed']);

        $this->actingAs($leader, 'sanctum');
        Log::spy();
        $response = $this->postJson("/api/projects/{$project->id}/team", ['members' => [
            ['application_id' => $mobile, 'role_id' => $this->roleId($project, 'mobile_developer')],
            ['application_id' => $backend, 'role_id' => $this->roleId($project, 'backend_developer')],
        ]])->assertOk();
        $teamId = $response->json('data.id');
        // الفريق يعرض القائد المعيّن، والقائد ليس من الأعضاء
        $response->assertJsonPath('data.leader.student_profile_id', $leader->studentProfile->id);
        $this->assertSame(0, collect($response->json('data.members'))->where('is_leader', true)->count());

        // إلغاء التعيين يسحب الصلاحية فورًا ويمنع التأكيد
        $this->actingAs($this->platformAdmin(), 'sanctum')
            ->deleteJson("/api/admin/projects/{$project->id}/leader", ['reason' => 'إعادة توزيع القادة'])
            ->assertOk();
        $this->actingAs($leader, 'sanctum')->postJson("/api/teams/{$teamId}/confirm")->assertNotFound();

        $newLeader = $this->assignedLeader($project);
        $this->actingAs($newLeader, 'sanctum')->postJson("/api/teams/{$teamId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', ProjectTeam::STATUS_READY);
        // بعد التأكيد: التشكيلة مقفلة
        $this->postJson("/api/projects/{$project->id}/team", ['members' => []])->assertStatus(409)->assertJsonPath('reason', 'team_locked');
        $this->assertSame($newLeader->studentProfile->id, ProjectTeam::find($teamId)->leader_student_profile_id);

        // عضو في فريق مؤكد لا يسحب طلبه بنفسه
        $member = User::where('email', 'lead-backend@example.test')->first();
        $this->actingAs($member, 'sanctum')->postJson("/api/student/applications/{$backend}/withdraw")->assertStatus(409);
    }

    public function test_withdrawing_from_a_forming_team_removes_the_member_and_keeps_the_assigned_leader(): void
    {
        $project = $this->project();
        $leader = $this->assignedLeader($project);
        $application = $this->applicant($project, 'leaving@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);

        $this->actingAs($leader, 'sanctum');
        $teamId = $this->postJson("/api/projects/{$project->id}/team", ['members' => [
            ['application_id' => $application, 'role_id' => $this->roleId($project, 'mobile_developer')],
        ]])->json('data.id');

        $this->actingAs(User::where('email', 'leaving@example.test')->first(), 'sanctum')
            ->postJson("/api/student/applications/{$application}/withdraw")
            ->assertOk();

        $team = ProjectTeam::find($teamId);
        $this->assertSame($leader->studentProfile->id, $team->leader_student_profile_id);
        $this->assertSame(0, $team->members()->count());
    }

    public function test_students_can_record_weekly_availability(): void
    {
        $student = $this->student('hours@example.test', []);

        $this->actingAs($student, 'sanctum')->patchJson('/api/student/profile', ['weekly_availability_hours' => 15])
            ->assertOk()
            ->assertJsonPath('data.weekly_availability_hours', 15);
        $this->patchJson('/api/student/profile', ['weekly_availability_hours' => 0])->assertUnprocessable();
        $this->assertSame(15, StudentProfile::find($student->studentProfile->id)->weekly_availability_hours);
    }

    private function project(): ProjectOpportunity
    {
        $organization = $this->organization('org-'.uniqid().'@example.test');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'نظام المواصلات',
            'proposal' => ProjectProposalFixture::withStaffing(),
            'status' => ProjectRequestStatus::PROPOSAL_READY,
        ]);
        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/approve", ProjectProposalFixture::approval())
            ->assertOk()
            ->assertJsonPath('data.publication.status', ProjectOpportunity::STATUS_PENDING_REVIEW);
        $this->app['auth']->forgetGuards();

        // اعتماد المؤسسة يضع المشروع في قائمة مراجعة مدير المنصة؛ النشر للطلاب بقراره فقط
        return $this->adminApprove(ProjectOpportunity::query()->where('project_request_id', $projectRequest->id)->sole());
    }

    private function owner(ProjectOpportunity $project): User
    {
        return OrganizationProfile::find($project->organization_id)->user->load('organizationProfile');
    }

    private function roleId(ProjectOpportunity $project, string $category): int
    {
        return $project->activeVersion->roles()->where('category', $category)->value('id');
    }

    /** @return int معرّف طلب الانضمام */
    private function applicant(ProjectOpportunity $project, string $email, array $skills): int
    {
        $student = $this->student($email, $skills);

        return $this->actingAs($student, 'sanctum')->postJson($this->applyUrl($project))->assertCreated()->json('data.id');
    }

    private function applyUrl(ProjectOpportunity $project): string
    {
        return "/api/opportunities/{$project->id}/applications";
    }

    /** @param array<string, ?string> $skills اسم المهارة => null بلا تقييم، أو passed/failed، أو "passed:advanced" */
    private function student(string $email, array $skills, string $proficiency = 'intermediate'): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'SecurePass123', 'role' => 'student']);
        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'academic_major' => 'هندسة البرمجيات',
            'professional_specialization' => array_intersect(['Flutter', 'Dart'], array_keys($skills)) !== [] ? 'mobile_development' : 'backend_development',
            'onboarding_completed' => true,
        ]);
        foreach ($skills as $name => $result) {
            $profile->studentSkills()->create(['skill_id' => Skill::where('name', $name)->value('id'), 'proficiency' => $proficiency]);
            if ($result !== null) {
                [$status, $level] = array_pad(explode(':', $result), 2, 'beginner');
                $profile->skillAssessments()->create([
                    'skill_id' => Skill::where('name', $name)->value('id'),
                    'status' => $status,
                    'level' => $level,
                    'source' => 'admin_review',
                    'assessed_at' => now(),
                ]);
            }
        }

        return $user->load('studentProfile');
    }

    private function organization(string $email): User
    {
        $user = User::create(['name' => 'Organization', 'email' => $email, 'password' => 'SecurePass123', 'role' => 'organization']);
        OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Transit Co',
            'organization_type' => 'شركة',
            'industry' => 'نقل',
        ]);

        return $user->load('organizationProfile');
    }
}
