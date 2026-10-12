<?php

namespace Tests\Feature;

use App\Models\IntakeSession;
use App\Models\OrganizationProfile;
use App\Models\ProjectRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * فشل Gemini أثناء الاكتشاف: تصنيف آمن للخطأ، حفظ إجابة المؤسسة قبل الطلب، ومتابعة المحادثة
 * دون تكرار الإجابة. كل استدعاءات Gemini هنا محاكاة HTTP؛ لا يُستخدم مفتاح حقيقي.
 */
class DiscoveryFailureRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const ANSWER = 'نسجل المواعيد في دفتر ورقي ونؤكدها عبر واتساب.';

    private const OVERLOADED = ['error' => [
        'code' => 503,
        'status' => 'UNAVAILABLE',
        'message' => 'The model is overloaded. Please try again later.',
    ]];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.debug' => false,
            'services.gemini.api_key' => 'test-gemini-key',
            'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'services.gemini.model' => 'gemini-test',
            'services.gemini.timeout' => 1,
            'services.gemini.max_retries' => 3,
            'services.gemini.retry_delay_ms' => 1000,
        ]);
        Sleep::fake();
    }

    public function test_a_successful_turn_carries_a_request_id(): void
    {
        [$organization, $projectRequest] = $this->project();
        Http::fake(['*' => Http::response($this->gemini($this->questionStep('q1', 'كيف تسجلون المواعيد اليوم؟')))]);

        $response = $this->actingAs($organization, 'sanctum')
            ->postJson($this->url($projectRequest, 'next-question'))
            ->assertOk()
            ->assertJsonPath('data.status', 'question')
            ->assertJsonPath('data.question.id', 'q1');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $response->headers->get('X-Request-Id'));
        $this->assertSame($response->headers->get('X-Request-Id'), Context::get('request_id'));
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_an_upstream_503_is_retried_with_backoff_and_the_turn_succeeds(): void
    {
        [$organization, $projectRequest] = $this->project();
        Http::fake(['*' => Http::sequence()
            ->push(self::OVERLOADED, 503)
            ->push($this->gemini($this->questionStep('q1', 'كيف تسجلون المواعيد اليوم؟')))]);

        $this->actingAs($organization, 'sanctum')
            ->postJson($this->url($projectRequest, 'next-question'))
            ->assertOk()
            ->assertJsonPath('data.question.id', 'q1');

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_a_persistent_upstream_503_keeps_the_answer_and_returns_a_safe_retryable_error(): void
    {
        [, $projectRequest] = $this->askFirstQuestion();
        Http::fake(['*' => Http::response(self::OVERLOADED, 503)]);
        Log::spy();

        $response = $this->postJson($this->url($projectRequest, 'answer'), [
            'question_id' => 'q1',
            'selected_answer' => self::ANSWER,
        ])->assertStatus(503)
            ->assertHeader('Retry-After', '5')
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'تعذر التواصل مع خدمة الذكاء الاصطناعي حاليًا. أعد المحاولة بعد قليل؛ إجاباتك محفوظة.')
            ->assertJsonPath('error.code', 'ai_temporarily_unavailable')
            ->assertJsonPath('error.retryable', true)
            ->assertJsonPath('error.retry_after', 5)
            ->assertJsonPath('answer_saved', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.question', null)
            ->assertJsonPath('data.history.0.selected_answer', self::ANSWER)
            ->assertJsonMissingPath('debug');

        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('request_id'));
        $this->assertStringNotContainsString('overloaded', $response->getContent());
        $this->assertStringNotContainsString('test-gemini-key', $response->getContent());
        // 3 محاولات محدودة للخطوة الفاشلة ثم توقف
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);

        $session = $this->discoverySession($projectRequest);
        $this->assertNotNull($session->responses()->where('question_id', 'q1')->value('answered_at'));
        $this->assertNull($session->current_question_id);
        $this->assertSame(0, $session->current_state['requirements']['processed_through']);

        // سطر سجل واحد يحدد المرحلة والسبب والمحاولات دون مفاتيح أو نص الإجابة
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode($context, JSON_UNESCAPED_UNICODE);

            return $message === 'Project discovery AI step failed.'
                && $context['endpoint'] === 'answer'
                && $context['stage'] === 'ai_request'
                && $context['error_code'] === 'ai_temporarily_unavailable'
                && $context['reason'] === 'upstream_unavailable'
                && $context['http_status'] === 503
                && $context['provider_status'] === 'UNAVAILABLE'
                && $context['gemini_attempts'] === 3
                && $context['answer_persisted'] === true
                && $context['unprocessed_answers'] === 1
                && ! str_contains($encoded, 'test-gemini-key')
                && ! str_contains($encoded, self::ANSWER);
        })->once();
    }

    public function test_retrying_after_partial_success_continues_without_duplicating_the_answer(): void
    {
        [, $projectRequest] = $this->askFirstQuestion();
        $contexts = [];
        $available = false;
        Http::fake(function (Request $request) use (&$contexts, &$available) {
            $contexts[] = json_decode($request['contents'][0]['parts'][0]['text'], true);

            return $available
                ? Http::response($this->gemini($this->questionStep('q2', 'من يستخدم جدول المواعيد؟', 'users')))
                : Http::response(self::OVERLOADED, 503);
        });

        $this->postJson($this->url($projectRequest, 'answer'), [
            'question_id' => 'q1',
            'selected_answer' => self::ANSWER,
        ])->assertStatus(503)->assertJsonPath('answer_saved', true);

        // الواجهة تتابع عبر next-question دون إعادة إرسال الإجابة
        $available = true;
        $this->postJson($this->url($projectRequest, 'next-question'))
            ->assertOk()
            ->assertJsonPath('data.question.id', 'q2')
            ->assertJsonPath('data.history.0.selected_answer', self::ANSWER);

        $this->assertSame(self::ANSWER, end($contexts)['new_organization_input'][0]['answer']);
        $session = $this->discoverySession($projectRequest);
        $this->assertSame(2, $session->responses()->count());
        $this->assertSame(1, $session->current_state['requirements']['processed_through']);

        // إعادة إرسال نفس الإجابة (مثل واجهة قديمة) تعيد السؤال المعلق دون حفظ مكرر أو طلب جديد
        $sent = count($contexts);
        $this->postJson($this->url($projectRequest, 'answer'), [
            'question_id' => 'q1',
            'selected_answer' => self::ANSWER,
        ])->assertOk()->assertJsonPath('data.question.id', 'q2');
        $this->assertCount($sent, $contexts);
        $this->assertSame(2, $session->responses()->count());

        // إجابة مختلفة لسؤال أُجيب عنه تُرفض ولا تستبدل المحفوظ
        $this->postJson($this->url($projectRequest, 'answer'), [
            'question_id' => 'q1',
            'selected_answer' => 'إجابة أخرى',
        ])->assertStatus(409);
        $this->assertSame(self::ANSWER, $session->responses()->where('question_id', 'q1')->value('selected_answer'));
    }

    public function test_a_rate_limit_returns_the_provider_delay_without_holding_the_request(): void
    {
        [$organization, $projectRequest] = $this->project();
        Http::fake(['*' => Http::response($this->quotaError('GenerateRequestsPerMinutePerProjectPerModel-FreeTier', '37s'), 429)]);

        $this->actingAs($organization, 'sanctum')
            ->postJson($this->url($projectRequest, 'next-question'))
            ->assertStatus(503)
            ->assertHeader('Retry-After', '37')
            ->assertJsonPath('error.code', 'ai_rate_limited')
            ->assertJsonPath('error.retryable', true)
            ->assertJsonPath('error.retry_after', 37)
            ->assertJsonPath('message', 'خدمة الذكاء الاصطناعي مشغولة حاليًا بسبب كثرة الطلبات. انتظر قليلًا ثم أعد المحاولة؛ إجاباتك محفوظة.');

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_an_exhausted_daily_quota_is_not_retried(): void
    {
        [$organization, $projectRequest] = $this->project();
        Http::fake(['*' => Http::response($this->quotaError('GenerateRequestsPerDayPerProjectPerModel-FreeTier', '20s'), 429)]);

        $response = $this->actingAs($organization, 'sanctum')
            ->postJson($this->url($projectRequest, 'next-question'))
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'ai_quota_exhausted')
            ->assertJsonPath('error.retryable', false)
            ->assertJsonPath('error.retry_after', null);

        $this->assertFalse($response->headers->has('Retry-After'));
        Http::assertSentCount(1);
    }

    public function test_configuration_and_request_errors_fail_fast_with_a_configuration_code(): void
    {
        [$organization, $projectRequest] = $this->project();
        $current = [];
        $sent = 0;
        Http::fake(function () use (&$current, &$sent) {
            $sent++;

            return Http::response($current[0], $current[1]);
        });
        $cases = [
            'invalid payload' => [['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Invalid JSON payload received.']], 400],
            'invalid key' => [['error' => ['status' => 'UNAUTHENTICATED', 'message' => 'API key not valid.']], 401],
            'unknown model' => [['error' => ['status' => 'NOT_FOUND', 'message' => 'models/gemini-test is not found.']], 404],
        ];

        foreach ($cases as $name => $current) {
            $sent = 0;
            $response = $this->actingAs($organization, 'sanctum')
                ->postJson($this->url($projectRequest, 'next-question'))
                ->assertStatus(503)
                ->assertJsonPath('error.code', 'ai_configuration_error')
                ->assertJsonPath('error.retryable', false)
                ->assertJsonPath('message', 'خدمة الذكاء الاصطناعي غير متاحة حاليًا بسبب مشكلة في إعدادات الخادم. إجاباتك محفوظة، ويرجى إبلاغ فريق الدعم.');

            $this->assertSame(1, $sent, $name);
            $this->assertFalse($response->headers->has('Retry-After'), $name);
        }

        config(['services.gemini.api_key' => '']);
        $sent = 0;
        $this->postJson($this->url($projectRequest, 'next-question'))
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'ai_configuration_error');
        $this->assertSame(0, $sent);
        $this->assertSame(0, $this->discoverySession($projectRequest)->responses()->count());
    }

    public function test_a_timeout_is_reported_without_retrying_inside_the_request(): void
    {
        [, $projectRequest] = $this->askFirstQuestion();
        $sent = 0;
        Http::fake(function () use (&$sent): never {
            $sent++;
            throw new ConnectionException('cURL error 28: Operation timed out after 1000 milliseconds');
        });

        $this->postJson($this->url($projectRequest, 'answer'), [
            'question_id' => 'q1',
            'selected_answer' => self::ANSWER,
        ])->assertStatus(503)
            ->assertJsonPath('error.code', 'ai_timeout')
            ->assertJsonPath('error.retryable', true)
            ->assertJsonPath('answer_saved', true)
            ->assertJsonPath('data.history.0.selected_answer', self::ANSWER);

        $this->assertSame(1, $sent);
        Sleep::assertNeverSlept();
    }

    public function test_malformed_output_keeps_the_answer_and_a_retry_recovers(): void
    {
        [, $projectRequest] = $this->askFirstQuestion();
        Http::fake(['*' => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [['text' => '{"status": "question", "question": {']]], 'finishReason' => 'MAX_TOKENS']]])
            ->push($this->gemini($this->questionStep('q2', 'من يستخدم جدول المواعيد؟', 'users')))]);

        $this->postJson($this->url($projectRequest, 'answer'), [
            'question_id' => 'q1',
            'selected_answer' => self::ANSWER,
        ])->assertStatus(503)
            ->assertJsonPath('error.code', 'ai_invalid_response')
            ->assertJsonPath('error.retryable', true)
            ->assertJsonPath('answer_saved', true);
        $this->assertSame(1, $this->discoverySession($projectRequest)->responses()->count());

        $this->postJson($this->url($projectRequest, 'next-question'))
            ->assertOk()
            ->assertJsonPath('data.question.id', 'q2');
    }

    public function test_provider_diagnostics_are_only_returned_when_debugging(): void
    {
        [$organization, $projectRequest] = $this->project();
        config(['services.gemini.max_retries' => 1, 'services.gemini.api_key' => 'AIzaRealLookingKey1234567890123456789']);
        Http::fake(['*' => Http::response(['error' => [
            'status' => 'PERMISSION_DENIED',
            'message' => 'Permission denied for key AIzaRealLookingKey1234567890123456789.',
        ]], 403)]);

        $hidden = $this->actingAs($organization, 'sanctum')
            ->postJson($this->url($projectRequest, 'next-question'))
            ->assertStatus(503)
            ->assertJsonMissingPath('debug');
        $this->assertStringNotContainsString('Permission denied', $hidden->getContent());

        config(['app.debug' => true]);
        $debug = $this->postJson($this->url($projectRequest, 'next-question'))
            ->assertStatus(503)
            ->assertJsonPath('debug.reason', 'permission_denied')
            ->assertJsonPath('debug.http_status', 403)
            ->assertJsonPath('debug.provider_status', 'PERMISSION_DENIED');
        $this->assertStringNotContainsString('AIzaRealLookingKey1234567890123456789', $debug->getContent());
    }

    public function test_a_reused_question_id_gets_a_new_id_instead_of_failing(): void
    {
        [, $projectRequest] = $this->askFirstQuestion();
        Http::fake(['*' => Http::sequence()
            ->push($this->gemini($this->questionStep('q1', 'من يستخدم جدول المواعيد؟', 'users')))
            ->push($this->gemini($this->questionStep('revision_scope', 'ما أهم ما تريدون تحسينه؟', 'desired_outcome')))]);

        $this->postJson($this->url($projectRequest, 'answer'), [
            'question_id' => 'q1',
            'selected_answer' => self::ANSWER,
        ])->assertOk()
            ->assertJsonPath('data.question.id', 'q1_2')
            ->assertJsonPath('data.question.text_ar', 'من يستخدم جدول المواعيد؟');

        // معرّف يبدأ ببادئة أدوار التعديل لا يُخلط بطلب تعديل
        $this->postJson($this->url($projectRequest, 'answer'), [
            'question_id' => 'q1_2',
            'selected_answer' => 'موظفة الاستقبال والطبيبان.',
        ])->assertOk()
            ->assertJsonPath('data.question.id', 'q_revision_scope_3')
            ->assertJsonPath('data.history.2.kind', 'question');
        Http::assertSentCount(2);
    }

    public function test_a_repeated_question_text_is_corrected_once_and_never_shown(): void
    {
        [, $projectRequest] = $this->askFirstQuestion();
        $contexts = [];
        $repeatAgain = false;
        Http::fake(function (Request $request) use (&$contexts, &$repeatAgain) {
            $contexts[] = json_decode($request['contents'][0]['parts'][0]['text'], true);
            $repeat = count($contexts) % 2 === 1 || $repeatAgain;

            return Http::response($this->gemini($repeat
                ? $this->questionStep('again', 'كيف تسجلون المواعيد اليوم؟')
                : $this->questionStep('q2', 'من يستخدم جدول المواعيد؟', 'users')));
        });

        $this->postJson($this->url($projectRequest, 'answer'), [
            'question_id' => 'q1',
            'selected_answer' => self::ANSWER,
        ])->assertOk()->assertJsonPath('data.question.id', 'q2');
        $this->assertCount(2, $contexts);
        $this->assertSame('كيف تسجلون المواعيد اليوم؟', $contexts[1]['repeated_question_rejected']['question']);
        $this->assertSame([], $contexts[1]['new_organization_input']);

        // إن تكرر السؤال بعد التصحيح لا يُعرض ولا يُحفظ، ويُعاد خطأ قابل لإعادة المحاولة
        $repeatAgain = true;
        $this->postJson($this->url($projectRequest, 'answer'), [
            'question_id' => 'q2',
            'selected_answer' => 'موظفة الاستقبال.',
        ])->assertStatus(503)
            ->assertJsonPath('error.code', 'ai_invalid_response')
            ->assertJsonPath('answer_saved', true);
        $this->assertCount(4, $contexts);
        $this->assertSame(2, $this->discoverySession($projectRequest)->responses()->count());
    }

    public function test_another_organization_cannot_continue_or_read_a_failed_conversation(): void
    {
        [, $projectRequest] = $this->askFirstQuestion();
        Http::fake(['*' => Http::response(self::OVERLOADED, 503)]);
        $this->postJson($this->url($projectRequest, 'answer'), [
            'question_id' => 'q1',
            'selected_answer' => self::ANSWER,
        ])->assertStatus(503);
        $sent = count(Http::recorded());

        $intruder = $this->organization('intruder-'.uniqid().'@example.test');
        $this->actingAs($intruder, 'sanctum')
            ->postJson($this->url($projectRequest, 'next-question'))
            ->assertNotFound()
            ->assertJsonMissingPath('data');
        $this->postJson($this->url($projectRequest, 'answer'), [
            'question_id' => 'q1',
            'selected_answer' => self::ANSWER,
        ])->assertNotFound()->assertJsonMissingPath('data');

        $this->assertCount($sent, Http::recorded());
        $this->assertSame(1, $this->discoverySession($projectRequest)->responses()->count());
    }

    /** @return array{0: User, 1: ProjectRequest} */
    private function askFirstQuestion(): array
    {
        [$organization, $projectRequest] = $this->project();
        Http::fake(['*' => Http::response($this->gemini($this->questionStep('q1', 'كيف تسجلون المواعيد اليوم؟')))]);
        $this->actingAs($organization, 'sanctum')
            ->postJson($this->url($projectRequest, 'next-question'))
            ->assertOk();
        // المحاكاة التالية تُضاف بعد هذه، لذا نبدأ كل اختبار بمحاكاة جديدة نظيفة
        Http::swap(new Factory(app('events')));
        Sleep::fake();

        return [$organization, $projectRequest];
    }

    /** @return array{0: User, 1: ProjectRequest} */
    private function project(): array
    {
        $organization = $this->organization('clinic-'.uniqid().'@example.test');
        $projectRequest = $organization->organizationProfile->projectRequests()->create([
            'title' => 'نظام مواعيد العيادة',
            'problem_description' => 'نحن عيادة صغيرة ونسجل المواعيد يدويًا فتحدث تداخلات.',
        ]);

        return [$organization, $projectRequest];
    }

    private function url(ProjectRequest $projectRequest, string $action): string
    {
        return "/api/organization/project-requests/{$projectRequest->id}/{$action}";
    }

    private function discoverySession(ProjectRequest $projectRequest): IntakeSession
    {
        return IntakeSession::query()->where('project_request_id', $projectRequest->id)->firstOrFail();
    }

    private function questionStep(string $id, string $text, string $area = 'current_process'): array
    {
        return [
            'status' => 'question',
            'reply_ar' => '',
            'updates' => [],
            'coverage' => [],
            'question' => ['id' => $id, 'text_ar' => $text, 'area' => $area, 'why_ar' => '', 'type' => 'short_text', 'options' => []],
        ];
    }

    private function quotaError(string $quotaId, string $retryDelay): array
    {
        return ['error' => [
            'code' => 429,
            'status' => 'RESOURCE_EXHAUSTED',
            'message' => 'You exceeded your current quota, please check your plan and billing details.',
            'details' => [
                ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [['quotaId' => $quotaId]]],
                ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $retryDelay],
            ],
        ]];
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
