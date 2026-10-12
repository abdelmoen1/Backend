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

    /** أنواع أسئلة صالحة بالترتيب: 7 عملية ثم 3 كتابية */
    protected const PROGRAMMING_TYPES = ['coding', 'debugging', 'sql', 'implementation', 'code_analysis', 'coding', 'implementation', 'explanation', 'reasoning', 'technical_analysis'];

    protected const NON_PROGRAMMING_TYPES = ['scenario', 'design_task', 'case_study', 'scenario', 'design_task', 'case_study', 'scenario', 'explanation', 'reasoning', 'technical_analysis'];

    /**
     * محاولة مولدة صالحة لمهارة واحدة: 10 أسئلة (7 عملية + 3 كتابية) بالمستوى المطلوب، لكل منها معياران
     * (6 + 4 نقاط) وقيم سرية يسهل البحث عنها. $variant يغيّر نصوص الأسئلة لمحاكاة مجموعة جديدة.
     */
    protected function generatedAssessment(int $skillId, string $level = 'intermediate', bool $programming = true, string $variant = 'A'): array
    {
        $questions = [];
        foreach ($programming ? self::PROGRAMMING_TYPES : self::NON_PROGRAMMING_TYPES as $index => $type) {
            $number = $index + 1;
            $questions[] = [
                'skill_id' => $skillId,
                'type' => $type,
                'difficulty' => $level,
                'prompt' => "سؤال {$type} رقم {$number} للمهارة {$skillId} بمستوى {$level} (مجموعة {$variant}).",
                'code_context' => in_array($type, ['debugging', 'code_analysis'], true) ? "<?php\n\$items = [1, 2];\necho \$items[2];" : '',
                'language' => $programming && in_array($type, ['coding', 'debugging', 'implementation', 'code_analysis'], true) ? 'php' : ($type === 'sql' ? 'sql' : ''),
                'criteria' => [
                    ['description' => 'يشرح الفكرة الأساسية بدقة.', 'points' => 6],
                    ['description' => 'يذكر مثالًا صحيحًا.', 'points' => 4],
                ],
                'reference_answer' => "SECRET-REFERENCE-{$skillId}-{$number}",
                'key_concepts' => ['SECRET-CONCEPT'],
                'common_mistakes' => ['SECRET-MISTAKE'],
                'minimum_evidence' => 'SECRET-MINIMUM-EVIDENCE',
            ];
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
