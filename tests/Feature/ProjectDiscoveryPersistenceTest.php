<?php

namespace Tests\Feature;

use App\Models\IntakeResponse;
use App\Models\IntakeSession;
use App\Models\OrganizationProfile;
use App\Models\ProjectRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectDiscoveryPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_session_and_full_question_history_survive_reload(): void
    {
        $organization = $this->organization('discovery@example.com');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'نظام مواعيد العيادة',
            'problem_description' => 'إدارة المواعيد يدويًا تسبب تداخلًا.',
            'current_process' => ['واتساب', 'دفتر ورقي'],
            'target_users' => ['المرضى', 'موظفو الاستقبال'],
            'desired_outcomes' => ['تقليل تعارض المواعيد'],
            'special_requirements' => 'يجب دعم اللغة العربية.',
        ]);
        $session = $projectRequest->intakeSession()->create([
            'current_question_id' => 'appointment_volume',
            'current_state' => ['step' => 2],
        ]);

        $session->responses()->create([
            'sequence' => 1,
            'question_id' => 'appointment_channel',
            'question_text' => 'كيف تستقبلون طلبات المواعيد؟',
            'question_type' => 'single_choice',
            'options' => [
                ['label_ar' => 'الهاتف', 'value' => 'phone'],
                ['label_ar' => 'واتساب', 'value' => 'whatsapp'],
                ['label_ar' => 'أخرى', 'value' => 'other'],
            ],
            'selected_answer' => 'other',
            'custom_answer' => 'زيارة مباشرة للعيادة',
            'answered_at' => now(),
        ]);
        $session->responses()->create([
            'sequence' => 2,
            'question_id' => 'appointment_volume',
            'question_text' => 'كم موعدًا تستقبلون يوميًا؟',
            'question_type' => 'single_choice',
            'options' => [
                ['label_ar' => 'أقل من 20', 'value' => 'under_20'],
                ['label_ar' => '20 أو أكثر', 'value' => '20_or_more'],
            ],
        ]);

        $reloadedRequest = ProjectRequest::with('organization', 'intakeSession.responses')
            ->findOrFail($projectRequest->id);
        $reloadedSession = $reloadedRequest->intakeSession;

        $this->assertSame($organization->organizationProfile->id, $reloadedRequest->organization->id);
        $this->assertSame('نظام مواعيد العيادة', $reloadedRequest->title);
        $this->assertSame(['واتساب', 'دفتر ورقي'], $reloadedRequest->current_process);
        $this->assertSame(IntakeSession::STATUS_ACTIVE, $reloadedSession->status);
        $this->assertSame('appointment_volume', $reloadedSession->current_question_id);
        $this->assertSame(['step' => 2], $reloadedSession->current_state);
        $this->assertCount(2, $reloadedSession->responses);
        $this->assertSame('appointment_channel', $reloadedSession->responses[0]->question_id);
        $this->assertSame('single_choice', $reloadedSession->responses[0]->question_type);
        $this->assertSame('other', $reloadedSession->responses[0]->selected_answer);
        $this->assertSame('زيارة مباشرة للعيادة', $reloadedSession->responses[0]->custom_answer);
        $this->assertSame('appointment_volume', $reloadedSession->responses[1]->question_id);
        $this->assertSame(2, $reloadedSession->responses[1]->sequence);
    }

    public function test_an_organization_cannot_find_another_organizations_discovery_history(): void
    {
        $owner = $this->organization('owner-discovery@example.com');
        $other = $this->organization('other-discovery@example.com');
        $projectRequest = $owner->organizationProfile->projectRequests()->create([
            'title' => 'طلب خاص',
        ]);
        $session = $projectRequest->intakeSession()->create();
        $session->responses()->create([
            'sequence' => 1,
            'question_id' => 'private_question',
            'question_text' => 'سؤال خاص',
            'question_type' => 'free_text',
            'selected_answer' => 'إجابة خاصة',
        ]);

        $scopedSession = $other->organizationProfile->projectRequests()
            ->with('intakeSession.responses')
            ->find($projectRequest->id);

        $this->assertNull($scopedSession);
        $this->assertNull(
            $other->organizationProfile->intakeSessions()->find($session->id)
        );
        $this->assertSame($owner->organizationProfile->id, $session->projectRequest->organization_id);
    }

    public function test_session_and_response_relationships_and_order_constraint_work(): void
    {
        $organization = $this->organization('relationships@example.com');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'طلب علاقات',
        ]);
        $session = $projectRequest->intakeSession()->create();
        $session->responses()->createMany([
            [
                'sequence' => 2,
                'question_id' => 'second',
                'question_text' => 'السؤال الثاني',
                'question_type' => 'single_choice',
            ],
            [
                'sequence' => 1,
                'question_id' => 'first',
                'question_text' => 'السؤال الأول',
                'question_type' => 'single_choice',
            ],
        ]);

        $this->assertInstanceOf(IntakeSession::class, $projectRequest->fresh()->intakeSession);
        $this->assertInstanceOf(ProjectRequest::class, $session->projectRequest);
        $this->assertSame(
            ['first', 'second'],
            $session->fresh()->responses->pluck('question_id')->all()
        );
        $this->assertInstanceOf(IntakeResponse::class, $session->responses()->first());
        $this->assertSame(
            $session->id,
            $organization->organizationProfile->intakeSessions()->first()->id
        );

        $this->expectException(QueryException::class);
        $session->responses()->create([
            'sequence' => 1,
            'question_id' => 'duplicate',
            'question_text' => 'ترتيب مكرر',
            'question_type' => 'single_choice',
        ]);
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
            'organization_type' => 'عيادة',
        ]);

        return $user->load('organizationProfile');
    }
}
