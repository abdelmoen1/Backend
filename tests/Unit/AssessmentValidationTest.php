<?php

namespace Tests\Unit;

use App\Models\AssessmentSessionAnswer;
use App\Models\Skill;
use App\Models\SkillAssessment;
use App\Services\Assessments\AssessmentGrader;
use App\Services\Assessments\AssessmentQuestionGenerator;
use App\Services\Assessments\AssessmentSessionService;
use Tests\Support\FakesAssessmentGemini;
use Tests\TestCase;

/**
 * لا يُوثق بردود Gemini: التحقق من الأسئلة المولدة ومن درجات التصحيح، وقواعد نتيجة المهارة.
 */
class AssessmentValidationTest extends TestCase
{
    use FakesAssessmentGemini;

    public function test_tasks_are_distributed_with_at_least_three_per_skill(): void
    {
        $this->assertSame([10], AssessmentQuestionGenerator::practicalDistribution(1, 10));
        $this->assertSame([5, 5], AssessmentQuestionGenerator::practicalDistribution(2, 10));
        $this->assertSame([4, 3, 3], AssessmentQuestionGenerator::practicalDistribution(3, 10));
        // أربع مهارات لا تحقق حد 3 مهام لكل مهارة (لهذا الحد الأقصى 3)
        $this->assertSame(2, min(AssessmentQuestionGenerator::practicalDistribution(4, 10)));
    }

    public function test_generated_coding_tasks_are_validated_against_the_plan(): void
    {
        $generator = app(AssessmentQuestionGenerator::class);
        $plan = [$this->plan(10, 'PHP', 'intermediate', 5), $this->plan(20, 'JavaScript', 'expert', 5)];
        $valid = $this->generatedAssessment([10 => 'intermediate', 20 => 'expert']);

        $result = $generator->validate($valid, $plan, [], 3);
        $this->assertSame(array_map(fn (int $n): string => "q{$n}", range(1, 10)), array_column($result['questions'], 'id'));
        $this->assertSame([...array_fill(0, 5, 10), ...array_fill(0, 5, 20)], array_column($result['questions'], 'skill_id'));
        // الصعوبة والنوع من خطة الخادم، والمعرّفات والمجموع يحسبها الخادم
        $this->assertSame([...array_fill(0, 5, 'intermediate'), ...array_fill(0, 5, 'expert')], array_column($result['questions'], 'difficulty'));
        $this->assertSame(['coding'], array_values(array_unique(array_column($result['questions'], 'type'))));
        $this->assertSame(['c1', 'c2'], array_column($result['questions'][0]['rubric']['criteria'], 'id'));
        $this->assertSame([10, 10], array_column($result['questions'][0]['rubric']['criteria'], 'skill_id'));
        $this->assertSame(10, $result['questions'][0]['max_points']);
        $this->assertArrayNotHasKey('options', $result['questions'][0]);

        // مهارة تأخذ مهام غيرها حتى 7 + 3 مقبول؛ 8 + 2 أقل من الحد الأدنى لمهارة
        $skewed = $valid;
        foreach ([5, 6] as $index) {
            $skewed['questions'][$index]['skill_id'] = 10;
        }
        $this->assertSame([10 => 7, 20 => 3], array_count_values(array_column($generator->validate($skewed, $plan, [], 3)['questions'], 'skill_id')));
        $skewed['questions'][7]['skill_id'] = 10;
        $this->assertNull($generator->validate($skewed, $plan, [], 3));

        // عدد ناقص، أو تكرار داخل الجلسة، أو مطابقة حرفية لمهمة سابقة: تُستبعد المهمة فيقل العدد
        $this->assertNull($generator->validate(['questions' => array_slice($valid['questions'], 0, 9)], $plan, [], 3));
        $duplicate = $valid;
        $duplicate['questions'][1]['task'] = $duplicate['questions'][0]['task'];
        $this->assertNull($generator->validate($duplicate, $plan, [], 3));
        $previous = [AssessmentQuestionGenerator::fingerprint("  {$valid['questions'][9]['task']} ")];
        $this->assertNull($generator->validate($valid, $plan, $previous, 3));

        // كل مهمة تنقصها مادة التصحيح أو تشبه الاختيار من متعدد تُستبعد
        foreach ([
            ['skill_id' => 99],
            ['skill_id' => null],
            ['task' => 'قصير جدًا'],
            ['task' => "ما ناتج الكود؟\nأ) 1\nب) 2"],
            ['task' => 'صح أم خطأ: هذه الدالة تعيد المجموع الصحيح دائمًا.'],
            ['reference_solution' => '  '],
            ['rubric' => []],
            ['rubric' => [['description' => 'x', 'points' => 11]]],
            ['rubric' => [['description' => '', 'points' => 5]]],
            ['rubric' => [['description' => 'x', 'points' => 2.5]]],
            ['starter_code' => str_repeat('x', 4001)],
            'not an object',
        ] as $change) {
            $broken = $valid;
            $broken['questions'][3] = is_array($change) ? $change + $broken['questions'][3] : $change;
            $this->assertNull($generator->validate($broken, $plan, [], 3), json_encode($change));
        }

        // تفاصيل لا تخص التصحيح لا تُسقط المهمة: رقم المهارة نصًا، ونقاط 5.0، ولغة أو عنوان فارغ
        $lenient = $valid;
        $lenient['questions'][2] = ['skill_id' => '10', 'language' => '', 'title' => '', 'rubric' => [['description' => 'x', 'points' => 5.0]]] + $lenient['questions'][2];
        $accepted = $generator->validate($lenient, $plan, [], 3)['questions'][2];
        $this->assertSame([10, 'php', 5], [$accepted['skill_id'], $accepted['language'], $accepted['max_points']]);
        $this->assertNotSame('', $accepted['title']);
        $this->assertNull($generator->validate(['title' => 'x'], $plan, [], 3));
    }

