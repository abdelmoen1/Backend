<?php

namespace Tests\Feature;

use App\Enums\ProjectRequestStatus;
use App\Models\IntakeSession;
use App\Models\OrganizationProfile;
use App\Models\ProjectRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LiveProjectDiscoveryLoopTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOM_REMINDER_ANSWER =
        'نريد إرسال تذكير للمريض عبر WhatsApp قبل موعده بيوم.';

    public function test_live_gemini_questions_adapt_to_clinic_answer_paths_and_resume(): void
    {
        $this->requireLiveGemini();
        $clinicScenarios = [
            [
                'email' => 'clinic-staff-live@example.test',
                'intent' => 'staff',
                'option_pattern' => '/استقبال|موظف/u',
                'question_pattern' => '/موظف|استقبال|مواعيد|تسجيل|تنظيم|تعارض/u',
            ],
            [
                'email' => 'clinic-self-service-live@example.test',
                'intent' => 'self_service',
                'option_pattern' => '/المرضى?.*(أنفسهم|بنفسهم|ذاتي|إلكتروني)/u',
                'question_pattern' => '/المريض|المرضى|حجز|صفحة|رابط|اختيار/u',
            ],
            [
                'email' => 'clinic-hybrid-live@example.test',
                'intent' => 'hybrid',
                'option_pattern' => '/كلاهما|معًا|الطرفين/u',
                'question_pattern' => '/موظف|استقبال|مريض|تنسيق|صلاحيات|مواعيد/u',
            ],
        ];

        foreach ($clinicScenarios as $scenario) {
            $project = $this->createProject(
                $scenario['email'],
                'عيادة',
                'نظام إدارة مواعيد المرضى',
                'نحتاج نظامًا لإدارة مواعيد المرضى.',
                'واتساب ودفتر ورقي.'
            );
            $this->actingAs($project->organization->user, 'sanctum');
            $endpoint = "/api/organization/project-requests/{$project->id}";

            $questionResponse = $this->postJson("{$endpoint}/next-question")
                ->assertOk()
                ->assertJsonPath('data.status', 'question');
            $question = $questionResponse->json('data.question');
            fwrite(STDOUT, "\nClinic {$scenario['intent']} Q1: {$question['text_ar']}\n");

            $option = collect($question['options'] ?? [])
                ->first(fn (array $choice): bool => preg_match(
                    $scenario['option_pattern'],
                    $choice['label_ar']
                ) === 1);
            $this->assertNotNull(
                $option,
                "Gemini's actual first question did not offer the requested {$scenario['intent']} answer path."
            );
            $this->assertFalse($option['allows_custom_text']);

            $nextResponse = $this->postJson("{$endpoint}/answer", [
                'question_id' => $question['id'],
                'selected_answer' => $option['value'],
            ])->assertOk()
                ->assertJsonPath('data.status', 'question');
            $nextQuestion = $nextResponse->json('data.question');
            fwrite(STDOUT, "Clinic {$scenario['intent']} Q2: {$nextQuestion['text_ar']}\n");
            $this->assertNotSame($question['text_ar'], $nextQuestion['text_ar']);
            $this->assertMatchesRegularExpression(
                $scenario['question_pattern'],
                $nextQuestion['text_ar'],
                "The next Gemini question did not reflect the {$scenario['intent']} answer path."
            );
            $this->assertSame($option['value'], $nextResponse->json('data.history.0.selected_answer'));
            $this->assertSame(1, $nextResponse->json('data.history.0.sequence'));

            $resumed = $this->postJson("{$endpoint}/next-question")
                ->assertOk()
                ->assertJsonPath('data.status', 'question');
            $this->assertSame($nextQuestion, $resumed->json('data.question'));
            $this->assertCount(1, $resumed->json('data.history'));
        }
    }

    public function test_live_gemini_uses_custom_other_answer_and_eventually_completes(): void
    {
        $this->requireLiveGemini();
        $project = $this->createProject(
            'clinic-other-live@example.test',
            'عيادة',
            'نظام إدارة مواعيد المرضى',
            'نحتاج نظامًا لإدارة مواعيد المرضى.',
            'واتساب ودفتر ورقي.'
        );
        $this->actingAs($project->organization->user, 'sanctum');
        $endpoint = "/api/organization/project-requests/{$project->id}";
        $geminiContexts = [];
        Event::listen(RequestSending::class, function (RequestSending $event) use (&$geminiContexts): void {
            if (! str_contains($event->request->url(), 'generativelanguage.googleapis.com')) {
                return;
            }

            $body = $event->request->data();
            $contextJson = $body['contents'][0]['parts'][0]['text'] ?? null;
            $context = is_string($contextJson) ? json_decode($contextJson, true) : null;
            if (is_array($context)) {
                $geminiContexts[] = $context;
            }
        });

        $question = $this->requestQuestion($endpoint);
        $customAnswerSaved = false;
        $customQuestion = null;

        for ($turn = 0; $turn < 5 && ! $customAnswerSaved; $turn++) {
            $other = collect($question['options'] ?? [])
                ->first(fn (array $option): bool => $option['value'] === 'other'
                    && $option['allows_custom_text'] === true);

            if ($other !== null) {
                $response = $this->postJson("{$endpoint}/answer", [
                    'question_id' => $question['id'],
                    'selected_answer' => $other['value'],
                    'custom_answer' => self::CUSTOM_REMINDER_ANSWER,
                ])->assertOk()
                    ->assertJsonPath('data.history.'.($turn).'.selected_answer', 'other')
                    ->assertJsonPath('data.history.'.($turn).'.custom_answer', self::CUSTOM_REMINDER_ANSWER);
                $customAnswerSaved = true;
                $customQuestion = $response->json('data.question');
                break;
            }

            $choice = $this->ordinaryAnswer($question);
            $response = $this->postJson("{$endpoint}/answer", [
                'question_id' => $question['id'],
                'selected_answer' => $choice,
            ])->assertOk()
                ->assertJsonPath('data.status', 'question');
            $question = $response->json('data.question');
        }

        $this->assertTrue($customAnswerSaved, 'Gemini did not offer a custom-text option within five live turns.');
        $this->assertNotNull($customQuestion, 'Expected Gemini to ask a follow-up after the custom answer.');
        fwrite(STDOUT, "\nClinic custom-answer follow-up: {$customQuestion['text_ar']}\n");
        $this->assertMatchesRegularExpression(
            '/واتساب|WhatsApp|تذكير|رسالة|موعد/u',
            $customQuestion['text_ar'],
            'The next Gemini question did not reflect the custom reminder requirement.'
        );

        $lastContext = $geminiContexts[array_key_last($geminiContexts)] ?? [];
        $lastFact = $lastContext['history'][array_key_last($lastContext['history'] ?? [])] ?? [];
        $this->assertSame(self::CUSTOM_REMINDER_ANSWER, $lastFact['custom_answer'] ?? null);
        $this->assertSame('other', $lastFact['selected_answer'] ?? null);
        $this->assertNotEmpty($lastFact['selected_option_labels'] ?? []);

        $session = $project->fresh()->intakeSession()->firstOrFail();
        $storedCustomAnswer = $session->responses()
            ->where('custom_answer', self::CUSTOM_REMINDER_ANSWER)
            ->first();
        $this->assertNotNull($storedCustomAnswer);
        $this->assertSame('other', $storedCustomAnswer->selected_answer);

        $question = $customQuestion;
        $completed = false;
        for ($turn = 0; $turn < 12; $turn++) {
            $response = $this->postJson("{$endpoint}/answer", [
                'question_id' => $question['id'],
                'selected_answer' => $this->ordinaryAnswer($question),
            ])->assertOk();
            $data = $response->json('data');
            if ($data['status'] === 'complete') {
                $completed = true;
                break;
            }
            $question = $data['question'];
            fwrite(STDOUT, 'Clinic completion follow-up: '.$question['text_ar']."\n");
        }

        $this->assertTrue($completed, 'Gemini did not return completion within the controlled 12-turn limit.');
        $completedSession = $project->fresh()->intakeSession()->firstOrFail();
        $this->assertSame(IntakeSession::STATUS_COMPLETED, $completedSession->status);
        $this->assertSame(ProjectRequestStatus::ANALYZING, $project->fresh()->status);
        $this->assertSame(
            self::CUSTOM_REMINDER_ANSWER,
            $completedSession->responses()->whereNotNull('answered_at')
                ->where('custom_answer', self::CUSTOM_REMINDER_ANSWER)
                ->value('custom_answer')
        );
        $historyCount = $completedSession->responses()->count();

        $this->postJson("{$endpoint}/next-question")
            ->assertOk()
            ->assertJsonPath('data.status', 'complete')
            ->assertJsonPath('data.question', null)
            ->assertJsonCount($historyCount, 'data.history');
        fwrite(STDOUT, "Clinic completion: complete with {$historyCount} persisted turns.\n");
    }

    public function test_live_gemini_questions_are_relevant_to_law_and_training_domains(): void
    {
        $this->requireLiveGemini();
        $domains = [
            [
                'email' => 'law-live@example.test',
                'type' => 'مكتب محاماة',
                'title' => 'نظام إدارة القضايا والعملاء',
                'problem' => 'نحتاج نظامًا لإدارة القضايا والعملاء.',
                'process' => 'ملفات وجداول متفرقة.',
                'pattern' => '/قضية|قضايا|عميل|ملف|جلسة|محام/u',
            ],
            [
                'email' => 'training-live@example.test',
                'type' => 'مركز تدريب',
                'title' => 'نظام إدارة الطلاب والحضور والاختبارات',
                'problem' => 'نحتاج نظامًا لإدارة الطلاب والحضور والاختبارات.',
                'process' => 'سجلات ورقية وجداول متفرقة.',
                'pattern' => '/طالب|متدرب|حضور|اختبار|دورة|تدريب/u',
            ],
        ];
        $domainQuestions = [];

        foreach ($domains as $domain) {
            $project = $this->createProject(
                $domain['email'],
                $domain['type'],
                $domain['title'],
                $domain['problem'],
                $domain['process']
            );
            $this->actingAs($project->organization->user, 'sanctum');
            $question = $this->requestQuestion(
                "/api/organization/project-requests/{$project->id}"
            );
            $domainQuestions[] = $question['text_ar'];
            fwrite(STDOUT, "{$domain['type']} Question 1: {$question['text_ar']}\n");
            $this->assertMatchesRegularExpression(
                $domain['pattern'],
                $question['text_ar'],
                "Gemini's question was not relevant to {$domain['type']}."
            );
        }

        $this->assertNotSame($domainQuestions[0], $domainQuestions[1]);
    }

    private function requireLiveGemini(): void
    {
        if (getenv('RUN_LIVE_GEMINI_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_LIVE_GEMINI_TESTS=1 to run real Gemini requests.');
        }

        if (! is_string(config('services.gemini.api_key'))
            || trim((string) config('services.gemini.api_key')) === '') {
            $this->markTestSkipped('The server-side Gemini key is not configured.');
        }
    }

    private function requestQuestion(string $endpoint): array
    {
        return $this->postJson("{$endpoint}/next-question")
            ->assertOk()
            ->assertJsonPath('data.status', 'question')
            ->json('data.question');
    }

    private function ordinaryAnswer(array $question): mixed
    {
        $ordinaryOptions = collect($question['options'] ?? [])
            ->reject(fn (array $option): bool => $option['allows_custom_text'])
            ->values();

        return match ($question['type']) {
            'single_choice' => $ordinaryOptions->first()['value'] ?? null,
            'multi_choice' => isset($ordinaryOptions[0])
                ? [$ordinaryOptions[0]['value']]
                : [],
            'short_text' => 'يستخدم الموظفون النظام لإنجاز العمل اليومي.',
            'long_text' => 'يسجل الموظفون التفاصيل ويتابعونها ضمن خطوات عمل واضحة.',
            'number' => 10,
            'date' => now()->addMonth()->toDateString(),
            'yes_no' => true,
        };
    }

    private function createProject(
        string $email,
        string $organizationType,
        string $title,
        string $problem,
        string $currentProcess
    ): ProjectRequest {
        $user = User::create([
            'name' => 'Live discovery organization',
            'email' => $email,
            'password' => 'SecurePass123',
            'role' => 'organization',
        ]);
        $organization = OrganizationProfile::create([
            'user_id' => $user->id,
            'organization_name' => "مؤسسة {$organizationType}",
            'organization_type' => $organizationType,
            'industry' => $organizationType,
        ]);

        return $organization->projectRequests()->create([
            'title' => $title,
            'problem_description' => $problem,
            'current_process' => [$currentProcess],
        ]);
    }
}
