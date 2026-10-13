<?php

namespace Tests\Support;

use App\Services\Assessments\AssessmentQuestionGenerator;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Gemini وهمي لتقييمات المهارات: يميّز طلب التوليد من طلب التصحيح من تعليمات النظام، ويحفظ كل
 * الطلبات للتحقق مما أُرسل. التصحيح الافتراضي يعتمد على علامة في إجابة الطالب: FULL تمنح كل
 * النقاط، HALF نصفها، وغير ذلك "لا حل".
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
     * جلسة مولدة صالحة: مهام برمجية موزعة على المهارات كما يوزعها الخادم (10 افتراضيًا). لكل مهمة معياران (6 + 4 نقاط)
     * وحل مرجعي بقيمة سرية يسهل البحث عنها. $variant يغيّر النصوص لمحاكاة مجموعة جديدة.
     *
     * @param  array<int, string>  $skills  معرّف المهارة => المستوى، بترتيب الاختيار
     */
    protected function generatedAssessment(array $skills, string $variant = 'A'): array
    {
        $distribution = AssessmentQuestionGenerator::practicalDistribution(count($skills), (int) config('assessments.questions.practical', 10));
        $questions = [];
        $number = 0;
        foreach (array_keys($skills) as $index => $skillId) {
            $level = $skills[$skillId];
            foreach (range(1, $distribution[$index]) as $_) {
                $number++;
                $questions[] = [
                    'skill_id' => $skillId,
                    'title' => "مهمة {$number}",
                    'language' => 'php',
                    'task' => "اكتب الدالة total رقم {$number} للمهارة {$skillId} بمستوى {$level} (مجموعة {$variant}) لتعيد مجموع عناصر المصفوفة.",
                    'starter_code' => "function total(array \$items): int\n{\n    // TODO\n}",
                    'requirements' => ['تعيد 0 للمصفوفة الفارغة.'],
                    'examples' => [['input' => '[1, 2, 3]', 'output' => '6']],
                    'reference_solution' => "SECRET-SOLUTION-{$skillId}-{$number}",
                    'rubric' => [
                        ['description' => 'SECRET-CRITERION يعيد المجموع الصحيح.', 'points' => 6],
                        ['description' => 'SECRET-CRITERION يعالج المصفوفة الفارغة.', 'points' => 4],
                    ],
                ];
            }
        }

        return ['title' => 'تقييم تجريبي', 'questions' => $questions];
    }

    /** تصحيح وهمي بعلامة في إجابة الطالب: FULL كل النقاط، HALF نصفها، وغير ذلك "لا حل". */
    protected function markerGrade(array $payload): array
    {
        $answer = (string) $payload['student_answer'];
        $ratio = str_contains($answer, 'FULL') ? 1.0 : (str_contains($answer, 'HALF') ? 0.5 : 0.0);

        return [
            'question_id' => $payload['task']['id'],
            'verdict' => match (true) {
                $ratio === 1.0 => 'correct',
                $ratio > 0 => 'partially_correct',
                default => 'no_solution',
            },
            'criteria' => array_map(fn (array $criterion): array => [
                'id' => $criterion['id'],
                'awarded' => (int) floor($criterion['points'] * $ratio),
                'justification' => 'مبرر',
            ], $payload['rubric']['criteria']),
            'issues' => $ratio < 1 ? ['لا يعالج المصفوفة الفارغة.'] : [],
            'feedback' => 'ملاحظات المصحح على الحل.',
            'reasoning' => 'تقييم موجز لصحة الحل وجودته.',
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
