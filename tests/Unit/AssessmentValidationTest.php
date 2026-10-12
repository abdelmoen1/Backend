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

    public function test_generated_questions_must_match_the_skill_level_and_exact_question_mix(): void
    {
        $generator = app(AssessmentQuestionGenerator::class);
        $skill = new Skill(['name' => 'PHP']);
        $skill->id = 10;
        $mix = ['practical' => 7, 'written' => 3];
        $valid = $this->generatedAssessment(10, 'intermediate');

        $result = $generator->validate($valid, $skill, 'intermediate', true, $mix);
        $this->assertSame(array_map(fn (int $n): string => "q{$n}", range(1, 10)), array_column($result['questions'], 'id'));
        $this->assertSame(array_fill(0, 10, 10), array_column($result['questions'], 'skill_id'));
        $this->assertSame([...array_fill(0, 7, 'practical'), ...array_fill(0, 3, 'written')], array_column($result['questions'], 'category'));
        $this->assertSame(10, $result['questions'][0]['max_points']);
        $this->assertArrayNotHasKey('options', $result['questions'][0]);

        // التركيبة من الإعدادات المحفوظة: 6/4 صالحة فقط إذا طُلبت كذلك
        $sixFour = $valid;
        $sixFour['questions'][0]['type'] = 'explanation';
        $this->assertNull($generator->validate($sixFour, $skill, 'intermediate', true, $mix));
        $this->assertNotNull($generator->validate($sixFour, $skill, 'intermediate', true, ['practical' => 6, 'written' => 4]));
        // مستوى آخر، أو نوع غير برمجي لمهارة برمجية، أو أسئلة كتابية فقط لمهارة غير برمجية
        $this->assertNull($generator->validate($valid, $skill, 'beginner', true, $mix));
        $this->assertNull($generator->validate($valid, $skill, 'intermediate', false, $mix));
        $this->assertNotNull($generator->validate($this->generatedAssessment(10, 'beginner', false), $skill, 'beginner', false, $mix));
        // عدد خاطئ، أو أسئلة مكررة داخل المحاولة، أو مطابقة حرفيًا لسؤال سابق
        $this->assertNull($generator->validate(['questions' => array_slice($valid['questions'], 0, 9)], $skill, 'intermediate', true, $mix));
        $duplicate = $valid;
        $duplicate['questions'][1]['prompt'] = $duplicate['questions'][0]['prompt'];
        $this->assertNull($generator->validate($duplicate, $skill, 'intermediate', true, $mix));
        $previous = [AssessmentQuestionGenerator::fingerprint("  {$valid['questions'][5]['prompt']} ")];
        $this->assertNull($generator->validate($valid, $skill, 'intermediate', true, $mix, $previous));

        foreach ([
            ['type' => 'multiple_choice'],
            ['skill_id' => '10'],
            ['skill_id' => 11],
            ['difficulty' => 'advanced'],
            ['criteria' => []],
            ['criteria' => [['description' => 'x', 'points' => 0]]],
            ['criteria' => array_fill(0, 7, ['description' => 'x', 'points' => 1])],
            ['minimum_evidence' => ''],
            ['key_concepts' => []],
            ['prompt' => 'قصير'],
        ] as $change) {
            $broken = $valid;
            $broken['questions'][0] = $change + $broken['questions'][0];
            $this->assertNull($generator->validate($broken, $skill, 'intermediate', true, $mix), json_encode($change));
        }

        // تصحيح الأخطاء وتحليل الكود يحتاجان كودًا معطى
        $noContext = $valid;
        $noContext['questions'][4]['code_context'] = "  \n";
        $this->assertNull($generator->validate($noContext, $skill, 'intermediate', true, $mix));
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

    public function test_grades_must_match_the_rubric_and_points_are_summed_by_laravel(): void
    {
        $grader = app(AssessmentGrader::class);
        $question = [
            'id' => 'q2',
            'max_points' => 10,
            'rubric' => ['criteria' => [['id' => 'c1', 'description' => 'a', 'points' => 6], ['id' => 'c2', 'description' => 'b', 'points' => 4]]],
        ];
        $grade = fn (array $changes = []): array => $changes + [
            'question_id' => 'q2',
            'status' => 'graded',
            'criteria' => [['id' => 'c2', 'awarded' => 4, 'justification' => 'x'], ['id' => 'c1', 'awarded' => 3, 'justification' => 'y']],
            'feedback' => 'جيد جزئيًا.',
            // مجموع يدعيه النموذج يُتجاهل
            'total' => 10,
            'passed' => true,
        ];

        $valid = $grader->validate($grade(), $question);
        $this->assertSame(7, $valid['awarded_points']);
        $this->assertSame(10, $valid['max_points']);
        $this->assertSame(['c1', 'c2'], array_column($valid['criteria'], 'id'));
        $this->assertArrayNotHasKey('passed', $valid);

        // إجابة غير كافية = صفر مهما كانت درجات المعايير
        $this->assertSame(0, $grader->validate($grade(['status' => 'insufficient_answer']), $question)['awarded_points']);

        foreach ([
            ['question_id' => 'q3'],
            ['status' => 'passed'],
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