    public function test_multi_skill_outcome_uses_only_each_skills_own_evidence(): void
    {
        $service = app(AssessmentSessionService::class);
        $settings = [
            'version' => 3,
            'min_practical_per_skill' => 3,
            'skill_pass_percentage' => 70,
            'practical_pass_percentage' => 60,
            'question_floor_percentage' => 40,
            'practical_questions_per_weak_allowed' => 3,
        ];
        // مهارة 1: 3 أسئلة عملية (10 نقاط)؛ مهارة 2: 3 أسئلة عملية؛ المقال: 40 نقطة للمهارة 1 و 5 للمهارة 2
        $questions = [
            ...array_map(fn (int $n): array => ['id' => "q{$n}", 'skill_id' => 1, 'skill_ids' => [1], 'category' => 'practical', 'max_points' => 10], [1, 2, 3]),
            ...array_map(fn (int $n): array => ['id' => "q{$n}", 'skill_id' => 2, 'skill_ids' => [2], 'category' => 'practical', 'max_points' => 10], [4, 5, 6]),
            ['id' => 'q7', 'skill_id' => null, 'skill_ids' => [1, 2], 'category' => 'written', 'max_points' => 45, 'rubric' => ['criteria' => [
                ['id' => 'c1', 'skill_id' => 1, 'points' => 40],
                ['id' => 'c2', 'skill_id' => 2, 'points' => 5],
            ]]],
        ];
        $answers = function (array $practical, array $essay, string $essayStatus = 'graded') {
            $items = collect($practical)->mapWithKeys(fn (?int $points, string $id): array => $points === null ? [] : [$id => new AssessmentSessionAnswer([
                'question_id' => $id, 'grade_status' => 'graded', 'awarded_points' => $points,
            ])]);
            $items['q7'] = new AssessmentSessionAnswer(['question_id' => 'q7', 'grade_status' => 'graded', 'awarded_points' => array_sum($essay), 'grade' => [
                'status' => $essayStatus,
                'criteria' => [['id' => 'c1', 'skill_id' => 1, 'awarded' => $essay[0]], ['id' => 'c2', 'skill_id' => 2, 'awarded' => $essay[1]]],
            ]]);

            return $items;
        };

        // مقال ممتاز (40/40) لا يعوّض عمليًا 50%: المجموع 78% لكن العملي أقل من 60%
        $set = $answers(['q1' => 5, 'q2' => 5, 'q3' => 5, 'q4' => 10, 'q5' => 10, 'q6' => 10], [40, 0]);
        $this->assertSame([SkillAssessment::STATUS_FAILED, 78, 'practical_below_threshold'], $service->multiSkillOutcome($questions, $set, $settings, 1));
        // المهارة 2 لا تأخذ شيئًا من معيار المهارة 1: 30/30 عملي + 0/5 مقال
        $this->assertSame([SkillAssessment::STATUS_PASSED, 85, 'passed'], $service->multiSkillOutcome($questions, $set, $settings, 2));
        // إجابة ضعيفة واحدة من ثلاث مسموحة، واثنتان لا
        $this->assertSame(SkillAssessment::STATUS_PASSED, $service->multiSkillOutcome($questions, $answers(['q1' => 10, 'q2' => 10, 'q3' => 2, 'q4' => 0, 'q5' => 0, 'q6' => 0], [40, 0]), $settings, 1)[0]);
        $this->assertSame(SkillAssessment::STATUS_FAILED, $service->multiSkillOutcome($questions, $answers(['q1' => 10, 'q2' => 3, 'q3' => 3, 'q4' => 0, 'q5' => 0, 'q6' => 0], [40, 0]), $settings, 1)[0]);
        // مهارة بتسعة أسئلة: 3 ضعيفة مسموحة، و4 لا، حتى مع مجموع 74% وعملي 68%
        $nine = [
            ...array_map(fn (int $n): array => ['id' => "p{$n}", 'skill_id' => 3, 'skill_ids' => [3], 'category' => 'practical', 'max_points' => 10], range(1, 9)),
            ['id' => 'e', 'skill_id' => null, 'skill_ids' => [3], 'category' => 'written', 'max_points' => 20, 'rubric' => ['criteria' => [['id' => 'c1', 'skill_id' => 3, 'points' => 20]]]],
        ];
        $nineAnswers = function (array $points) {
            $items = collect($points)->mapWithKeys(fn (int $value, int $index): array => ['p'.($index + 1) => new AssessmentSessionAnswer([
                'question_id' => 'p'.($index + 1), 'grade_status' => 'graded', 'awarded_points' => $value,
            ])]);
            $items['e'] = new AssessmentSessionAnswer(['question_id' => 'e', 'grade_status' => 'graded', 'awarded_points' => 20, 'grade' => [
                'status' => 'graded', 'criteria' => [['id' => 'c1', 'skill_id' => 3, 'awarded' => 20]],
            ]]);

            return $items;
        };
        $this->assertSame([SkillAssessment::STATUS_FAILED, 74, 'weak_answer'], $service->multiSkillOutcome($nine, $nineAnswers([10, 10, 10, 10, 10, 3, 3, 3, 3]), $settings, 3));
        $this->assertSame(SkillAssessment::STATUS_PASSED, $service->multiSkillOutcome($nine, $nineAnswers([10, 10, 10, 10, 10, 10, 3, 3, 3]), $settings, 3)[0]);
        // مقال "غير كافٍ" = صفر لكل المهارات مهما كانت درجات معاييره
        // 30/30 عملي + 0/40 مقال = 42%
        $this->assertSame(
            [SkillAssessment::STATUS_FAILED, 42, 'below_threshold'],
            $service->multiSkillOutcome($questions, $answers(['q1' => 10, 'q2' => 10, 'q3' => 10, 'q4' => 0, 'q5' => 0, 'q6' => 0], [40, 5], 'insufficient_answer'), $settings, 1)
        );
        // دليل عملي ناقص (سؤالان فقط) لا ينجح أبدًا
        $this->assertSame('insufficient_evidence', $service->multiSkillOutcome(array_slice($questions, 1), $answers(['q2' => 10, 'q3' => 10, 'q4' => 10, 'q5' => 10, 'q6' => 10], [40, 5]), $settings, 1)[2]);
        // تعذر تصحيح المقال يجعل كل مهارة يغطيها غير مكتملة
        $ungradable = $answers(['q1' => 10, 'q2' => 10, 'q3' => 10, 'q4' => 10, 'q5' => 10, 'q6' => 10], [0, 0]);
        $ungradable['q7'] = new AssessmentSessionAnswer(['question_id' => 'q7', 'grade_status' => 'ungradable']);
        $this->assertSame(SkillAssessment::STATUS_INCOMPLETE, $service->multiSkillOutcome($questions, $ungradable, $settings, 2)[0]);
    }

