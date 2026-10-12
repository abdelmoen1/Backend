<?php

namespace Tests\Feature;

use App\Contracts\ProjectProposalGenerator;
use App\Enums\ProjectRequestStatus;
use App\Models\IntakeSession;
use App\Models\OrganizationProfile;
use App\Models\ProjectOpportunity;
use App\Models\ProjectRequest;
use App\Models\ProjectRoleSkill;
use App\Models\Skill;
use App\Models\SkillAssessment;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Projects\OpportunityMatcher;
use App\Services\Projects\SpecializationRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakesAssessmentGemini;
use Tests\Support\ProjectProposalFixture;
use Tests\TestCase;

/**
 * اعتماد المقترح ونشره ومطابقة الطلاب. كل فرصة منشورة تظهر لكل طالب يحق له التصفح، مع حالة أهلية
 * يحسبها الخادم لكل دور: المسار المهني متوافق، وكل مهارة إلزامية مختارة ومثبتة بمستوى لا يقل عن المستوى
 * الذي أكدته المؤسسة.
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

    public function test_an_organization_approves_its_own_proposal_with_confirmed_levels_and_it_is_published_once(): void
    {
        [$organization, $projectRequest] = $this->readyProject();

        // توصية الذكاء الاصطناعي ظاهرة في المسودة كتوصية فقط
        $this->actingAs($organization, 'sanctum')
            ->getJson($this->url($projectRequest))
            ->assertJsonPath('data.staffing.roles.0.mandatory_skills.0.name', 'Flutter')
            ->assertJsonPath('data.staffing.roles.0.mandatory_skills.0.recommended_level', 'intermediate')
            ->assertJsonPath('data.staffing.roles.1.mandatory_skills.1.recommended_level', null);

        $this->postJson($this->url($projectRequest, 'approve'), ProjectProposalFixture::approval(levels: ['Flutter' => 'advanced', 'Laravel' => 'intermediate']))
            ->assertOk()
            ->assertJsonPath('data.status', ProjectRequestStatus::CONVERTED_TO_OPPORTUNITY)
            ->assertJsonPath('data.publication.status', ProjectOpportunity::STATUS_PUBLISHED)
            ->assertJsonPath('data.publication.version', 1)
            ->assertJsonPath('data.publication.has_pending_changes', false)
            ->assertJsonPath('data.publication.levels_confirmed', true)
            ->assertJsonPath('data.publication.roles.0.title', 'مطوّر تطبيق الجوال')
            ->assertJsonPath('data.publication.roles.0.mandatory_skills', ['Flutter', 'Dart'])
            ->assertJsonPath('data.publication.roles.0.mandatory_requirements.0', ['name' => 'Flutter', 'min_level' => 'advanced', 'min_level_label' => 'متقدم'])
            ->assertJsonPath('data.publication.roles.0.mandatory_requirements.1.min_level', 'beginner')
            ->assertJsonPath('data.publication.roles.1.mandatory_requirements.0.min_level', 'intermediate')
            ->assertJsonPath('data.publication.roles.0.eligible_students', 0);

        $version = $projectRequest->proposalVersions()->sole();
        $this->assertSame($organization->id, $version->approved_by_user_id);
        $this->assertSame($projectRequest->fresh()->proposal, $version->proposal);
        $this->assertSame(2, $version->roles()->count());
        // المستوى المؤكد محفوظ بجانب التوصية الأصلية
        $this->assertSame('advanced', $version->staffing['roles'][0]['mandatory_skills'][0]['min_level']);
        $this->assertSame('intermediate', $version->staffing['roles'][0]['mandatory_skills'][0]['recommended_level']);
        $this->assertNull(ProjectRoleSkill::query()->where('importance', ProjectRoleSkill::DESIRABLE)->value('min_level'));

        // نقرات مكررة أو إعادة محاولة عبر الشبكة لا تنشئ نسخًا أو فرصًا جديدة
        foreach (range(1, 2) as $_) {
            $this->postJson($this->url($projectRequest, 'approve'), ProjectProposalFixture::approval(levels: ['Flutter' => 'advanced', 'Laravel' => 'intermediate']))->assertOk();
        }
        $this->assertDatabaseCount('project_opportunities', 1);
        $this->assertDatabaseCount('project_proposal_versions', 1);
        $this->assertDatabaseCount('project_roles', 2);

        // تغيير المستويات وحدها ينشئ نسخة معتمدة جديدة ولا يعدّل النسخة السابقة
        $this->postJson($this->url($projectRequest, 'approve'), ProjectProposalFixture::approval(levels: ['Flutter' => 'intermediate', 'Laravel' => 'intermediate']))
            ->assertOk()
            ->assertJsonPath('data.publication.version', 2)
            ->assertJsonPath('data.publication.has_pending_changes', false)
            ->assertJsonPath('data.publication.roles.0.mandatory_requirements.0.min_level', 'intermediate');
        $this->assertSame('advanced', $version->fresh()->roles()->first()->skills()->where('importance', 'mandatory')->first()->min_level);
    }

    public function test_approval_needs_confirmation_of_every_mandatory_level_and_publishable_staffing(): void
    {
        [$organization, $projectRequest] = $this->readyProject();
        $this->actingAs($organization, 'sanctum');
        $complete = ProjectProposalFixture::approval();

        $this->postJson($this->url($projectRequest, 'approve'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirm_requirements', 'role_skill_levels']);
        // توصيات الذكاء الاصطناعي لا تُعتمد ضمنيًا: مهارة إلزامية دون مستوى مؤكد ترفض الاعتماد
        $this->postJson($this->url($projectRequest, 'approve'), ['confirm_requirements' => true, 'role_skill_levels' => array_slice($complete['role_skill_levels'], 1)])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'حدد المستوى الأدنى المطلوب لكل مهارة إلزامية قبل الاعتماد: Flutter.');
        $this->postJson($this->url($projectRequest, 'approve'), ['role_skill_levels' => [...$complete['role_skill_levels'], ['role' => 0, 'skill_id' => Skill::where('name', 'Git')->value('id'), 'level' => 'beginner']]] + $complete)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'تغيّرت أدوار المقترح أو مهاراته منذ تحميل الصفحة. حدّث الصفحة وأكد مستويات المهارات من جديد.');
        $invalid = $complete;
        $invalid['role_skill_levels'][0]['level'] = 'expert';
        $this->postJson($this->url($projectRequest, 'approve'), $invalid)->assertJsonValidationErrors('role_skill_levels.0.level');
        $this->assertDatabaseCount('project_opportunities', 0);

        // مقترح قديم بلا أدوار منظمة لا يُنشر
        $projectRequest->update(['proposal' => ProjectProposalFixture::valid()]);
        $this->postJson($this->url($projectRequest, 'approve'), ['confirm_requirements' => true, 'role_skill_levels' => []])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'لا يتضمن المقترح أدوار الفريق والمهارات المطلوبة بشكل منظم. حدّث المقترح ليُحدد الأدوار قبل النشر.');

        // أدوار بمهارات خارج قائمة المنصة لا يمكن مطابقتها
        $proposal = ProjectProposalFixture::withStaffing();
        foreach ($proposal['staffing_requirements'] as &$role) {
            $role['mandatory_skills'] = ['Kotlin Multiplatform'];
        }
        unset($role);
        $projectRequest->update(['proposal' => $proposal]);
        $this->postJson($this->url($projectRequest, 'approve'), ['confirm_requirements' => true, 'role_skill_levels' => []])
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
            ->postJson($this->url($projectRequest, 'approve'), ProjectProposalFixture::approval())
            ->assertNotFound();
        $this->getJson($this->url($projectRequest))->assertNotFound();
        $this->assertDatabaseCount('project_opportunities', 0);
    }

    public function test_every_published_opportunity_is_visible_with_a_backend_computed_eligibility(): void
    {
        $opportunity = $this->publishedOpportunity(['Laravel' => 'intermediate']);
        $untested = $this->student('untested@example.test', ['Laravel' => null, 'REST API' => null], specialization: 'backend_development');
        $beginner = $this->student('beginner@example.test', ['Laravel' => 'passed', 'REST API' => 'passed'], specialization: 'backend_development');
        $qualified = $this->student('qualified@example.test', ['Laravel' => 'passed:intermediate', 'REST API' => 'passed'], specialization: 'backend_development');

        // لم يختبر المهارات بعد: الفرصة ظاهرة مع السبب والإجراء
        $this->actingAs($untested, 'sanctum')
            ->getJson('/api/student/opportunities')
            ->assertOk()
            ->assertJsonCount(1, 'data.opportunities')
            ->assertJsonPath('data.opportunities.0.id', $opportunity->id)
            ->assertJsonPath('data.opportunities.0.eligibility.status', OpportunityMatcher::ASSESSMENT_REQUIRED)
            ->assertJsonPath('data.opportunities.0.eligibility.role_title', 'مطوّر الخدمات الخلفية')
            ->assertJsonPath('data.opportunities.0.eligibility.message', 'لم تختبر مهارة Laravel بعد. ابدأ اختبار المهارة لمعرفة مدى توافقك مع هذه الفرصة.')
            ->assertJsonPath('data.opportunities.0.eligibility.action.type', 'assessment')
            ->assertJsonPath('data.opportunities.0.eligibility.action.skill_id', Skill::where('name', 'Laravel')->value('id'))
            ->assertJsonPath('data.opportunities.0.eligibility.action.level', 'intermediate')
            ->assertJsonPath('data.opportunities.0.roles.1.eligibility.reasons.1.skill.name', 'REST API')
            ->assertJsonPath('data.opportunities.0.roles.1.eligibility.reasons.1.code', OpportunityMatcher::ASSESSMENT_REQUIRED)
            ->assertJsonPath('data.opportunities.0.roles.1.matched', false)
            ->assertJsonPath('data.opportunities.0.match.roles', []);
        $this->getJson("/api/student/opportunities/{$opportunity->id}")
            ->assertOk()
            ->assertJsonPath('data.eligibility.status', OpportunityMatcher::ASSESSMENT_REQUIRED);

        // مبتدئ مثبت لا يستوفي دورًا يتطلب مستوى متوسطًا
        $this->actingAs($beginner, 'sanctum')
            ->getJson('/api/student/opportunities')
            ->assertJsonPath('data.opportunities.0.eligibility.status', OpportunityMatcher::LEVEL_INSUFFICIENT)
            ->assertJsonPath('data.opportunities.0.eligibility.message', 'مستواك المثبت في Laravel هو مبتدئ، بينما يتطلب هذا الدور مستوى متوسطًا.')
            ->assertJsonPath('data.opportunities.0.roles.1.eligibility.reasons.0.required_level', 'intermediate')
            ->assertJsonPath('data.opportunities.0.roles.1.eligibility.reasons.0.verified_level', 'beginner')
            ->assertJsonPath('data.opportunities.0.roles.1.requirements.0', [
                'id' => Skill::where('name', 'Laravel')->value('id'),
                'name' => 'Laravel',
                'min_level' => 'intermediate',
                'min_level_label' => 'متوسط',
                'level_confirmed' => true,
                'selected' => true,
                'verified_level' => 'beginner',
                'verified_level_label' => 'مبتدئ',
                'met' => false,
            ])
            ->assertJsonPath('data.opportunities.0.roles.1.requirements.1.met', true)
            ->assertJsonPath('data.opportunities.0.roles.1.missing_skills', ['Laravel'])
            ->assertJsonPath('data.verified_levels.0.level', 'beginner');

        // يستوفي كل المستويات
        $this->actingAs($qualified, 'sanctum')
            ->getJson('/api/student/opportunities')
            ->assertJsonPath('data.opportunities.0.eligibility.status', OpportunityMatcher::ELIGIBLE)
            ->assertJsonPath('data.opportunities.0.eligibility.message', 'أنت تستوفي متطلبات المهارات والمستوى لهذا الدور.')
            ->assertJsonPath('data.opportunities.0.eligibility.eligible_roles', ['مطوّر الخدمات الخلفية'])
            ->assertJsonPath('data.opportunities.0.eligibility.action', null)
            ->assertJsonPath('data.opportunities.0.roles.1.matched', true)
            ->assertJsonPath('data.opportunities.0.roles.0.eligibility.status', OpportunityMatcher::SPECIALIZATION_MISMATCH)
            ->assertJsonPath('data.opportunities.0.match.roles', ['مطوّر الخدمات الخلفية'])
            ->assertJsonPath('data.opportunities.0.match.verified_skills', ['Laravel', 'REST API']);

        // المؤسسة ترى عدد المستوفين لكل دور بالقواعد نفسها، دون هويات
        $this->actingAs($opportunity->projectRequest->organization->user, 'sanctum')
            ->getJson($this->url($opportunity->projectRequest))
            ->assertJsonPath('data.publication.roles.1.eligible_students', 1)
            ->assertJsonMissingPath('data.publication.roles.1.students');
    }

    public function test_ineligible_students_can_open_published_details_without_private_data(): void
    {
        $opportunity = $this->publishedOpportunity();
        $student = $this->student('details@example.test', [], specialization: 'mobile_development');

        $detail = $this->actingAs($student, 'sanctum')
            ->getJson("/api/student/opportunities/{$opportunity->id}")
            ->assertOk()
            ->assertJsonPath('data.eligibility.status', OpportunityMatcher::SKILL_NOT_SELECTED)
            ->assertJsonPath('data.roles.0.eligibility.reasons.0.message', 'مهارة Flutter ليست ضمن مهارات ملفك بعد. ابدأ اختبار المهارة لإضافتها ومعرفة مدى توافقك مع هذه الفرصة.')
            ->assertJsonPath('data.roles.0.eligibility.reasons.0.action.type', 'assessment')
            ->assertJsonPath('data.scope.functional_scope.0.group', 'المواعيد')
            ->assertJsonPath('data.organization.name', 'Clinic');
        $body = $detail->getContent();
        foreach (['estimated_cost', 'operational_cost', 'payment_schedule', 'budget_range', 'تقدير مبدئي', 'SECRET-BUDGET', 'rationale', 'revision_note', 'key_requirements', 'needs_confirmation', 'applied', 'application'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }
    }

    public function test_self_declared_failed_unselected_and_partial_skills_are_explained_but_not_eligible(): void
    {
        $opportunity = $this->publishedOpportunity();
        // مهارات مختارة بمستوى "متقدم" معلن ذاتيًا دون أي تقييم
        $selfDeclared = $this->student('self@example.test', ['Flutter' => null, 'Dart' => null], 'advanced');
        $failed = $this->student('failed@example.test', ['Laravel' => 'failed', 'REST API' => 'passed']);
        $partial = $this->student('partial@example.test', ['Flutter' => 'passed']);
        // تقييم ناجح لمهارة لم يخترها الطالب في ملفه لا يُحتسب
        $unselected = $this->student('unselected@example.test', ['Laravel' => 'passed']);
        $this->assess($unselected->studentProfile, 'REST API', 'passed');

        $expectations = [
            [$selfDeclared, OpportunityMatcher::ASSESSMENT_REQUIRED, 'لم تختبر مهارة Flutter بعد. ابدأ اختبار المهارة لمعرفة مدى توافقك مع هذه الفرصة.'],
            [$failed, OpportunityMatcher::ASSESSMENT_REQUIRED, 'لم تجتز اختبار مهارة Laravel بعد. أعد اختبار المهارة لمعرفة مدى توافقك مع هذه الفرصة.'],
            [$partial, OpportunityMatcher::SKILL_NOT_SELECTED, 'مهارة Dart ليست ضمن مهارات ملفك بعد. ابدأ اختبار المهارة لإضافتها ومعرفة مدى توافقك مع هذه الفرصة.'],
            [$unselected, OpportunityMatcher::SKILL_NOT_SELECTED, 'مهارة REST API مثبتة لديك لكنها ليست ضمن مهارات ملفك. أضفها إلى ملفك لتُحتسب في هذا الدور.'],
        ];
        foreach ($expectations as [$student, $status, $message]) {
            $this->actingAs($student, 'sanctum')
                ->getJson('/api/student/opportunities')
                ->assertOk()
                ->assertJsonCount(1, 'data.opportunities')
                ->assertJsonPath('data.opportunities.0.eligibility.status', $status)
                ->assertJsonPath('data.opportunities.0.eligibility.message', $message)
                ->assertJsonPath('data.opportunities.0.match.roles', []);
            $this->getJson("/api/student/opportunities/{$opportunity->id}")
                ->assertOk()
                ->assertJsonPath('data.eligibility.status', $status);
        }
        $this->actingAs($unselected, 'sanctum')->getJson('/api/student/opportunities')
            ->assertJsonPath('data.opportunities.0.eligibility.action.type', 'profile');
    }

    public function test_unpublished_suspended_and_withdrawn_projects_stay_inaccessible(): void
    {
        $opportunity = $this->publishedOpportunity();
        $student = $this->student('mobile2@example.test', ['Flutter' => 'passed', 'Dart' => 'passed']);
        $untested = $this->student('untested2@example.test', []);
        [, $draft] = $this->readyProject(null, 'draft-org@example.test');

        $this->actingAs($student, 'sanctum')->getJson("/api/student/opportunities/{$opportunity->id}")->assertOk();

        foreach ([ProjectOpportunity::STATUS_SUSPENDED, ProjectOpportunity::STATUS_WITHDRAWN] as $status) {
            $opportunity->update(['status' => $status]);
            foreach ([$student, $untested] as $viewer) {
                $this->actingAs($viewer, 'sanctum')->getJson('/api/student/opportunities')->assertJsonCount(0, 'data.opportunities');
                $this->getJson("/api/student/opportunities/{$opportunity->id}")->assertNotFound()->assertJsonMissingPath('data');
            }
        }

        // مقترح لم يُعتمد لا يملك فرصة أصلًا، ومعرّف غير موجود يعامل بنفس الطريقة
        $this->getJson('/api/student/opportunities/'.($opportunity->id + 100))->assertNotFound();
        $this->assertNull($draft->opportunity);

        // التعليق الإداري لا يُلغى بإعادة اعتماد المؤسسة
        $opportunity->update(['status' => ProjectOpportunity::STATUS_SUSPENDED]);
        $this->actingAs($opportunity->projectRequest->organization->user, 'sanctum')
            ->postJson($this->url($opportunity->projectRequest, 'approve'), ProjectProposalFixture::approval())
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
        $this->getJson("/api/student/opportunities/{$opportunity->id}")->assertForbidden();
        $this->actingAs($student, 'sanctum')
            ->getJson($this->url($opportunity->projectRequest))
            ->assertForbidden();
        $this->postJson($this->url($opportunity->projectRequest, 'approve'), ProjectProposalFixture::approval())
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
        $this->actingAs($mobile, 'sanctum')->getJson('/api/student/opportunities')
            ->assertJsonPath('data.opportunities.0.eligibility.status', OpportunityMatcher::ELIGIBLE);

        $this->actingAs($owner, 'sanctum')
            ->postJson($this->url($projectRequest, 'approve'), ProjectProposalFixture::approval($revised))
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

        // الفرصة ما زالت ظاهرة لطالب الجوال لكن دورها لم يعد مطلوبًا
        $this->actingAs($mobile, 'sanctum')->getJson('/api/student/opportunities')
            ->assertJsonCount(1, 'data.opportunities')
            ->assertJsonPath('data.opportunities.0.eligibility.status', OpportunityMatcher::SPECIALIZATION_MISMATCH);
        $this->actingAs($backend, 'sanctum')->getJson('/api/student/opportunities')
            ->assertJsonPath('data.opportunities.0.title', 'نظام المواصلات - الإصدار المعدل')
            ->assertJsonPath('data.opportunities.0.eligibility.status', OpportunityMatcher::ELIGIBLE);
    }

    public function test_legacy_versions_without_levels_require_any_verified_level_until_reconfirmed(): void
    {
        $opportunity = $this->publishedOpportunity();
        // نسخة منشورة قبل إضافة المستويات (كما تتركها الهجرة)
        ProjectRoleSkill::query()->update(['min_level' => null]);
        $student = $this->student('legacy-level@example.test', ['Laravel' => 'passed', 'REST API' => 'passed'], specialization: 'backend_development');

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/opportunities')
            ->assertJsonPath('data.opportunities.0.eligibility.status', OpportunityMatcher::ELIGIBLE)
            ->assertJsonPath('data.opportunities.0.roles.1.requirements.0.min_level', null)
            ->assertJsonPath('data.opportunities.0.roles.1.requirements.0.level_confirmed', false);

        $owner = $opportunity->projectRequest->organization->user;
        $this->actingAs($owner, 'sanctum')
            ->getJson($this->url($opportunity->projectRequest))
            ->assertJsonPath('data.publication.levels_confirmed', false)
            ->assertJsonPath('data.publication.roles.1.mandatory_requirements.0.min_level', null);

        // إعادة الاعتماد بمستويات مؤكدة تنشئ نسخة جديدة وتطبقها
        $this->postJson($this->url($opportunity->projectRequest, 'approve'), ProjectProposalFixture::approval(levels: ['Laravel' => 'advanced']))
            ->assertOk()
            ->assertJsonPath('data.publication.version', 2)
            ->assertJsonPath('data.publication.levels_confirmed', true);
        $this->actingAs($student, 'sanctum')
            ->getJson('/api/student/opportunities')
            ->assertJsonPath('data.opportunities.0.eligibility.status', OpportunityMatcher::LEVEL_INSUFFICIENT);
    }

    public function test_a_published_request_cannot_be_deleted_and_student_skill_management_still_works(): void
    {
        $opportunity = $this->publishedOpportunity();
        $owner = $opportunity->projectRequest->organization->user;
        $this->actingAs($owner, 'sanctum')
            ->deleteJson($this->url($opportunity->projectRequest))
            ->assertUnprocessable();

        // إضافة مهارة من الطالب بمستوى معلن لا تنشئ أي تحقق
        $student = $this->student('skills@example.test', []);
        $this->actingAs($student, 'sanctum')
            ->postJson('/api/student/skills', ['skill_id' => Skill::where('name', 'Flutter')->value('id'), 'proficiency' => 'expert'])
            ->assertCreated();
        $this->assertDatabaseCount('skill_assessments', 0);
        $this->getJson('/api/student/opportunities')
            ->assertJsonPath('data.verified_skills', [])
            ->assertJsonPath('data.opportunities.0.eligibility.status', OpportunityMatcher::SKILL_NOT_SELECTED);
    }

    public function test_admin_commands_record_levelled_assessments_and_moderate_opportunities(): void
    {
        $opportunity = $this->publishedOpportunity(['Flutter' => 'intermediate']);
        $student = $this->student('cli@example.test', ['Flutter' => null, 'Dart' => null]);

        $this->artisan('skills:record-assessment', ['email' => 'cli@example.test', 'skill' => 'flutter', '--score' => 85, '--level' => 'beginner'])->assertSuccessful();
        $this->artisan('skills:record-assessment', ['email' => 'cli@example.test', 'skill' => 'Dart', '--level' => 'beginner'])->assertSuccessful();
        $this->artisan('skills:record-assessment', ['email' => 'cli@example.test', 'skill' => 'Dart'])->assertFailed();
        $this->artisan('skills:record-assessment', ['email' => 'cli@example.test', 'skill' => 'Unknown skill', '--level' => 'beginner'])->assertFailed();
        $this->actingAs($student, 'sanctum')->getJson('/api/student/opportunities')
            ->assertJsonPath('data.opportunities.0.eligibility.status', OpportunityMatcher::LEVEL_INSUFFICIENT);
        $this->artisan('skills:record-assessment', ['email' => 'cli@example.test', 'skill' => 'Flutter', '--level' => 'intermediate'])->assertSuccessful();
        $this->getJson('/api/student/opportunities')
            ->assertJsonPath('data.opportunities.0.eligibility.status', OpportunityMatcher::ELIGIBLE);

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
            ->assertJsonPath('data.opportunities.0.eligibility.status', OpportunityMatcher::ELIGIBLE)
            ->assertJsonPath('data.specialization.status', SpecializationRoles::COMPATIBLE)
            ->assertJsonPath('data.specialization.key', 'mobile_development');

        $this->actingAs($designer, 'sanctum')
            ->getJson('/api/student/opportunities')
            ->assertOk()
            ->assertJsonCount(1, 'data.opportunities')
            ->assertJsonPath('data.opportunities.0.eligibility.status', OpportunityMatcher::SPECIALIZATION_MISMATCH)
            ->assertJsonPath('data.opportunities.0.roles.0.eligibility.message', 'هذا الدور (مطوّر تطبيقات جوال) لا يتوافق مع مسارك المهني الحالي (تصميم واجهات وتجربة المستخدم (UI/UX)).')
            ->assertJsonPath('data.opportunities.0.roles.0.specialization_compatible', false)
            ->assertJsonPath('data.verified_skills', ['Flutter', 'Dart'])
            ->assertJsonPath('data.specialization.key', 'ui_ux_design');
        $this->getJson("/api/student/opportunities/{$opportunity->id}")->assertOk();

        // عدد المستوفين الذي تراه المؤسسة يطبق القاعدة نفسها
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

        foreach ([[$legacy, SpecializationRoles::UNRECOGNIZED, 'مسارك المهني المسجل غير معروف'], [$missing, SpecializationRoles::MISSING, 'حدد مسارك المهني في ملفك أولًا']] as [$student, $status, $message]) {
            $response = $this->actingAs($student, 'sanctum')
                ->getJson('/api/student/opportunities')
                ->assertJsonCount(1, 'data.opportunities')
                ->assertJsonPath('data.opportunities.0.eligibility.status', OpportunityMatcher::PROFILE_INCOMPLETE)
                ->assertJsonPath('data.opportunities.0.eligibility.action.type', 'profile')
                ->assertJsonPath('data.specialization.status', $status);
            $this->assertStringStartsWith($message, $response->json('data.opportunities.0.eligibility.message'));
            $this->getJson("/api/student/opportunities/{$opportunity->id}")
                ->assertOk()
                ->assertJsonPath('data.eligibility.status', OpportunityMatcher::PROFILE_INCOMPLETE);
        }
    }

    public function test_assessment_levels_drive_eligibility_end_to_end(): void
    {
        $this->configureGemini();
        $opportunity = $this->publishedOpportunity(['Laravel' => 'intermediate']);
        $laravel = Skill::where('name', 'Laravel')->value('id');
        $rest = Skill::where('name', 'REST API')->value('id');
        $student = $this->student('journey@example.test', [], specialization: 'backend_development');
        $this->actingAs($student, 'sanctum');

        $this->getJson('/api/student/opportunities')
            ->assertJsonPath('data.opportunities.0.eligibility.status', OpportunityMatcher::SKILL_NOT_SELECTED);

        // REST API مبتدئ (المطلوب) + Laravel مبتدئ (أقل من المطلوب)
        $this->completeAttempt($rest, 'beginner', 'FULL');
        $this->completeAttempt($laravel, 'beginner', 'FULL')
            ->assertJsonPath('data.results.0.status', SkillAssessment::STATUS_PASSED);
        $this->getJson("/api/student/opportunities/{$opportunity->id}")
            ->assertOk()
            ->assertJsonPath('data.eligibility.status', OpportunityMatcher::LEVEL_INSUFFICIENT)
            ->assertJsonPath('data.eligibility.message', 'مستواك المثبت في Laravel هو مبتدئ، بينما يتطلب هذا الدور مستوى متوسطًا.');

        // رسوب في المتوسط لا يغيّر شيئًا، ثم النجاح فيه يرفع المستوى ويؤهل للدور
        $this->completeAttempt($laravel, 'intermediate', 'weak');
        $this->getJson("/api/student/opportunities/{$opportunity->id}")->assertJsonPath('data.eligibility.status', OpportunityMatcher::LEVEL_INSUFFICIENT);
        $this->travel(25)->hours();
        $this->completeAttempt($laravel, 'intermediate', 'FULL', 'B');
        $this->getJson("/api/student/opportunities/{$opportunity->id}")
            ->assertJsonPath('data.eligibility.status', OpportunityMatcher::ELIGIBLE)
            ->assertJsonPath('data.roles.1.requirements.0.verified_level', 'intermediate');

        // رسوب لاحق بمستوى مبتدئ لا يلغي المستوى المتوسط
        $this->travel(25)->hours();
        $this->completeAttempt($laravel, 'beginner', 'weak', 'C');
        $this->getJson("/api/student/opportunities/{$opportunity->id}")->assertJsonPath('data.eligibility.status', OpportunityMatcher::ELIGIBLE);

        // إزالة المهارة من الملف توقف احتسابها فورًا، والفرصة تبقى ظاهرة مع السبب
        $this->deleteJson("/api/student/skills/{$rest}")->assertOk();
        $this->getJson("/api/student/opportunities/{$opportunity->id}")
            ->assertOk()
            ->assertJsonPath('data.eligibility.status', OpportunityMatcher::SKILL_NOT_SELECTED);
    }

    /** محاولة كاملة لمهارة واحدة بمستوى محدد، كما تفعل الواجهة (Gemini وهمي). */
    private function completeAttempt(int $skillId, string $level, string $answer, string $variant = 'A'): TestResponse
    {
        // كل محاولة 12 طلبًا؛ دقيقة بين المحاولات حتى لا يتدخل حد throttle:assessments (30 في الدقيقة)
        $this->travel(61)->seconds();
        $this->fakeGemini($this->generatedAssessment($skillId, $level, true, $variant));
        $sessionId = $this->postJson('/api/student/assessment-sessions', ['skill_id' => $skillId, 'level' => $level])->assertCreated()->json('data.id');
        foreach (range(1, 10) as $_) {
            $question = $this->getJson("/api/student/assessment-sessions/{$sessionId}")->json('data.current_question.id');
            $this->postJson("/api/student/assessment-sessions/{$sessionId}/answers", ['question_id' => $question, 'answer' => $answer])->assertOk();
        }

        return $this->postJson("/api/student/assessment-sessions/{$sessionId}/finalize")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    /** @param array<string, string> $levels المستوى المؤكد لكل مهارة إلزامية (الافتراضي مبتدئ) */
    private function publishedOpportunity(array $levels = []): ProjectOpportunity
    {
        [$organization, $projectRequest] = $this->readyProject(null, 'publisher-'.uniqid().'@example.test');
        $this->actingAs($organization, 'sanctum')
            ->postJson($this->url($projectRequest, 'approve'), ProjectProposalFixture::approval(levels: $levels))
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
            'budget_range' => 'SECRET-BUDGET',
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
     * @param  array<string, ?string>  $skills  اسم المهارة => نتيجة التقييم: null بلا تقييم، أو passed/failed بمستوى
     *                                          مبتدئ، أو "passed:intermediate" بمستوى محدد
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

    private function assess(StudentProfile $profile, string $skill, string $result): void
    {
        [$status, $level] = array_pad(explode(':', $result), 2, 'beginner');
        $profile->skillAssessments()->create([
            'skill_id' => Skill::where('name', $skill)->value('id'),
            'status' => $status,
            'level' => $level,
            'source' => 'admin_review',
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
