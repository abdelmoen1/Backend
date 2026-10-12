<?php

namespace Tests\Unit;

use App\Models\IntakeResponse;
use App\Models\IntakeSession;
use App\Models\OrganizationProfile;
use App\Models\ProjectRequest;
use App\Models\Skill;
use App\Models\User;
use App\Services\AI\GeminiProjectProposalGenerator;
use App\Services\AI\ProjectProposalException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProjectProposalFixture;
use Tests\TestCase;

class GeminiProjectProposalGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationSequence = 0;

    public function test_generates_and_normalizes_a_structured_proposal_from_project_and_discovery_context(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        $request = $this->projectRequestWithDiscovery();
        Http::fake([
            '*' => Http::response($this->geminiResponse(ProjectProposalFixture::valid())),
        ]);

        $proposal = app(GeminiProjectProposalGenerator::class)->generate($request);

        $this->assertSame('تقدير مبدئي: يحدد بعد اعتماد النطاق', $proposal['estimated_cost']);
        $this->assertSame(
            'نظام إدارة مواعيد العيادة',
            $proposal['project_overview']['project_name']
        );
        Http::assertSent(function (Request $sent): bool {
            $body = $sent->data();
            $encodedContext = $body['contents'][0]['parts'][0]['text'];
            $context = json_decode($encodedContext, true);

            return $sent->hasHeader('x-goog-api-key', 'test-key')
                && $body['generationConfig']['responseMimeType'] === 'application/json'
                && isset($body['generationConfig']['responseSchema']['properties']['functional_scope'])
                && ! isset($body['generationConfig']['responseFormat'])
                && $context['organization']['type'] === 'عيادة'
                && $context['discovery_answers'][0]['custom_answer'] === 'تذكير المرضى عبر الرسائل';
        });
    }

    public function test_staffing_is_constrained_to_platform_skills_and_drives_team_and_skill_lists(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        foreach (ProjectProposalFixture::STAFFING_SKILLS as $name) {
            Skill::create(['name' => $name]);
        }
        $request = $this->projectRequestWithDiscovery();
        $output = ProjectProposalFixture::withStaffing();
        $output['staffing_requirements'][] = ['category' => 'unknown_role', 'title' => '', 'responsibilities' => 'x'];
        $output['key_requirements'] = [
            ['requirement' => 'عرض الأجرة لكل مسار', 'basis' => 'confirmed', 'note' => 'ذكرتها المؤسسة'],
            ['requirement' => 'تقليل تكلفة التنقل 50%', 'basis' => 'guaranteed', 'note' => ''],
            ['requirement' => 'تطبيق جوال للركاب', 'basis' => 'recommendation', 'note' => 'بانتظار التأكيد'],
        ];
        Http::fake(['*' => Http::response($this->geminiResponse($output))]);

        $proposal = app(GeminiProjectProposalGenerator::class)->generate($request);

        // الدور غير الصالح يُحذف، والفريق والمهارات تُشتق من الأدوار المنظمة فلا تتعارض
        $this->assertCount(2, $proposal['staffing_requirements']);
        $this->assertSame(['مطوّر تطبيق الجوال', 'مطوّر الخدمات الخلفية'], array_column($proposal['proposed_team'], 'role'));
        $this->assertSame(['Flutter', 'Dart', 'Git', 'Laravel', 'REST API', 'MySQL'], $proposal['required_skills']);
        // الأساس غير المعروف يُستبعد ولا يُعرض كحقيقة
        $this->assertSame(['confirmed', 'recommendation'], array_column($proposal['key_requirements'], 'basis'));

        Http::assertSent(function (Request $sent): bool {
            $schema = $sent->data()['generationConfig']['responseSchema'];
            $context = json_decode($sent->data()['contents'][0]['parts'][0]['text'], true);
            $skills = $schema['properties']['staffing_requirements']['items']['properties']['mandatory_skills']['items']['enum'];

            return in_array('Laravel', $skills, true)
                && ! in_array('React Native', $skills, true)
                && in_array('staffing_requirements', $schema['required'], true)
                && in_array('Flutter', $context['skill_catalog'], true)
                && isset($context['role_categories']['mobile_developer']);
        });
    }

    public function test_rejects_malformed_and_incomplete_structured_output(): void
    {
        config(['services.gemini.api_key' => 'test-key']);
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"project_overview":']]],
                ]],
            ]),
        ]);

        try {
            app(GeminiProjectProposalGenerator::class)
                ->generate($this->projectRequestWithDiscovery());
            $this->fail('Malformed JSON must be rejected.');
        } catch (ProjectProposalException $exception) {
            $this->assertSame(
                'تعذر إعداد مقترح المشروع حاليًا. حاول مرة أخرى لاحقًا.',
                $exception->getMessage()
            );
        }

        Http::fake([
            '*' => Http::response($this->geminiResponse([
                ...ProjectProposalFixture::valid(),
                'extra' => 'unexpected',
            ])),
        ]);
        $this->expectException(ProjectProposalException::class);
        app(GeminiProjectProposalGenerator::class)
            ->generate($this->projectRequestWithDiscovery());
    }

    public function test_missing_key_provider_error_and_connection_failure_are_safe(): void
    {
        config(['services.gemini.api_key' => null]);
        $generator = app(GeminiProjectProposalGenerator::class);

        try {
            $generator->generate($this->projectRequestWithDiscovery());
            $this->fail('A missing API key must fail.');
        } catch (ProjectProposalException $exception) {
            $this->assertStringNotContainsString('test-key', $exception->getMessage());
        }

        config(['services.gemini.api_key' => 'test-key']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'private details']], 503)]);
        try {
            $generator->generate($this->projectRequestWithDiscovery());
            $this->fail('A provider failure must fail safely.');
        } catch (ProjectProposalException $exception) {
            $this->assertStringNotContainsString('private details', $exception->getMessage());
        }

        Http::fake(['*' => Http::failedConnection()]);
        $this->expectException(ProjectProposalException::class);
        $generator->generate($this->projectRequestWithDiscovery());
    }

    private function projectRequestWithDiscovery(): ProjectRequest
    {
        $user = User::create([
            'name' => 'Clinic',
            'email' => 'clinic-proposal-'.(++$this->organizationSequence).'@example.test',
            'password' => 'SecurePass123',
            'role' => 'organization',
        ]);
        $organization = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => 'عيادة الأمل',
            'organization_type' => 'عيادة',
        ]);
        $projectRequest = $organization->projectRequests()->create([
            'title' => 'نظام المواعيد',
            'problem_description' => 'إدارة المواعيد عبر واتساب ودفتر.',
        ]);
        $session = IntakeSession::create([
            'project_request_id' => $projectRequest->id,
            'status' => IntakeSession::STATUS_COMPLETED,
        ]);
        IntakeResponse::create([
            'intake_session_id' => $session->id,
            'sequence' => 1,
            'question_id' => 'reminder_channel',
            'question_text' => 'كيف تودون تذكير المرضى؟',
            'question_type' => 'short_text',
            'selected_answer' => 'other',
            'custom_answer' => 'تذكير المرضى عبر الرسائل',
            'answered_at' => now(),
        ]);

        return $projectRequest->fresh();
    }

    private function geminiResponse(array $proposal): array
    {
        return [
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode($proposal, JSON_UNESCAPED_UNICODE),
                    ]],
                ],
            ]],
        ];
    }
}
