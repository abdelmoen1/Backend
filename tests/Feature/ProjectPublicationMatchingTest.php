<?php

namespace Tests\Feature;

use App\Contracts\ProjectProposalGenerator;
use App\Enums\ProjectRequestStatus;
use App\Models\IntakeSession;
use App\Models\OrganizationProfile;
use App\Models\ProjectOpportunity;
use App\Models\ProjectRequest;
use App\Models\Skill;
use App\Models\SkillAssessment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Projects\SpecializationRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakesAssessmentGemini;
use Tests\Support\ProjectProposalFixture;
use Tests\TestCase;

/**
 * اعتماد المقترح ونشره ومطابقة الطلاب: المهارة تُحتسب فقط إذا اختارها الطالب ولها تقييم ناجح،
 * والدور يطابق فقط إذا كان المسار المهني للطالب متوافقًا مع فئته.
 */
class ProjectPublicationMatchingTest extends TestCase
{
    use FakesAssessmentGemini;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (ProjectProposalFixture::STAFFING_SKILLS as $name) {
            Skill::create(['name' => $name]);
        }
    }

    public function test_an_organization_approves_its_own_proposal_and_it_is_published_once(): void
    {
        [$organization, $projectRequest] = $this->readyProject();

        $this->actingAs($organization, 'sanctum')
            ->postJson($this->url($projectRequest, 'approve'), ['confirm_requirements' => true])
            ->assertOk()
            ->assertJsonPath('data.status', ProjectRequestStatus::CONVERTED_TO_OPPORTUNITY)
            ->assertJsonPath('data.publication.status', ProjectOpportunity::STATUS_PUBLISHED)
            ->assertJsonPath('data.publication.version', 1)
            ->assertJsonPath('data.publication.has_pending_changes', false)
            ->assertJsonPath('data.publication.roles.0.title', 'مطوّر تطبيق الجوال')
            ->assertJsonPath('data.publication.roles.0.mandatory_skills', ['Flutter', 'Dart'])
            ->assertJsonPath('data.publication.roles.1.mandatory_skills', ['Laravel', 'REST API'])
            ->assertJsonPath('data.publication.roles.0.eligible_students', 0);

        $version = $projectRequest->proposalVersions()->sole();
        $this->assertSame($organization->id, $version->approved_by_user_id);
        $this->assertSame($projectRequest->fresh()->proposal, $version->proposal);
        $this->assertSame(2, $version->roles()->count());

        // نقرات مكررة أو إعادة محاولة عبر الشبكة لا تنشئ نسخًا أو فرصًا جديدة
        foreach (range(1, 2) as $_) {
            $this->postJson($this->url($projectRequest, 'approve'), ['confirm_requirements' => true])->assertOk();
        }
        $this->assertDatabaseCount('project_opportunities', 1);
        $this->assertDatabaseCount('project_proposal_versions', 1);
        $this->assertDatabaseCount('project_roles', 2);
    }

    public function test_approval_needs_confirmation_and_publishable_staffing(): void
    {
        [$organization, $projectRequest] = $this->readyProject(ProjectProposalFixture::valid());

        $this->actingAs($organization, 'sanctum')
            ->postJson($this->url($projectRequest, 'approve'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm_requirements');

        // مقترح قديم بلا أدوار منظمة لا يُنشر
        $this->postJson($this->url($projectRequest, 'approve'), ['confirm_requirements' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يتضمن المقترح أدوار الفريق والمهارات المطلوبة بشكل منظم. حدّث المقترح ليُحدد الأدوار قبل النشر.');

        // أدوار بمهارات خارج قائمة المنصة لا يمكن مطابقتها
        $proposal = ProjectProposalFixture::withStaffing();
        foreach ($proposal['staffing_requirements'] as &$role) {
            $role['mandatory_skills'] = ['Kotlin Multiplatform'];
        }
        unset($role);
        $projectRequest->update(['proposal' => $proposal]);
        $this->postJson($this->url($projectRequest, 'approve'), ['confirm_requirements' => true])
            ->assertUnprocessable();
        $this->getJson($this->url($projectRequest))
            ->assertJsonPath('data.staffing.matchable_roles', 0)
            ->assertJsonPath('data.staffing.unmapped_skills', ['Kotlin Multiplatform']);

        $this->assertDatabaseCount('project_opportunities', 0);
        $this->assertSame(ProjectRequestStatus::PROPOSAL_READY, $projectRequest->fresh()->status);
    }

    public function test_another_organization_cannot_approve_or_read_the_proposal(): void
    {
        [, $projectRequest] = $this->readyProject();
        $intruder = $this->organization('intruder-org@example.test');

        $this->actingAs($intruder, 'sanctum')
            ->postJson($this->url($projectRequest, 'approve'), ['confirm_requirements' => true])
            ->assertNotFound();
        $this->getJson($this->url($projectRequest))->assertNotFound();
        $this->assertDatabaseCount('project_opportunities', 0);
    }

    public function test_students_qualified_for_either_role_see_the_project_with_an_explanation(): void
    {
        $opportunity = $this->publishedOpportunity();
        $mobile = $this->student('mobile@example.test', ['Flutter' => 'passed', 'Dart' => 'passed', 'Git' => 'passed']);
        $backend = $this->student('backend@example.test', ['Laravel' => 'passed', 'REST API' => 'passed']);

        $this->actingAs($mobile, 'sanctum')
            ->getJson('/api/student/opportunities')
            ->assertOk()
            ->assertJsonCount(1, 'data.opportunities')
            ->assertJsonPath('data.opportunities.0.id', $opportunity->id)
            ->assertJsonPath('data.opportunities.0.match.roles', ['مطوّر تطبيق الجوال'])
            ->assertJsonPath('data.opportunities.0.match.verified_skills', ['Flutter', 'Dart', 'Git'])
            ->assertJsonPath('data.opportunities.0.roles.0.matched', true)
            ->assertJsonPath('data.opportunities.0.roles.1.matched', false);

        $this->actingAs($backend, 'sanctum')
            ->getJson('/api/student/opportunities')
            ->assertOk()
            ->assertJsonPath('data.opportunities.0.match.roles', ['مطوّر الخدمات الخلفية'])
            ->assertJsonPath('data.opportunities.0.match.verified_skills', ['Laravel', 'REST API']);

        $detail = $this->getJson("/api/student/opportunities/{$opportunity->id}")
            ->assertOk()
            ->assertJsonPath('data.scope.functional_scope.0.group', 'المواعيد')
            ->assertJsonPath('data.organization.name', 'Clinic');
        // بيانات المؤسسة الخاصة لا تصل للطالب
        $body = $detail->getContent();
        foreach (['estimated_cost', 'operational_cost', 'payment_schedule', 'budget_range', 'تقدير مبدئي'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }

        // المؤسسة ترى عدد المؤهلين لكل دور دون هوياتهم
        $owner = $opportunity->projectRequest->organization->user;
        $this->actingAs($owner, 'sanctum')
            ->getJson($this->url($opportunity->projectRequest))
            ->assertJsonPath('data.publication.roles.0.eligible_students', 1)
            ->assertJsonPath('data.publication.roles.1.eligible_students', 1)
            ->assertJsonMissingPath('data.publication.roles.0.students');
    }

    public function test_self_declared_failed_or_unselected_skills_do_not_qualify(): void
    {
        $opportunity = $this->publishedOpportunity();
        // مهارات مختارة بمستوى "متقدم" دون أي تقييم
        $selfDeclared = $this->student('self@example.test', ['Flutter' => null, 'Dart' => null], 'advanced');
        $failed = $this->student('failed@example.test', ['Laravel' => 'failed', 'REST API' => 'passed']);
        $partial = $this->student('partial@example.test', ['Flutter' => 'passed']);
        // تقييم ناجح لمهارة لم يخترها الطالب في ملفه لا يُحتسب
        $unselected = $this->student('unselected@example.test', ['Laravel' => 'passed']);
        $this->assess($unselected->studentProfile, 'REST API', SkillAssessment::STATUS_PASSED);

        foreach ([$selfDeclared, $failed, $partial, $unselected] as $student) {
            $this->actingAs($student, 'sanctum')
                ->getJson('/api/student/opportunities')
                ->assertOk()
                ->assertJsonCount(0, 'data.opportunities');
            $this->getJson("/api/student/opportunities/{$opportunity->id}")
                ->assertNotFound()
                ->assertJsonMissingPath('data');
        }
    }

    public function test_unpublished_suspended_and_withdrawn_projects_are_hidden_even_by_direct_url(): void
    {
        $opportunity = $this->publishedOpportunity();
        $student = $this->student('mobile2@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);
        [, $draft] = $this->readyProject(null, 'draft-org@example.test');

        $this->actingAs($student, 'sanctum')->getJson("/api/student/opportunities/{$opportunity->id}")->assertOk();

        foreach ([ProjectOpportunity::STATUS_SUSPENDED, ProjectOpportunity::STATUS_WITHDRAWN] as $status) {
            $opportunity->update(['status' => $status]);
            $this->getJson('/api/student/opportunities')->assertJsonCount(0, 'data.opportunities');
            $this->getJson("/api/student/opportunities/{$opportunity->id}")->assertNotFound();
        }

        // مقترح لم يُعتمد لا يملك فرصة أصلًا، ومعرّف غير موجود يعامل بنفس الطريقة
        $this->getJson('/api/student/opportunities/'.($opportunity->id + 100))->assertNotFound();
        $this->assertNull($draft->opportunity);

        // التعليق الإداري لا يُلغى بإعادة اعتماد المؤسسة
        $opportunity->update(['status' => ProjectOpportunity::STATUS_SUSPENDED]);
        $this->actingAs($opportunity->projectRequest->organization->user, 'sanctum')
            ->postJson($this->url($opportunity->projectRequest, 'approve'), ['confirm_requirements' => true])
            ->assertOk()
            ->assertJsonPath('data.publication.status', ProjectOpportunity::STATUS_SUSPENDED);
    }

    public function test_access_is_limited_to_the_right_roles(): void
    {
        $opportunity = $this->publishedOpportunity();
        $student = $this->student('student-access@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);
        $incomplete = $this->student('incomplete@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);
        $incomplete->studentProfile->update(['onboarding_completed' => false]);

        $this->actingAs($opportunity->projectRequest->organization->user, 'sanctum')
            ->getJson('/api/student/opportunities')
            ->assertForbidden();
        $this->actingAs($incomplete, 'sanctum')
            ->getJson('/api/student/opportunities')
            ->assertForbidden();
        $this->actingAs($student, 'sanctum')
            ->getJson($this->url($opportunity->projectRequest))
            ->assertForbidden();
        $this->postJson($this->url($opportunity->projectRequest, 'approve'), ['confirm_requirements' => true])
            ->assertForbidden();
    }

    public function test_editing_a_published_proposal_keeps_the_approved_version_until_reconfirmed(): void
    {
        $opportunity = $this->publishedOpportunity();
        $projectRequest = $opportunity->projectRequest;
        $owner = $projectRequest->organization->user;
        $backend = $this->student('backend2@example.test', ['Laravel' => 'passed', 'REST API' => 'passed']);
        $mobile = $this->student('mobile3@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);

        $revised = ProjectProposalFixture::withStaffing();
        $revised['project_overview']['project_name'] = 'نظام المواصلات - الإصدار المعدل';
        // المؤسسة لم تعد تحتاج تطبيق جوال في النسخة الأولى
        $revised['staffing_requirements'] = [$revised['staffing_requirements'][1]];
        $generator = \Mockery::mock(ProjectProposalGenerator::class);
        $generator->shouldReceive('generate')->once()->andReturn($revised);
        $this->app->instance(ProjectProposalGenerator::class, $generator);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($projectRequest, 'revision'), ['note' => 'لا نحتاج تطبيق الجوال في النسخة الأولى.'])
            ->assertOk();
        $this->assertSame(
            'revision_2',
            $projectRequest->intakeSession->responses()->reorder()->latest('sequence')->value('question_id'),
            'Edits continue the same discovery conversation.'
        );
        $this->postJson($this->url($projectRequest, 'analyze'))
            ->assertOk()
            ->assertJsonPath('data.status', ProjectRequestStatus::PROPOSAL_READY)
            ->assertJsonPath('data.publication.version', 1)
            ->assertJsonPath('data.publication.has_pending_changes', true);

        // قبل إعادة الاعتماد يبقى النطاق المنشور كما هو
        $this->actingAs($mobile, 'sanctum')->getJson('/api/student/opportunities')->assertJsonCount(1, 'data.opportunities');

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($projectRequest, 'approve'), ['confirm_requirements' => true])
            ->assertOk()
            ->assertJsonPath('data.publication.version', 2)
            ->assertJsonPath('data.publication.has_pending_changes', false);

        $this->assertSame(2, $projectRequest->proposalVersions()->count());
        $this->assertSame('نظام المواصلات - الإصدار المعدل', $opportunity->fresh()->title);
        // النسخة المعتمدة الأولى محفوظة كما هي
        $this->assertSame(
            ProjectProposalFixture::withStaffing()['project_overview']['project_name'],
            $projectRequest->proposalVersions()->where('version', 1)->sole()->proposal['project_overview']['project_name']
        );
        $this->assertDatabaseCount('project_opportunities', 1);

        $this->actingAs($mobile, 'sanctum')->getJson('/api/student/opportunities')->assertJsonCount(0, 'data.opportunities');
        $this->actingAs($backend, 'sanctum')->getJson('/api/student/opportunities')
            ->assertJsonPath('data.opportunities.0.title', 'نظام المواصلات - الإصدار المعدل');
    }

    public function test_a_published_request_cannot_be_deleted_and_student_skill_management_still_works(): void
    {
        $opportunity = $this->publishedOpportunity();
        $owner = $opportunity->projectRequest->organization->user;
        $this->actingAs($owner, 'sanctum')
            ->deleteJson($this->url($opportunity->projectRequest))
            ->assertUnprocessable();

        // إضافة مهارة من الطالب لا تنشئ أي تحقق
        $student = $this->student('skills@example.test', []);
        $this->actingAs($student, 'sanctum')
            ->postJson('/api/student/skills', ['skill_id' => Skill::where('name', 'Flutter')->value('id'), 'proficiency' => 'expert'])
            ->assertCreated();
        $this->assertDatabaseCount('skill_assessments', 0);
        $this->getJson('/api/student/opportunities')->assertJsonPath('data.verified_skills', []);
    }

    public function test_admin_commands_record_assessments_and_moderate_opportunities(): void
    {
        $opportunity = $this->publishedOpportunity();
        $student = $this->student('cli@example.test', ['Flutter' => null, 'Dart' => null]);

        $this->artisan('skills:record-assessment', ['email' => 'cli@example.test', 'skill' => 'flutter', '--score' => 85])->assertSuccessful();
        $this->artisan('skills:record-assessment', ['email' => 'cli@example.test', 'skill' => 'Dart'])->assertSuccessful();
        $this->artisan('skills:record-assessment', ['email' => 'cli@example.test', 'skill' => 'Unknown skill'])->assertFailed();
        $this->actingAs($student, 'sanctum')->getJson('/api/student/opportunities')->assertJsonCount(1, 'data.opportunities');

        $this->artisan('opportunities:moderate', ['opportunity' => $opportunity->id, 'action' => 'suspend', '--note' => 'مراجعة المحتوى'])->assertSuccessful();
        $this->getJson("/api/student/opportunities/{$opportunity->id}")->assertNotFound();
        $this->artisan('opportunities:moderate', ['opportunity' => $opportunity->id, 'action' => 'publish'])->assertSuccessful();
        $this->getJson("/api/student/opportunities/{$opportunity->id}")->assertOk();
    }

    public function test_professional_specialization_must_be_compatible_with_a_required_role(): void
    {
        $opportunity = $this->publishedOpportunity();
        $mobile = $this->student('mobile-dev@example.test', ['Flutter' => 'passed', 'Dart' => 'passed'], specialization: 'mobile_development');
        // التخصص الجامعي لم يعد يحدد المطابقة
        $mobile->studentProfile->update(['academic_major' => 'إدارة الأعمال']);
        // المهارات نفسها متحقق منها، لكن المسار المهني لا يتوافق مع أي دور في المشروع
        $designer = $this->student('designer@example.test', ['Flutter' => 'passed', 'Dart' => 'passed'], specialization: 'ui_ux_design');

        $this->actingAs($mobile, 'sanctum')
            ->getJson('/api/student/opportunities')
            ->assertJsonCount(1, 'data.opportunities')
            ->assertJsonPath('data.specialization.status', SpecializationRoles::COMPATIBLE)
            ->assertJsonPath('data.specialization.key', 'mobile_development');

        $this->actingAs($designer, 'sanctum')
            ->getJson('/api/student/opportunities')
            ->assertOk()
            ->assertJsonCount(0, 'data.opportunities')
            ->assertJsonPath('data.verified_skills', ['Flutter', 'Dart'])
            ->assertJsonPath('data.specialization.key', 'ui_ux_design');
        $this->getJson("/api/student/opportunities/{$opportunity->id}")->assertNotFound();

        // عدد المؤهلين الذي تراه المؤسسة يطبق القاعدة نفسها
        $this->actingAs($opportunity->projectRequest->organization->user, 'sanctum')
            ->getJson($this->url($opportunity->projectRequest))
            ->assertJsonPath('data.publication.roles.0.eligible_students', 1);
    }

    public function test_unrecognized_or_missing_specializations_are_explained_and_never_bypass_skills(): void
    {
        $opportunity = $this->publishedOpportunity();
        // قيمة قديمة ليست من قائمة المسارات (مثل تخصص جامعي)
        $legacy = $this->student('legacy@example.test', ['Flutter' => 'passed', 'Dart' => 'passed'], specialization: 'علوم الحاسوب');
        $missing = $this->student('no-spec@example.test', ['Flutter' => 'passed', 'Dart' => 'passed'], specialization: null);
        $unverified = $this->student('unverified@example.test', ['Flutter' => null, 'Dart' => null], specialization: 'mobile_development');

        foreach ([[$legacy, SpecializationRoles::UNRECOGNIZED], [$missing, SpecializationRoles::MISSING]] as [$student, $status]) {
            $this->actingAs($student, 'sanctum')
                ->getJson('/api/student/opportunities')
                ->assertJsonCount(0, 'data.opportunities')
                ->assertJsonPath('data.specialization.status', $status);
            $this->getJson("/api/student/opportunities/{$opportunity->id}")->assertNotFound();
        }

        // المسار المتوافق لا يعوّض غياب التقييم
        $this->actingAs($unverified, 'sanctum')
            ->getJson('/api/student/opportunities')
            ->assertJsonCount(0, 'data.opportunities')
            ->assertJsonPath('data.specialization.status', SpecializationRoles::COMPATIBLE);
        $this->getJson("/api/student/opportunities/{$opportunity->id}")->assertNotFound();
    }

    public function test_students_see_which_requirements_they_meet_for_each_role(): void
    {
        $opportunity = $this->publishedOpportunity();
        $backend = $this->student('explain@example.test', ['Laravel' => 'passed', 'REST API' => 'passed', 'Flutter' => 'passed'], specialization: 'backend_development');

        $this->actingAs($backend, 'sanctum')
            ->getJson("/api/student/opportunities/{$opportunity->id}")
            ->assertOk()
            ->assertJsonPath('data.roles.1.matched', true)
            ->assertJsonPath('data.roles.1.missing_skills', [])
            ->assertJsonPath('data.roles.0.matched', false)
            ->assertJsonPath('data.roles.0.specialization_compatible', false)
            ->assertJsonPath('data.roles.0.missing_skills', ['Dart'])
            ->assertJsonMissingPath('data.match.score');
    }

    public function test_written_assessment_results_drive_matching_per_skill(): void
    {
        $this->configureGemini();
        $opportunity = $this->publishedOpportunity();
        $flutter = Skill::where('name', 'Flutter')->value('id');
        $dart = Skill::where('name', 'Dart')->value('id');
        $mobile = $this->student('journey@example.test', ['Flutter' => null, 'Dart' => null], specialization: 'mobile_development');

        $this->actingAs($mobile, 'sanctum')->getJson('/api/student/opportunities')->assertJsonCount(0, 'data.opportunities');
        $this->fakeGemini($this->generatedAssessment([$flutter, $dart]));
        $this->completeSession([$flutter, $dart], ['FULL', 'FULL', 'FULL', 'FULL']);

        $this->getJson('/api/student/opportunities')
            ->assertJsonCount(1, 'data.opportunities')
            ->assertJsonPath('data.opportunities.0.id', $opportunity->id)
            ->assertJsonPath('data.opportunities.0.match.roles', ['مطوّر تطبيق الجوال'])
            ->assertJsonPath('data.opportunities.0.match.verified_skills', ['Flutter', 'Dart']);
        $this->getJson("/api/student/opportunities/{$opportunity->id}")->assertOk();

        // إزالة المهارة من الملف توقف احتسابها فورًا
        $this->deleteJson("/api/student/skills/{$dart}")->assertOk();
        $this->getJson("/api/student/opportunities/{$opportunity->id}")->assertNotFound();

        // نجاح Laravel لا يؤهل لدور يتطلب REST API أيضًا وقد رسب فيها الطالب
        $laravel = Skill::where('name', 'Laravel')->value('id');
        $rest = Skill::where('name', 'REST API')->value('id');
        $backend = $this->student('backend-journey@example.test', [], specialization: 'backend_development');
        $this->actingAs($backend, 'sanctum');
        $this->fakeGemini($this->generatedAssessment([$laravel, $rest]));
        $this->completeSession([$laravel, $rest], ['FULL', 'FULL', 'weak', 'weak'])
            ->assertJsonPath('data.results.0.status', SkillAssessment::STATUS_PASSED)
            ->assertJsonPath('data.results.1.status', SkillAssessment::STATUS_FAILED);
        $this->getJson('/api/student/opportunities')
            ->assertJsonPath('data.verified_skills', ['Laravel'])
            ->assertJsonCount(0, 'data.opportunities');
        $this->getJson("/api/student/opportunities/{$opportunity->id}")->assertNotFound();
    }

    /** يبدأ جلسة ويجيب بالترتيب ثم يصحح، كما تفعل الواجهة. */
    private function completeSession(array $skillIds, array $answers): TestResponse
    {
        $sessionId = $this->postJson('/api/student/assessment-sessions', ['skill_ids' => $skillIds])->assertCreated()->json('data.id');
        foreach ($answers as $answer) {
            $question = $this->getJson("/api/student/assessment-sessions/{$sessionId}")->json('data.current_question.id');
            $this->postJson("/api/student/assessment-sessions/{$sessionId}/answers", ['question_id' => $question, 'answer' => $answer])->assertOk();
        }

        return $this->postJson("/api/student/assessment-sessions/{$sessionId}/finalize")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    private function publishedOpportunity(): ProjectOpportunity
    {
        [$organization, $projectRequest] = $this->readyProject(null, 'publisher-'.uniqid().'@example.test');
        $this->actingAs($organization, 'sanctum')
            ->postJson($this->url($projectRequest, 'approve'), ['confirm_requirements' => true])
            ->assertOk();

        return ProjectOpportunity::query()->where('project_request_id', $projectRequest->id)->sole();
    }

    /** @return array{0: User, 1: ProjectRequest} */
    private function readyProject(?array $proposal = null, string $email = 'owner-org@example.test'): array
    {
        $organization = $this->organization($email);
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'نظام المواصلات',
            'problem_description' => 'لا يعرف الركاب المسارات والأجرة.',
            'proposal' => $proposal ?? ProjectProposalFixture::withStaffing(),
            'status' => ProjectRequestStatus::PROPOSAL_READY,
        ]);
        IntakeSession::create([
            'project_request_id' => $projectRequest->id,
            'status' => IntakeSession::STATUS_COMPLETED,
            'current_state' => [],
        ])->responses()->create([
            'sequence' => 1,
            'question_id' => 'q1',
            'question_text' => 'ما المشكلة؟',
            'question_type' => 'long_text',
            'options' => [],
            'selected_answer' => 'لا يعرف الركاب المسارات.',
            'answered_at' => now(),
        ]);

        return [$organization, $projectRequest];
    }

    /**
     * @param  array<string, ?string>  $skills  اسم المهارة => نتيجة التقييم (null بلا تقييم)
     * @param  ?string  $specialization  'auto': مسار الجوال لمن لديه Flutter أو Dart، وإلا الخلفية
     */
    private function student(string $email, array $skills, string $proficiency = 'intermediate', ?string $specialization = 'auto'): User
    {
        $user = User::create(['name' => 'Student', 'email' => $email, 'password' => 'SecurePass123', 'role' => 'student']);
        $profile = StudentProfile::create([
            'user_id' => $user->id,
            'academic_major' => 'هندسة البرمجيات',
            'professional_specialization' => $specialization === 'auto'
                ? (array_intersect(['Flutter', 'Dart'], array_keys($skills)) !== [] ? 'mobile_development' : 'backend_development')
                : $specialization,
            'onboarding_completed' => true,
        ]);
        foreach ($skills as $name => $result) {
            $profile->studentSkills()->create(['skill_id' => Skill::where('name', $name)->value('id'), 'proficiency' => $proficiency]);
            if ($result !== null) {
                $this->assess($profile, $name, $result);
            }
        }

        return $user->load('studentProfile');
    }

    private function assess(StudentProfile $profile, string $skill, string $status): void
    {
        $profile->skillAssessments()->create([
            'skill_id' => Skill::where('name', $skill)->value('id'),
            'status' => $status,
            'source' => 'platform_test',
            'assessed_at' => now(),
        ]);
    }

    private function organization(string $email): User
    {
        $user = User::create(['name' => 'Organization', 'email' => $email, 'password' => 'SecurePass123', 'role' => 'organization']);
        OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'Clinic',
            'organization_type' => 'عيادة',
            'industry' => 'صحة',
        ]);

        return $user->load('organizationProfile');
    }

    private function url(ProjectRequest $projectRequest, string $action = ''): string
    {
        return rtrim("/api/organization/project-requests/{$projectRequest->id}/{$action}", '/');
    }
}