    private function plan(int $id, string $name, string $level, int $practical): array
    {
        $skill = new Skill(['name' => $name]);
        $skill->id = $id;

        return ['skill' => $skill, 'level' => $level, 'practical' => $practical];
    }

    public function test_multiple_choice_prompts_are_detected(): void
    {
        foreach ([
            "ما الناتج؟\nأ) 1\nب) 2\nج) 3",
            "What is printed?\nA) 1\nB) 2",
            'اختر الإجابة الصحيحة: ما وظيفة git add؟',
            'Which of the following is a PHP superglobal?',
            'صح أم خطأ: PHP لغة مترجمة.',
        ] as $prompt) {
            $this->assertTrue(AssessmentQuestionGenerator::looksLikeMultipleChoice($prompt), $prompt);
        }
        foreach ([
            'اكتب دالة PHP تعيد مجموع عناصر مصفوفة، واشرح كيف تتعامل مع المصفوفة الفارغة.',
            "اشرح الخطأ في الكود التالي وكيف تصلحه:\n\$a = [1, 2];\necho \$a[2];",
        ] as $prompt) {
            $this->assertFalse(AssessmentQuestionGenerator::looksLikeMultipleChoice($prompt), $prompt);
        }
    }

    public function test_code_reviews_must_match_the_rubric_and_points_are_summed_by_laravel(): void
    {
        $grader = app(AssessmentGrader::class);
        $question = [
            'id' => 'q2',
            'max_points' => 10,
            'rubric' => ['criteria' => [['id' => 'c1', 'description' => 'a', 'points' => 6], ['id' => 'c2', 'description' => 'b', 'points' => 4]], 'reference_solution' => 'x'],
        ];
        $grade = fn (array $changes = []): array => $changes + [
            'question_id' => 'q2',
            'verdict' => 'partially_correct',
            'criteria' => [['id' => 'c2', 'awarded' => 4, 'justification' => 'x'], ['id' => 'c1', 'awarded' => 3, 'justification' => 'y']],
            'issues' => ['لا يعالج القيم السالبة.', ''],
            'feedback' => 'جيد جزئيًا.',
            'reasoning' => 'المنطق صحيح جزئيًا.',
            // مجموع أو نجاح يدعيه النموذج يُتجاهل
            'total' => 10,
            'passed' => true,
        ];

        $valid = $grader->validate($grade(), $question);
        $this->assertSame(7, $valid['awarded_points']);
        $this->assertSame(10, $valid['max_points']);
        $this->assertSame(['c1', 'c2'], array_column($valid['criteria'], 'id'));
        $this->assertSame(['partially_correct', ['لا يعالج القيم السالبة.'], 'المنطق صحيح جزئيًا.', 'graded'], [$valid['verdict'], $valid['issues'], $valid['reasoning'], $valid['status']]);
        $this->assertArrayNotHasKey('passed', $valid);
        // question_id اختياري؛ لكنه إن وُجد يجب أن يطابق
        $this->assertNotNull($grader->validate(array_diff_key($grade(), ['question_id' => true]), $question));

        // لا حل = صفر مهما كانت درجات المعايير
        $none = $grader->validate($grade(['verdict' => 'no_solution']), $question);
        $this->assertSame([0, 'insufficient_answer'], [$none['awarded_points'], $none['status']]);

        foreach ([
            ['question_id' => 'q3'],
            ['verdict' => 'passed'],
            ['verdict' => null],
            ['criteria' => [['id' => 'c1', 'awarded' => 7, 'justification' => 'x'], ['id' => 'c2', 'awarded' => 0, 'justification' => 'x']]],
            ['criteria' => [['id' => 'c1', 'awarded' => -1, 'justification' => 'x'], ['id' => 'c2', 'awarded' => 0, 'justification' => 'x']]],
            ['criteria' => [['id' => 'c1', 'awarded' => 2.5, 'justification' => 'x'], ['id' => 'c2', 'awarded' => 0, 'justification' => 'x']]],
            ['criteria' => [['id' => 'c1', 'awarded' => 1, 'justification' => 'x']]],
            ['criteria' => [['id' => 'c1', 'awarded' => 1, 'justification' => 'x'], ['id' => 'c1', 'awarded' => 1, 'justification' => 'x']]],
            ['criteria' => [['id' => 'c1', 'awarded' => 1, 'justification' => 'x'], ['id' => 'c9', 'awarded' => 1, 'justification' => 'x']]],
            ['feedback' => ''],
        ] as $change) {
            $this->assertNull($grader->validate($grade($change), $question), json_encode($change));
        }
    }

