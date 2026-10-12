<?php

namespace Tests\Feature;

use App\Contracts\ProjectProposalGenerator;
use App\Enums\ProjectRequestStatus;
use App\Models\IntakeSession;
use App\Models\OrganizationProfile;
use App\Models\ProjectRequest;
use App\Models\User;
use App\Services\AI\DiscoveryRequirements;
use App\Services\AI\GeminiProjectProposalGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProjectProposalFixture;
use Tests\TestCase;

class AdaptiveDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private const CLINIC_MESSAGE = 'نحن عيادة صغيرة. يتواصل المرضى معنا عبر واتساب لحجز المواعيد، وتسجل موظفة الاستقبال المواعيد يدويًا، وأحيانًا يُحجز مريضان في نفس الوقت.';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.api_key' => 'test-gemini-key',
            'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'services.gemini.model' => 'gemini-test',
            'services.gemini.timeout' => 1,
            'services.gemini.max_retries' => 1,
        ]);
    }

    public function test_one_message_populates_several_areas_and_the_next_question_targets_a_gap(): void
    {
        [$organization, $projectRequest] = $this->clinicProject();
        $contexts = [];
        Http::fake(function (Request $request) use (&$contexts) {
            $contexts[] = $this->context($request);

            return Http::response($this->gemini([
                'status' => 'question',
                'reply_ar' => 'فهمت أن الحجز عبر واتساب والتسجيل اليدوي يسببان تعارض المواعيد.',
                'updates' => [
                    $this->update('problem', 'fact', 'يُحجز أحيانًا مريضان في نفس الموعد.'),
                    $this->update('current_process', 'fact', 'يحجز المرضى عبر واتساب وتسجل موظفة الاستقبال المواعيد يدويًا.'),
                    $this->update('users', 'fact', 'موظفة الاستقبال تدير المواعيد.'),
                    $this->update('users', 'fact', 'المرضى يطلبون المواعيد.'),
                ],
                'coverage' => [
                    ['area' => 'problem', 'status' => 'sufficient'],
                    ['area' => 'current_process', 'status' => 'partial'],
                ],
                'question' => [
                    'id' => 'desired_outcome',
                    'text_ar' => 'ما النتيجة التي تريدون تحقيقها أولًا؟',
                    'why_ar' => 'تساعدنا على تحديد أولويات الحل.',
                    'area' => 'desired_outcome',
                    'type' => 'single_choice',
                    'options' => [
                        ['label_ar' => 'منع تعارض المواعيد', 'value' => 'no_overlap', 'allows_custom_text' => false],
                        ['label_ar' => 'تقليل وقت الاستقبال', 'value' => 'less_time', 'allows_custom_text' => false],
                        ['label_ar' => 'أخرى', 'value' => 'other', 'allows_custom_text' => true],
                    ],
                ],
            ]));
        });

        $response = $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk()
            ->assertJsonPath('data.status', 'question')
            ->assertJsonPath('data.question.area', 'desired_outcome')
            ->assertJsonPath('data.question.why_ar', 'تساعدنا على تحديد أولويات الحل.')
            ->assertJsonPath('data.history.0.assistant_reply', 'فهمت أن الحجز عبر واتساب والتسجيل اليدوي يسببان تعارض المواعيد.')
            ->assertJsonPath('data.requirements.tracked', true)
            ->assertJsonPath('data.requirements.total', 11)
            ->assertJsonPath('data.requirements.ready_for_proposal', false);

        // الرسالة الواحدة ملأت أكثر من محور
        $areas = collect($response->json('data.requirements.areas'))->keyBy('key');
        $this->assertSame('sufficient', $areas['problem']['status']);
        $this->assertSame('partial', $areas['current_process']['status']);
        $this->assertCount(2, $areas['users']['items']);
        $this->assertSame('missing', $areas['desired_outcome']['status']);

        // نموذج المشروع أُرسل كمدخلات جديدة، ومعه تعريف المحاور الإحدى عشرة
        $this->assertSame('project_form', $contexts[0]['new_organization_input'][0]['kind']);
        $this->assertSame(self::CLINIC_MESSAGE, $contexts[0]['new_organization_input'][0]['problem_description']);
        $this->assertCount(11, $contexts[0]['discovery_areas']);
        $this->assertSame('discovery', $contexts[0]['mode']);

        $state = $this->discoverySession($projectRequest)->current_state['requirements'];
        $this->assertTrue($state['form_processed']);
        $this->assertSame('project_form', $state['items'][0]['source']);
    }

    public function test_follow_up_context_keeps_known_requirements_and_only_sends_new_input(): void
    {
        [$organization, $projectRequest] = $this->clinicProject();
        $contexts = [];
        Http::fake(function (Request $request) use (&$contexts) {
            $contexts[] = $this->context($request);

            return Http::response($this->gemini(count($contexts) === 1
                ? [
                    'status' => 'question',
                    'reply_ar' => 'فهمت المشكلة.',
                    'updates' => [$this->update('problem', 'fact', 'تعارض المواعيد.')],
                    'coverage' => [],
                    'question' => $this->question('goal', 'ما النتيجة المطلوبة؟', 'desired_outcome'),
                ]
                : [
                    'status' => 'question',
                    'reply_ar' => 'واضح.',
                    'updates' => [$this->update('desired_outcome', 'fact', 'منع الحجز المزدوج.')],
                    'coverage' => [],
                    'question' => $this->question('needs', 'ما أهم ما تحتاجه موظفة الاستقبال؟', 'business_needs'),
                ]));
        });

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk();
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/answer", [
            'question_id' => 'goal',
            'selected_answer' => 'منع حجز موعدين في نفس الوقت',
        ])->assertOk()->assertJsonPath('data.question.id', 'needs');

        $second = $contexts[1];
        $this->assertSame('تعارض المواعيد.', $second['requirements_state']['problem']['items'][0]['text']);
        $this->assertSame('r1', $second['requirements_state']['problem']['items'][0]['id']);
        $this->assertCount(1, $second['new_organization_input']);
        $this->assertSame('answer', $second['new_organization_input'][0]['kind']);
        $this->assertSame('منع حجز موعدين في نفس الوقت', $second['new_organization_input'][0]['answer']);
        $this->assertSame('ما النتيجة المطلوبة؟', $second['already_asked_questions'][0]['text_ar']);

        $state = $this->discoverySession($projectRequest)->current_state['requirements'];
        $this->assertSame(1, $state['processed_through']);
        $this->assertSame('answer', $state['items'][1]['source']);
        $this->assertSame(1, $state['items'][1]['source_sequence']);
    }

    public function test_corrections_supersede_earlier_requirements_and_unknowns_are_not_facts(): void
    {
        [$organization, $projectRequest] = $this->clinicProject();
        Http::fake(['*' => Http::sequence()
            ->push($this->gemini([
                'status' => 'question',
                'reply_ar' => '',
                'updates' => [$this->update('important_features', 'fact', 'يحجز المرضى مواعيدهم بأنفسهم.', 'essential')],
                'coverage' => [],
                'question' => $this->question('budget', 'هل حددتم ميزانية للتطوير؟', 'budget'),
            ]))
            ->push($this->gemini([
                'status' => 'question',
                'reply_ar' => 'تم: لن يكون الحجز الذاتي في النسخة الأولى، والميزانية لم تُحدد بعد.',
                'updates' => [
                    $this->update('important_features', 'fact', 'موظفة الاستقبال فقط تدير المواعيد في النسخة الأولى.', 'essential', ['r1']),
                    $this->update('budget', 'unknown', 'لم تحدد المؤسسة ميزانية التطوير بعد.'),
                ],
                'coverage' => [['area' => 'budget', 'status' => 'unknown']],
                'question' => $this->question('deadline', 'هل لديكم موعد مستهدف للإطلاق؟', 'expected_timeline'),
            ]))]);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk();
        $response = $this->postJson("/api/organization/project-requests/{$projectRequest->id}/answer", [
            'question_id' => 'budget',
            'selected_answer' => 'لم نحددها بعد، ولا نحتاج الحجز الذاتي للمرضى في البداية',
        ])->assertOk();

        $items = $this->discoverySession($projectRequest)->current_state['requirements']['items'];
        $this->assertSame('superseded', $items[0]['status']);
        $this->assertSame('r2', $items[0]['superseded_by']);
        $this->assertSame('active', $items[1]['status']);
        $this->assertSame('essential', $items[1]['priority']);

        $areas = collect($response->json('data.requirements.areas'))->keyBy('key');
        $this->assertSame(['موظفة الاستقبال فقط تدير المواعيد في النسخة الأولى.'], array_column($areas['important_features']['items'], 'text'));
        $this->assertSame('unknown', $areas['budget']['status']);
        $this->assertSame('unknown', $areas['budget']['items'][0]['kind']);
    }

    public function test_free_text_is_accepted_for_choice_and_number_questions(): void
    {
        [$organization, $projectRequest] = $this->clinicProject();
        Http::fake(['*' => Http::sequence()
            ->push($this->gemini([
                'status' => 'question',
                'reply_ar' => '',
                'updates' => [],
                'coverage' => [],
                'question' => [
                    'id' => 'channel',
                    'text_ar' => 'كيف تُدار المواعيد حاليًا؟',
                    'area' => 'current_process',
                    'type' => 'single_choice',
                    'options' => [
                        ['label_ar' => 'دفتر ورقي', 'value' => 'paper', 'allows_custom_text' => false],
                        ['label_ar' => 'واتساب', 'value' => 'whatsapp', 'allows_custom_text' => false],
                    ],
                ],
            ]))
            ->push($this->gemini([
                'status' => 'question',
                'reply_ar' => '',
                'updates' => [],
                'coverage' => [],
                'question' => ['id' => 'volume', 'text_ar' => 'كم موعدًا يوميًا؟', 'area' => 'business_needs', 'type' => 'number', 'options' => []],
            ]))
            ->push($this->gemini([
                'status' => 'question',
                'reply_ar' => '',
                'updates' => [],
                'coverage' => [],
                'question' => $this->question('next', 'سؤال تالٍ', 'constraints'),
            ]))]);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk();

        // لا اختيار ولا نص → خطأ عربي واضح
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/answer", [
            'question_id' => 'channel',
            'selected_answer' => null,
        ])->assertUnprocessable()->assertJsonPath('errors.selected_answer.0', 'اختر إجابة أو اكتبها بكلماتك.');

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/answer", [
            'question_id' => 'channel',
            'selected_answer' => null,
            'custom_answer' => 'مزيج من الهاتف وجدول Excel مشترك',
        ])->assertOk()->assertJsonPath('data.question.id', 'volume');

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/answer", [
            'question_id' => 'volume',
            'selected_answer' => null,
            'custom_answer' => 'يختلف حسب الموسم',
        ])->assertOk()->assertJsonPath('data.question.id', 'next')
            ->assertJsonPath('data.history.0.custom_answer', 'مزيج من الهاتف وجدول Excel مشترك')
            ->assertJsonPath('data.history.1.selected_answer', null);
    }

    public function test_answer_survives_gemini_failure_and_extraction_runs_on_retry(): void
    {
        [$organization, $projectRequest] = $this->clinicProject();
        $contexts = [];
        $calls = 0;
        Http::fake(function (Request $request) use (&$contexts, &$calls) {
            $calls++;
            $contexts[] = $this->context($request);
            if ($calls === 2) {
                return Http::response(['error' => ['status' => 'UNAVAILABLE']], 503);
            }
            if ($calls === 3) {
                return Http::response($this->gemini([
                    'status' => 'question',
                    'reply_ar' => 'شكرًا.',
                    'updates' => [$this->update('desired_outcome', 'fact', 'إنهاء تعارض المواعيد.')],
                    'coverage' => [],
                    'question' => $this->question('q2', 'من يستخدم النظام؟', 'users'),
                ]));
            }

            return Http::response($this->gemini([
                'status' => 'question',
                'reply_ar' => '',
                'updates' => [],
                'coverage' => [],
                'question' => $this->question('q1', 'ما النتيجة المطلوبة؟', 'desired_outcome'),
            ]));
        });

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk();
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/answer", [
            'question_id' => 'q1',
            'selected_answer' => 'إنهاء التعارض',
        ])->assertStatus(503);

        $session = $this->discoverySession($projectRequest);
        $this->assertNotNull($session->responses()->where('question_id', 'q1')->value('answered_at'));
        $this->assertSame(0, $session->current_state['requirements']['processed_through']);

        // إعادة المحاولة بنفس الإجابة آمنة: لا حفظ مكرر، والإجابة تُستخرج الآن
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/answer", [
            'question_id' => 'q1',
            'selected_answer' => 'إنهاء التعارض',
        ])->assertOk()->assertJsonPath('data.question.id', 'q2');

        $this->assertSame('إنهاء التعارض', $contexts[2]['new_organization_input'][0]['answer']);
        $session->refresh();
        $this->assertSame(2, $session->responses()->count());
        $this->assertSame(1, $session->current_state['requirements']['processed_through']);
    }

    public function test_malformed_output_is_rejected_and_invalid_items_are_dropped_individually(): void
    {
        [$organization, $projectRequest] = $this->clinicProject();
        Http::fake(['*' => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [['text' => '{not json']]], 'finishReason' => 'STOP']]])
            ->push($this->gemini([
                'status' => 'question',
                'reply_ar' => 'تمام.',
                'updates' => [
                    ['area' => 'not_an_area', 'kind' => 'fact', 'text' => 'x', 'priority' => 'unspecified', 'replaces' => []],
                    ['area' => 'problem', 'kind' => 'rumor', 'text' => 'x', 'priority' => 'unspecified', 'replaces' => []],
                    ['area' => 'problem', 'kind' => 'fact', 'text' => '   ', 'priority' => 'unspecified', 'replaces' => []],
                    $this->update('problem', 'fact', 'تعارض المواعيد.', 'unspecified', ['r999']),
                ],
                'coverage' => [['area' => 'problem', 'status' => 'great']],
                'question' => $this->question('q1', 'ما النتيجة المطلوبة؟', 'desired_outcome'),
            ]))]);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'ai_invalid_response')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.question', null);
        $this->assertSame(0, $this->discoverySession($projectRequest)->responses()->count());

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk()
            ->assertJsonPath('data.question.id', 'q1');

        $requirements = $this->discoverySession($projectRequest)->current_state['requirements'];
        $this->assertCount(1, $requirements['items']);
        $this->assertSame('problem', $requirements['items'][0]['area']);
        $this->assertArrayNotHasKey('problem', $requirements['coverage']);
    }

    public function test_premature_completion_is_rejected_once_and_turned_into_a_question(): void
    {
        [$organization, $projectRequest] = $this->clinicProject();
        $contexts = [];
        Http::fake(function (Request $request) use (&$contexts) {
            $contexts[] = $this->context($request);

            return Http::response($this->gemini(count($contexts) === 1
                ? [
                    'status' => 'complete',
                    'reply_ar' => 'لدينا ما يكفي.',
                    'updates' => [$this->update('problem', 'fact', 'تعارض المواعيد.')],
                    'coverage' => [],
                    'question' => null,
                ]
                : [
                    'status' => 'question',
                    'reply_ar' => '',
                    'updates' => [],
                    'coverage' => [],
                    'question' => $this->question('users_q', 'من سيستخدم النظام؟', 'users'),
                ]));
        });

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk()
            ->assertJsonPath('data.status', 'question')
            ->assertJsonPath('data.question.id', 'users_q');

        $this->assertCount(2, $contexts);
        $this->assertSame([], $contexts[1]['new_organization_input']);
        $this->assertNotEmpty($contexts[1]['completion_rejected']['missing_core_areas']);
        $this->assertSame('تعارض المواعيد.', $contexts[1]['requirements_state']['problem']['items'][0]['text']);
        $this->assertSame(IntakeSession::STATUS_ACTIVE, $this->discoverySession($projectRequest)->status);
        $this->assertCount(1, $this->discoverySession($projectRequest)->current_state['requirements']['items']);
    }

    public function test_insisted_completion_is_accepted_without_looping_and_gaps_become_open_questions(): void
    {
        [$organization, $projectRequest] = $this->clinicProject();
        Http::fake(['*' => Http::response($this->gemini([
            'status' => 'complete',
            'reply_ar' => 'انتهينا.',
            'updates' => [$this->update('problem', 'fact', 'تعارض المواعيد.')],
            'coverage' => [],
            'question' => null,
        ]))]);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk()
            ->assertJsonPath('data.status', 'complete')
            ->assertJsonPath('data.completion_message', 'انتهينا.');

        Http::assertSentCount(2);
        $items = collect($this->discoverySession($projectRequest)->current_state['requirements']['items']);
        $this->assertSame(5, $items->where('kind', 'open_question')->count());
    }

    public function test_completion_depends_on_coverage_not_question_count(): void
    {
        [$organization, $projectRequest] = $this->clinicProject();
        Http::fake(['*' => Http::response($this->gemini([
            'status' => 'complete',
            'reply_ar' => 'لدينا صورة واضحة تكفي لإعداد المسودة.',
            'updates' => $this->coreFacts(),
            'coverage' => [],
            'question' => null,
        ]))]);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk()
            ->assertJsonPath('data.status', 'complete')
            ->assertJsonPath('data.requirements.ready_for_proposal', true);

        Http::assertSentCount(1);
        $this->assertSame(ProjectRequestStatus::ANALYZING, $projectRequest->fresh()->status);
    }

    public function test_organization_can_finish_early_when_core_areas_are_covered(): void
    {
        [$organization, $projectRequest] = $this->clinicProject();
        Http::fake(['*' => Http::response($this->gemini([
            'status' => 'question',
            'reply_ar' => '',
            'updates' => $this->coreFacts(),
            'coverage' => [],
            'question' => $this->question('ops', 'من سيتولى الصيانة بعد الإطلاق؟', 'operational_requirements'),
        ]))]);
        $generator = \Mockery::mock(ProjectProposalGenerator::class);
        $generator->shouldReceive('generate')->once()->andReturn(ProjectProposalFixture::valid());
        $this->app->instance(ProjectProposalGenerator::class, $generator);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk()
            ->assertJsonPath('data.requirements.ready_for_proposal', true);

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/analyze")
            ->assertOk()
            ->assertJsonPath('data.status', ProjectRequestStatus::PROPOSAL_READY);

        $session = $this->discoverySession($projectRequest);
        $this->assertSame(IntakeSession::STATUS_COMPLETED, $session->status);
        $this->assertSame(['ops'], $session->current_state['skipped_questions']);
        $this->assertSame(1, $session->responses()->count());

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk()
            ->assertJsonPath('data.status', 'complete')
            ->assertJsonPath('data.history.0.skipped', true)
            ->assertJsonPath('data.has_proposal', true);
    }

    public function test_editing_the_proposal_continues_the_same_conversation_and_regenerates_from_updated_requirements(): void
    {
        [$organization, $projectRequest] = $this->clinicProject();
        $contexts = [];
        Http::fake(function (Request $request) use (&$contexts) {
            $contexts[] = $this->context($request);

            return Http::response($this->gemini(count($contexts) === 1
                ? [
                    'status' => 'complete',
                    'reply_ar' => 'جاهزون.',
                    'updates' => array_merge($this->coreFacts(), [
                        $this->update('important_features', 'fact', 'حجز ذاتي للمرضى عبر رابط.', 'essential'),
                    ]),
                    'coverage' => [],
                    'question' => null,
                ]
                : [
                    'status' => 'complete',
                    'reply_ar' => 'حدّثت النطاق: لا حجز ذاتي في النسخة الأولى، وقد يقل النطاق والمدة.',
                    'updates' => [
                        $this->update('important_features', 'fact', 'لا يوجد حجز ذاتي للمرضى في النسخة الأولى؛ موظفة الاستقبال فقط تدير المواعيد.', 'essential', ['r7']),
                    ],
                    'coverage' => [],
                    'question' => null,
                ]));
        });
        $generated = [];
        $generator = \Mockery::mock(ProjectProposalGenerator::class);
        $generator->shouldReceive('generate')->twice()->andReturnUsing(function (ProjectRequest $record) use (&$generated) {
            $generated[] = [
                'features' => collect(DiscoveryRequirements::activeItems(DiscoveryRequirements::from($record->intakeSession->current_state)))
                    ->where('area', 'important_features')->pluck('text')->all(),
                'revision_note' => $record->revision_note,
            ];

            return ProjectProposalFixture::valid();
        });
        $this->app->instance(ProjectProposalGenerator::class, $generator);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk()->assertJsonPath('data.status', 'complete');
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/analyze")->assertOk();

        $note = 'لا نحتاج حجز المرضى الذاتي في النسخة الأولى. موظفة الاستقبال فقط تدير المواعيد.';
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/revision", ['note' => $note])
            ->assertOk()
            ->assertJsonPath('data.status', ProjectRequestStatus::REVISION_REQUESTED);

        $session = $this->discoverySession($projectRequest);
        $this->assertSame(IntakeSession::STATUS_ACTIVE, $session->status);
        $this->assertSame(1, $session->responses()->count());
        $this->assertSame('revision_1', $session->responses()->first()->question_id);

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk()
            ->assertJsonPath('data.status', 'complete')
            ->assertJsonPath('data.history.0.kind', 'revision')
            ->assertJsonPath('data.history.0.selected_answer', $note)
            ->assertJsonPath('data.completion_message', 'حدّثت النطاق: لا حجز ذاتي في النسخة الأولى، وقد يقل النطاق والمدة.');

        $this->assertSame('revision', $contexts[1]['mode']);
        $this->assertSame('proposal_change_request', $contexts[1]['new_organization_input'][0]['kind']);
        $this->assertSame('نظام إدارة مواعيد العيادة', $contexts[1]['current_proposal']['project_overview']['project_name']);
        $this->assertSame('r7', $contexts[1]['requirements_state']['important_features']['items'][1]['id']);

        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/analyze")
            ->assertOk()
            ->assertJsonPath('data.status', ProjectRequestStatus::PROPOSAL_READY)
            ->assertJsonPath('data.revision_note', null);

        $this->assertContains('حجز ذاتي للمرضى عبر رابط.', $generated[0]['features']);
        $this->assertNotContains('حجز ذاتي للمرضى عبر رابط.', $generated[1]['features']);
        $this->assertContains('لا يوجد حجز ذاتي للمرضى في النسخة الأولى؛ موظفة الاستقبال فقط تدير المواعيد.', $generated[1]['features']);
        $this->assertSame($note, $generated[1]['revision_note']);
        $this->assertSame(1, ProjectRequest::count());
    }

    public function test_revision_waits_for_an_open_assistant_question(): void
    {
        [$organization, $projectRequest] = $this->clinicProject();
        $projectRequest->update(['proposal' => ProjectProposalFixture::valid(), 'status' => ProjectRequestStatus::PROPOSAL_READY]);
        $session = $projectRequest->intakeSession()->create(['current_question_id' => 'open_q', 'current_state' => []]);
        $session->responses()->create([
            'sequence' => 1,
            'question_id' => 'open_q',
            'question_text' => 'سؤال مفتوح؟',
            'question_type' => 'short_text',
            'options' => [],
        ]);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/revision", ['note' => 'غيّروا النطاق من فضلكم.'])
            ->assertStatus(409);

        $this->assertSame(1, $session->responses()->count());
        $this->assertSame(ProjectRequestStatus::PROPOSAL_READY, $projectRequest->fresh()->status);
    }

    public function test_another_organization_cannot_revise_or_continue_the_conversation(): void
    {
        [, $projectRequest] = $this->clinicProject();
        $projectRequest->update(['proposal' => ProjectProposalFixture::valid(), 'status' => ProjectRequestStatus::PROPOSAL_READY]);
        $other = $this->organization('intruder@example.test');
        Http::fake();

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/revision", ['note' => 'تعديل غير مصرح به.'])
            ->assertNotFound();
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")->assertNotFound();
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/analyze")->assertNotFound();

        Http::assertNothingSent();
        $this->assertNull($projectRequest->fresh()->revision_note);
    }

    public function test_proposal_generator_uses_cumulative_structured_requirements_and_drops_invalid_optional_sections(): void
    {
        [, $projectRequest] = $this->clinicProject();
        $requirements = DiscoveryRequirements::apply(
            DiscoveryRequirements::from([]),
            array_merge($this->coreFacts(), [
                $this->update('budget', 'unknown', 'الميزانية غير محددة بعد.'),
                $this->update('constraints', 'assumption', 'يكفي متصفح الويب على أجهزة العيادة.'),
                $this->update('expected_timeline', 'open_question', 'هل الموعد مرتبط بافتتاح الفرع الجديد؟'),
            ]),
            [],
            ['type' => 'answer', 'sequence' => 1]
        );
        $requirements['processed_through'] = 1;
        $session = $projectRequest->intakeSession()->create([
            'status' => IntakeSession::STATUS_COMPLETED,
            'current_state' => ['requirements' => $requirements],
        ]);
        $session->responses()->create([
            'sequence' => 1, 'question_id' => 'q1', 'question_text' => 'سؤال؟', 'question_type' => 'short_text',
            'options' => [], 'selected_answer' => 'إجابة مدمجة', 'answered_at' => now(),
        ]);
        $session->responses()->create([
            'sequence' => 2, 'question_id' => 'revision_2', 'question_text' => 'ما الذي تريد تعديله؟', 'question_type' => 'long_text',
            'options' => [], 'selected_answer' => 'أضيفوا تذكيرًا قبل الموعد', 'answered_at' => now(),
        ]);

        $proposal = ProjectProposalFixture::valid() + [
            'risks' => ['الاعتماد على اتصال إنترنت مستقر.'],
            'payment_schedule' => [['milestone' => '', 'share' => '30%', 'notes' => '']],
        ];
        $context = null;
        Http::fake(function (Request $request) use (&$context, $proposal) {
            $context = $this->context($request);

            return Http::response($this->gemini($proposal));
        });

        $result = app(GeminiProjectProposalGenerator::class)->generate($projectRequest->fresh());

        $this->assertSame(['الاعتماد على اتصال إنترنت مستقر.'], $result['risks']);
        $this->assertArrayNotHasKey('payment_schedule', $result);
        $this->assertSame('partial', $context['structured_requirements']['problem']['status']);
        $this->assertSame('تعارض المواعيد بسبب التسجيل اليدوي.', $context['structured_requirements']['problem']['items'][0]['text']);
        $this->assertSame('الميزانية: الميزانية غير محددة بعد.', $context['unknown_information'][0]);
        $this->assertSame('القيود: يكفي متصفح الويب على أجهزة العيادة.', $context['assumptions'][0]);
        $this->assertContains('الموعد: هل الموعد مرتبط بافتتاح الفرع الجديد؟', $context['unresolved_questions']);
        $this->assertCount(1, $context['unincorporated_organization_input']);
        $this->assertSame('أضيفوا تذكيرًا قبل الموعد', $context['unincorporated_organization_input'][0]['answer']);
    }

    public function test_editing_the_problem_form_mid_conversation_is_re_extracted_on_the_next_turn(): void
    {
        [$organization, $projectRequest] = $this->clinicProject();
        $contexts = [];
        Http::fake(function (Request $request) use (&$contexts) {
            $contexts[] = $this->context($request);

            return Http::response($this->gemini([
                'status' => 'question',
                'reply_ar' => '',
                'updates' => [],
                'coverage' => [],
                'question' => $this->question('q'.count($contexts), 'سؤال رقم '.count($contexts), 'users'),
            ]));
        });

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk();
        $this->patchJson("/api/organization/project-requests/{$projectRequest->id}", [
            'problem_description' => 'نحن مركز تدريب ونريد تنظيم تسجيل المتدربين بدل المواعيد.',
        ])->assertOk();
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/answer", [
            'question_id' => 'q1',
            'selected_answer' => 'المتدربون والإدارة',
        ])->assertOk();

        $this->assertSame('project_form', $contexts[1]['new_organization_input'][0]['kind']);
        $this->assertSame(
            'نحن مركز تدريب ونريد تنظيم تسجيل المتدربين بدل المواعيد.',
            $contexts[1]['new_organization_input'][0]['problem_description']
        );
        $this->assertSame('answer', $contexts[1]['new_organization_input'][1]['kind']);
        $this->assertTrue($this->discoverySession($projectRequest)->current_state['requirements']['form_processed']);
    }

    public function test_conversation_history_and_requirements_survive_a_reload(): void
    {
        [$organization, $projectRequest] = $this->clinicProject();
        Http::fake(['*' => Http::response($this->gemini([
            'status' => 'question',
            'reply_ar' => 'فهمت.',
            'updates' => [$this->update('problem', 'fact', 'تعارض المواعيد.')],
            'coverage' => [],
            'question' => $this->question('q1', 'ما النتيجة المطلوبة؟', 'desired_outcome'),
        ]))]);

        $this->actingAs($organization, 'sanctum')
            ->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk();

        // إعادة تحميل الصفحة: نفس السؤال والسجل والمتطلبات بدون استدعاء Gemini جديد
        $this->postJson("/api/organization/project-requests/{$projectRequest->id}/next-question")
            ->assertOk()
            ->assertJsonPath('data.question.id', 'q1')
            ->assertJsonPath('data.question.area', 'desired_outcome')
            ->assertJsonPath('data.history.0.assistant_reply', 'فهمت.')
            ->assertJsonPath('data.requirements.areas.0.items.0.text', 'تعارض المواعيد.');

        Http::assertSentCount(1);
    }

    private function coreFacts(): array
    {
        return [
            $this->update('problem', 'fact', 'تعارض المواعيد بسبب التسجيل اليدوي.'),
            $this->update('current_process', 'fact', 'الحجز عبر واتساب والتسجيل في دفتر.'),
            $this->update('desired_outcome', 'fact', 'منع الحجز المزدوج.'),
            $this->update('users', 'fact', 'موظفة الاستقبال.'),
            $this->update('business_needs', 'fact', 'معرفة الأوقات المتاحة فورًا.', 'essential'),
            $this->update('important_features', 'fact', 'تقويم مواعيد يمنع التداخل.', 'essential'),
        ];
    }

    private function update(string $area, string $kind, string $text, string $priority = 'unspecified', array $replaces = []): array
    {
        return compact('area', 'kind', 'text', 'priority', 'replaces');
    }

    private function question(string $id, string $text, string $area): array
    {
        return ['id' => $id, 'text_ar' => $text, 'area' => $area, 'why_ar' => '', 'type' => 'short_text', 'options' => []];
    }

    private function clinicProject(): array
    {
        $organization = $this->organization('clinic-'.uniqid().'@example.test');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'نظام مواعيد العيادة',
            'problem_description' => self::CLINIC_MESSAGE,
            'intake_data' => ['short_title' => 'تعارض المواعيد'],
        ]);

        return [$organization, $projectRequest];
    }

    private function discoverySession(ProjectRequest $projectRequest): IntakeSession
    {
        return IntakeSession::query()->where('project_request_id', $projectRequest->id)->firstOrFail();
    }

    private function context(Request $request): array
    {
        return json_decode($request['contents'][0]['parts'][0]['text'], true);
    }

    private function gemini(array $result): array
    {
        return [
            'candidates' => [[
                'content' => [
                    'parts' => [['text' => json_encode($result, JSON_UNESCAPED_UNICODE)]],
                    'role' => 'model',
                ],
                'finishReason' => 'STOP',
            ]],
        ];
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
            'organization_name' => 'Clinic',
            'organization_type' => 'عيادة',
            'industry' => 'صحة',
        ]);

        return $user->load('organizationProfile');
    }
}
