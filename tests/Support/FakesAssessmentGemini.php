<?php

namespace Tests\Support;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Gemini وهمي لتقييمات المهارات: يميّز طلب التوليد من طلب التصحيح من تعليمات النظام، ويحفظ كل
 * الطلبات للتحقق مما أُرسل. التصحيح الافتراضي يعتمد على علامة في إجابة الطالب: FULL تمنح كل
 * النقاط، HALF نصفها، وغير ذلك "إجابة غير كافية".
 */
trait FakesAssessmentGemini
{
    /** @var array<int, array{kind: string, body: array}> */
    protected array $geminiRequests = [];

    protected function configureGemini(): void
    {
        config([
            'services.gemini.api_key' => 'test-gemini-key',
            'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'services.gemini.model' => 'gemini-test',
            'services.gemini.timeout' => 1,
            'services.gemini.max_retries' => 1,
        ]);
    }

    /**
     * @param  array|Closure(int): (array|PromiseInterface)  $generation  نتيجة التوليد أو دالة برقم المحاولة
     * @param  ?Closure(array): (array|PromiseInterface)  $grading  دالة تستقبل محتوى طلب التصحيح
     */
    protected function fakeGemini(array|Closure $generation, ?Closure $grading = null): void
    {
        $this->geminiRequests = [];
        // Http::fake يراكم الردود الوهمية؛ مصنع جديد حتى يحل هذا الرد محل السابق
        Http::swap(new Factory(app('events')));
        Http::fake(function (Request $request) use ($generation, $grading) {
            $body = $request->data();
            $system = $body['systemInstruction']['parts'][0]['text'] ?? '';
            $isGeneration = str_contains($system, 'مُعِدّ تقييمات');
            $this->geminiRequests[] = ['kind' => $isGeneration ? 'generation' : 'grading', 'body' => $body];

            if ($isGeneration) {
                $count = count(array_filter($this->geminiRequests, fn (array $item): bool => $item['kind'] === 'generation'));
                $result = $generation instanceof Closure ? $generation($count) : $generation;
            } else {
                $payload = json_decode($body['contents'][0]['parts'][0]['text'], true);
                $result = ($grading ?? fn (array $payload): array => $this->markerGrade($payload))($payload);
            }

            return is_array($result) ? Http::response($this->geminiBody($result)) : $result;
        });
    }

    /** @return array<int, array> */
    protected function geminiRequestsOf(string $kind): array
    {
        return array_values(array_map(
            fn (array $item): array => $item['body'],
            array_filter($this->geminiRequests, fn (array $item): bool => $item['kind'] === $kind)
        ));
    }

    /**
     * تقييم مولد صالح: لكل مهارة سؤالان (شرح ثم كود) بمعيارين (6 + 4 نقاط) وقيم سرية يسهل البحث عنها.
     *
     * @param  array<int, int>  $skillIds
     */
    protected function generatedAssessment(array $skillIds, int $perSkill = 2): array
    {
        $questions = [];
        foreach ($skillIds as $skillId) {
            foreach (range(1, $perSkill) as $index) {
                $questions[] = [
                    'skill_id' => $skillId,
                    'format' => $index % 2 === 0 ? 'code' : 'text',
                    'difficulty' => 'intermediate',
                    'prompt' => "اشرح بكلماتك أو اكتب كودًا للمهارة {$skillId} في السؤال رقم {$index}.",
                    'code_context' => '',
                    'language' => $index % 2 === 0 ? 'php' : '',
                    'criteria' => [
                        ['description' => 'يشرح الفكرة الأساسية بدقة.', 'points' => 6],
                        ['description' => 'يذكر مثالًا صحيحًا.', 'points' => 4],
                    ],
                    'reference_answer' => "SECRET-REFERENCE-{$skillId}-{$index}",
                    'key_concepts' => ['SECRET-CONCEPT'],
                    'common_mistakes' => ['SECRET-MISTAKE'],
                    'minimum_evidence' => 'SECRET-MINIMUM-EVIDENCE',
                ];
            }
        }

        return ['title' => 'تقييم تجريبي', 'questions' => $questions];
    }

    protected function markerGrade(array $payload): array
    {
        $answer = (string) $payload['student_answer'];
        $ratio = str_contains($answer, 'FULL') ? 1.0 : (str_contains($answer, 'HALF') ? 0.5 : 0.0);

        return [
            'question_id' => $payload['question']['id'],
            'status' => $ratio > 0 ? 'graded' : 'insufficient_answer',
            'criteria' => array_map(fn (array $criterion): array => [
                'id' => $criterion['id'],
                'awarded' => (int) floor($criterion['points'] * $ratio),
                'justification' => 'مبرر',
            ], $payload['rubric']['criteria']),
            'feedback' => 'ملاحظات المصحح على الإجابة.',
            'evidence' => [mb_substr($answer, 0, 40)],
            'missing_concepts' => $ratio < 1 ? ['مفهوم ناقص'] : [],
        ];
    }

    protected function geminiBody(array $result): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [['text' => json_encode($result, JSON_UNESCAPED_UNICODE)]], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
        ];
    }
}
