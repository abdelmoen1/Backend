<?php

namespace Tests\Feature;

use App\Enums\ProjectRequestStatus;
use App\Models\IntakeResponse;
use App\Models\IntakeSession;
use App\Models\OrganizationProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProjectDiscoveryEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.api_key' => 'test-gemini-key',
            'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'services.gemini.model' => 'gemini-3.8-flash',
            'services.gemini.timeout' => 1,
        ]);
    }

    public function test_clinic_training_center_and_law_office_receive_context_specific_questions(): void
    {
        $questions = [
            'عيادة' => [
                'id' => 'clinic_appointment_flow',
                'text_ar' => 'كيف تفضلون تنظيم مواعيد المرضى وتأكيد حضورهم؟',
            ],
            'مركز تدريب' => [
                'id' => 'training_attendance',
                'text_ar' => 'كيف يسجل المركز حضور المتدربين في الدورات؟',
            ],
            'مكتب محاماة' => [
                'id' => 'law_case_tracking',
                'text_ar' => 'ما المراحل التي تمر بها القضية من تسجيلها حتى إغلاقها؟',
            ],
        ];
        $contexts = [];

        Http::fake(function (Request $request) use ($questions, &$contexts) {
            $context = json_decode($request['contents'][0]['parts'][0]['text'], true);
            $contexts[] = $context;
            $question = $questions[$context['organization']['type']];

            return Http::response($this->geminiResponse([
                'status' => 'question',
                'question' => $this->question(
                    $question['id'],
                    $question['text_ar']
                ),
            ]));
        });

        $results = [];
        foreach ([
            ['type' => 'عيادة', 'problem' => 'إدارة المواعيد'],
            ['type' => 'مركز تدريب', 'problem' => 'إدارة الطلاب والحضور والاختبارات'],
            ['type' => 'مكتب محاماة', 'problem' => 'إدارة القضايا والعملاء'],
        ] as $index => $details) {
            $organization = $this->organization(
                "discovery-{$index}@example.test",
                $details['type']
            );
            $projectRequest = $organization->organizationProfile->projectRequests()->create([
                'title' => 'تحسين العمل',
                'problem_description' => $details['problem'],
            ]);

            $response = $this->actingAs($organization, 'sanctum')
                ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
                ->assertOk()
                ->assertJsonPath('data.status', 'question');

            $results[] = $response->json('data.question');
        }

        $this->assertSame(
            ['clinic_appointment_flow', 'training_attendance', 'law_case_tracking'],
            array_column($results, 'id')
        );
        $this->assertCount(3, array_unique(array_column($results, 'text_ar')));
        $this->assertSame('إدارة الطلاب والحضور والاختبارات', $contexts[1]['project']['problem']);
        $this->assertSame('إدارة القضايا والعملاء', $contexts[2]['project']['problem']);
    }

    public function test_other_custom_answer_is_included_in_the_next_ai_context(): void
    {
        $organization = $this->organization('custom-answer@example.test', 'عيادة');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'مواعيد المرضى',
            'problem_description' => 'إدارة المواعيد يدويًا.',
        ]);
        $contexts = [];

        Http::fake(function (Request $request) use (&$contexts) {
            $context = json_decode($request['contents'][0]['parts'][0]['text'], true);
            $contexts[] = $context;

            if (count($contexts) === 1) {
                return Http::response($this->geminiResponse([
                    'status' => 'question',
                    'question' => $this->question(
                        'reminders',
                        'ما طريقة التذكير التي تناسب مرضاكم؟',
                        [
                            ['label_ar' => 'رسالة نصية', 'value' => 'sms', 'allows_custom_text' => false],
                            ['label_ar' => 'اتصال هاتفي', 'value' => 'call', 'allows_custom_text' => false],
                            ['label_ar' => 'أخرى', 'value' => 'other', 'allows_custom_text' => true],
                        ]
                    ),
                ]));
            }

            return Http::response($this->geminiResponse([
                'status' => 'question',
                'question' => [
                    'id' => 'whatsapp_reminder_timing',
                    'text_ar' => 'متى ترغبون بإرسال تذكير WhatsApp قبل الموعد؟',
                    'type' => 'short_text',
                    'options' => [],
                ],
            ]));
        });

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk()
            ->assertJsonPath('data.question.id', 'reminders');

        $answerResponse = $this->postJson(
            "/api/organization/project-requests/{$projectRequest->id}/answer",
            [
                'question_id' => 'reminders',
                'selected_answer' => 'other',
                'custom_answer' => 'نريد إرسال تذكير للمريض عبر WhatsApp',
            ]
        )
            ->assertOk()
            ->assertJsonPath('data.question.id', 'whatsapp_reminder_timing')
            ->assertJsonPath('data.history.0.selected_answer', 'other')
            ->assertJsonPath('data.history.0.custom_answer', 'نريد إرسال تذكير للمريض عبر WhatsApp');

        $this->assertContains(
            'نريد إرسال تذكير للمريض عبر WhatsApp',
            $contexts[1]['known_requirements']['custom_answers']
        );
        $this->assertSame(
            'نريد إرسال تذكير للمريض عبر WhatsApp',
            $contexts[1]['history'][0]['custom_answer']
        );
        $this->assertSame(
            ['أخرى'],
            $contexts[1]['history'][0]['selected_option_labels']
        );
        $this->assertStringContainsString('غير معروفة', $contexts[1]['remaining_unknowns']['instruction']);
        $this->assertSame(
            'whatsapp_reminder_timing',
            $answerResponse->json('data.question.id')
        );
        $this->assertSame(
            'reminders',
            $contexts[1]['known_requirements']['confirmed_discovery_answers'][0]['question_id']
        );
        $this->assertSame('عيادة', $contexts[1]['organization']['type']);
        $this->assertSame('مواعيد المرضى', $contexts[1]['project']['title']);
    }

    public function test_duplicate_answer_is_idempotent_and_next_question_is_not_duplicated(): void
    {
        $organization = $this->organization('duplicate-answer@example.test', 'عيادة');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'تنظيم المواعيد',
            'problem_description' => 'إدارة المواعيد يدويًا.',
        ]);
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' => Http::sequence()
                ->push($this->geminiResponse([
                    'status' => 'question',
                    'question' => $this->question('q1', 'كيف تصل الطلبات؟'),
                ]))
                ->push($this->geminiResponse([
                    'status' => 'question',
                    'question' => $this->question('q2', 'كم طلبًا يوميًا؟'),
                ])),
        ]);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk();
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk()
            ->assertJsonPath('data.question.id', 'q1')
            ->assertJsonCount(1, 'data.history');
        $answer = [
            'question_id' => 'q1',
            'selected_answer' => 'phone',
        ];

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/answer", $answer)
            ->assertOk()
            ->assertJsonPath('data.question.id', 'q2');
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/answer", $answer)
            ->assertOk()
            ->assertJsonPath('data.question.id', 'q2');

        $session = $projectRequest->intakeSession()->firstOrFail();
        $this->assertSame(2, $session->responses()->count());
        Http::assertSentCount(2);
    }

    public function test_answer_is_persisted_when_the_following_gemini_turn_fails(): void
    {
        $organization = $this->organization('provider-failure@example.test', 'عيادة');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'تنظيم المواعيد',
            'problem_description' => 'إدارة المواعيد يدويًا.',
        ]);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return Http::response($this->geminiResponse([
                    'status' => 'question',
                    'question' => $this->question('q1', 'كيف تصل الطلبات؟'),
                ]));
            }
            if ($calls <= 3) {
                return Http::response(['error' => ['message' => 'temporary failure']], 503);
            }

            return Http::response($this->geminiResponse([
                'status' => 'complete',
                'question' => null,
            ]));
        });
        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk();

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/answer", [
            'question_id' => 'q1',
            'selected_answer' => 'phone',
        ])->assertStatus(503);

        $savedAnswer = IntakeResponse::query()
            ->where('question_id', 'q1')
            ->firstOrFail();
        $this->assertSame('phone', $savedAnswer->selected_answer);
        $this->assertNotNull($savedAnswer->answered_at);

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/answer", [
            'question_id' => 'q1',
            'selected_answer' => 'phone',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'complete');
        $this->assertSame(IntakeSession::STATUS_COMPLETED, $savedAnswer->intakeSession->fresh()->status);
        $this->assertSame(ProjectRequestStatus::ANALYZING, $projectRequest->fresh()->status);
    }

    public function test_answer_validation_requires_custom_text_for_other_and_rejects_stale_questions(): void
    {
        $organization = $this->organization('answer-validation@example.test', 'عيادة');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'تنظيم المواعيد',
            'problem_description' => 'إدارة المواعيد يدويًا.',
        ]);
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' => Http::response($this->geminiResponse([
                'status' => 'question',
                'question' => $this->question(
                    'q1',
                    'كيف تصل الطلبات؟',
                    [
                        ['label_ar' => 'هاتف', 'value' => 'phone', 'allows_custom_text' => false],
                        ['label_ar' => 'أخرى', 'value' => 'other', 'allows_custom_text' => true],
                    ]
                ),
            ])),
        ]);
        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk();

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/answer", [
            'question_id' => 'q1',
            'selected_answer' => 'other',
        ])->assertUnprocessable()->assertJsonValidationErrors('custom_answer');

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/answer", [
            'question_id' => 'stale-question',
            'selected_answer' => 'phone',
        ])->assertStatus(409);

        $this->assertNull(
            IntakeResponse::query()->where('question_id', 'q1')->firstOrFail()->answered_at
        );
    }

    public function test_repeated_ai_question_is_not_persisted_and_can_be_retried(): void
    {
        $organization = $this->organization('repeated-question@example.test', 'عيادة');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'مواعيد المرضى',
            'problem_description' => 'تنظيم المواعيد.',
        ]);
        $session = $projectRequest->intakeSession()->create();
        $session->responses()->create([
            'sequence' => 1,
            'question_id' => 'already-asked',
            'question_text' => 'من سيحجز المواعيد؟',
            'question_type' => 'single_choice',
            'options' => [
                ['label_ar' => 'الاستقبال', 'value' => 'staff', 'allows_custom_text' => false],
                ['label_ar' => 'أخرى', 'value' => 'other', 'allows_custom_text' => true],
            ],
            'selected_answer' => 'staff',
            'answered_at' => now(),
        ]);
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' => Http::response(
                $this->geminiResponse([
                    'status' => 'question',
                    'question' => $this->question('another-id', 'من سيحجز المواعيد؟'),
                ])
            ),
        ]);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertStatus(503)
            ->assertJsonPath('message', 'تعذر إنشاء سؤال جديد للاكتشاف حاليًا. إجاباتك محفوظة، ويمكنك المحاولة مرة أخرى.');

        $this->assertSame(1, $session->responses()->count());
        $this->assertNull($session->fresh()->current_question_id);
    }

    public function test_discovery_has_a_high_server_side_loop_safety_limit_without_faking_completion(): void
    {
        $organization = $this->organization('discovery-safety-limit@example.test', 'عيادة');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'مواعيد المرضى',
            'problem_description' => 'تنظيم المواعيد.',
        ]);
        $session = $projectRequest->intakeSession()->create();
        $responses = [];
        for ($sequence = 1; $sequence <= 50; $sequence++) {
            $responses[] = [
                'sequence' => $sequence,
                'question_id' => "question-{$sequence}",
                'question_text' => "سؤال {$sequence}",
                'question_type' => 'short_text',
                'selected_answer' => 'إجابة محفوظة',
                'answered_at' => now(),
            ];
        }
        $session->responses()->createMany($responses);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'توقفت جلسة الاكتشاف عند حد الأمان. يرجى التواصل مع الدعم لمتابعة الطلب.');

        $this->assertSame(IntakeSession::STATUS_ACTIVE, $session->fresh()->status);
        $this->assertSame(50, $session->responses()->count());
        Http::assertNothingSent();
    }

    public function test_all_question_types_accept_and_persist_their_answer_shapes(): void
    {
        $organization = $this->organization('answer-types@example.test', 'مؤسسة');
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' => Http::response($this->geminiResponse([
                'status' => 'complete',
                'question' => null,
            ])),
        ]);
        $answers = [
            ['single_choice', ['a', 'b'], 'a'],
            ['multi_choice', ['a', 'b'], ['a', 'b']],
            ['short_text', [], 'إجابة قصيرة'],
            ['long_text', [], 'شرح مفصل لسير العمل الحالي'],
            ['number', [], 12],
            ['date', [], '2026-10-10'],
            ['yes_no', [], true],
        ];

        foreach ($answers as $index => [$type, $values, $answer]) {
            $projectRequest = $organization->organizationProfile->projectRequests()->create([
                'title' => "طلب {$index}",
            ]);
            $session = $projectRequest->intakeSession()->create([
                'current_question_id' => "type_{$index}",
            ]);
            $options = array_map(
                fn (string $value): array => [
                    'label_ar' => $value,
                    'value' => $value,
                    'allows_custom_text' => false,
                ],
                $values
            );
            $session->responses()->create([
                'sequence' => 1,
                'question_id' => "type_{$index}",
                'question_text' => 'سؤال',
                'question_type' => $type,
                'options' => $options,
            ]);

            $response = $this->actingAs($organization, 'sanctum')
                ->postJson(
                    "/api/organization/project-requests/{$projectRequest->id}/answer",
                    [
                        'question_id' => "type_{$index}",
                        'selected_answer' => $answer,
                    ]
                );
            $response->assertOk()->assertJsonPath('data.status', 'complete');

            $this->assertNotNull(
                $session->responses()->firstOrFail()->fresh()->answered_at
            );
        }
    }

    public function test_discovery_endpoints_are_scoped_to_the_authenticated_organization(): void
    {
        $owner = $this->organization('discovery-owner@example.test', 'عيادة');
        $other = $this->organization('discovery-other@example.test', 'مكتب محاماة');
        $projectRequest = $owner->organizationProfile->projectRequests()->create([
            'title' => 'طلب خاص',
            'problem_description' => 'وصف خاص للمشكلة.',
        ]);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertNotFound();
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/answer", [
            'question_id' => 'q1',
            'selected_answer' => 'answer',
        ])->assertNotFound();

        $this->assertDatabaseCount('intake_sessions', 0);
    }

    private function organization(string $email, string $type): User
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
            'organization_type' => $type,
            'industry' => $type,
            'description' => "سياق خاص بـ {$type}",
        ]);

        return $user->load('organizationProfile');
    }

    private function question(string $id, string $text, ?array $options = null): array
    {
        return [
            'id' => $id,
            'text_ar' => $text,
            'type' => 'single_choice',
            'options' => $options ?? [
                ['label_ar' => 'هاتف', 'value' => 'phone', 'allows_custom_text' => false],
                ['label_ar' => 'زيارة', 'value' => 'visit', 'allows_custom_text' => false],
                ['label_ar' => 'أخرى', 'value' => 'other', 'allows_custom_text' => true],
            ],
        ];
    }

    private function geminiResponse(array $result): array
    {
        return [
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    ]],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
        ];
    }
}