    public function test_skill_outcome_rules(): void
    {
        $service = app(AssessmentSessionService::class);
        $settings = ['questions_per_skill' => 2, 'skill_pass_percentage' => 70, 'question_floor_percentage' => 40, 'retake_after_hours' => 24];
        $questions = collect([
            ['id' => 'q1', 'skill_id' => 1, 'max_points' => 20],
            ['id' => 'q2', 'skill_id' => 1, 'max_points' => 5],
        ]);
        $answers = fn (array $grades) => collect($grades)->map(fn (array $grade, string $id) => new AssessmentSessionAnswer([
            'question_id' => $id,
            'grade_status' => $grade[0],
            'awarded_points' => $grade[1],
        ]));

        $this->assertSame([SkillAssessment::STATUS_PASSED, 76, 'passed'], $service->skillOutcome($questions, $answers(['q1' => ['graded', 16], 'q2' => ['graded', 3]]), $settings));
        // 80% إجمالًا لكن إجابة شبه فارغة (0 من 5): لا تعوضها الإجابة الممتازة
        $this->assertSame([SkillAssessment::STATUS_FAILED, 80, 'weak_answer'], $service->skillOutcome($questions, $answers(['q1' => ['graded', 20], 'q2' => ['graded', 0]]), $settings));
        // 69% أقل من الحد ولو بفارق بسيط
        $this->assertSame(SkillAssessment::STATUS_FAILED, $service->skillOutcome(collect([['id' => 'q1', 'skill_id' => 1, 'max_points' => 100], ['id' => 'q2', 'skill_id' => 1, 'max_points' => 100]]), $answers(['q1' => ['graded', 69], 'q2' => ['graded', 69]]), $settings)[0]);
        // سؤال دون إجابة (انتهى الوقت)
        $this->assertSame([SkillAssessment::STATUS_FAILED, 80, 'unanswered_questions'], $service->skillOutcome($questions, $answers(['q1' => ['graded', 20]]), $settings));
        // تعذر التصحيح: غير مكتمل وليس رسوبًا
        $this->assertSame([SkillAssessment::STATUS_INCOMPLETE, null, 'grading_unavailable'], $service->skillOutcome($questions, $answers(['q1' => ['graded', 20], 'q2' => ['ungradable', null]]), $settings));
        // أدلة أقل من الحد الأدنى للأسئلة لا تنجح
        $this->assertSame(SkillAssessment::STATUS_FAILED, $service->skillOutcome($questions->take(1), $answers(['q1' => ['graded', 20]]), $settings)[0]);
    }

    public function test_attempt_outcome_rules_for_the_seven_three_mix(): void
    {
        $service = app(AssessmentSessionService::class);
        $settings = [
            'questions' => ['practical' => 7, 'written' => 3],
            'skill_pass_percentage' => 70,
            'practical_pass_percentage' => 60,
            'question_floor_percentage' => 40,
            'max_weak_answers' => 2,
        ];
        // 7 عملية × 10 نقاط ثم 3 كتابية × $writtenMax
        $questions = fn (int $writtenMax = 10) => collect(range(1, 10))->map(fn (int $n): array => [
            'id' => "q{$n}",
            'skill_id' => 1,
            'category' => $n <= 7 ? 'practical' : 'written',
            'max_points' => $n <= 7 ? 10 : $writtenMax,
        ]);
        $answers = fn (array $points) => collect($points)->mapWithKeys(fn (?int $value, int $index): array => $value === null ? [] : ['q'.($index + 1) => new AssessmentSessionAnswer([
            'question_id' => 'q'.($index + 1),
            'grade_status' => 'graded',
            'awarded_points' => $value,
        ])]);

        // مرتفعة مع إجابتين ضعيفتين فقط: ناجح
        $this->assertSame([SkillAssessment::STATUS_PASSED, 84, 'passed'], $service->skillOutcome($questions(), $answers([10, 10, 10, 10, 10, 10, 2, 10, 2, 10]), $settings));
        // ثلاث إجابات ضعيفة تتجاوز الحد ولو كان المجموع مرتفعًا
        $this->assertSame([SkillAssessment::STATUS_FAILED, 76, 'weak_answer'], $service->skillOutcome($questions(), $answers([10, 10, 10, 10, 10, 2, 2, 10, 2, 10]), $settings));
        // الكتابة الممتازة لا تعوّض ضعف الجانب العملي (50% < 60%) ولو بلغ المجموع 81%
        $this->assertSame([SkillAssessment::STATUS_FAILED, 81, 'practical_below_threshold'], $service->skillOutcome($questions(40), $answers([5, 5, 5, 5, 5, 5, 5, 40, 40, 40]), $settings));
        // دون الحد الإجمالي
        $this->assertSame([SkillAssessment::STATUS_FAILED, 60, 'below_threshold'], $service->skillOutcome($questions(), $answers(array_fill(0, 10, 6)), $settings));
        // أسئلة دون إجابة تُعد ضعيفة: اثنتان مقبولتان، ثلاث لا
        $this->assertSame(SkillAssessment::STATUS_PASSED, $service->skillOutcome($questions(), $answers([10, 10, 10, 10, 10, 10, 10, 10, null, null]), $settings)[0]);
        $this->assertSame([SkillAssessment::STATUS_FAILED, 70, 'unanswered_questions'], $service->skillOutcome($questions(), $answers([10, 10, 10, 10, 10, 10, 10, null, null, null]), $settings));
    }
}
