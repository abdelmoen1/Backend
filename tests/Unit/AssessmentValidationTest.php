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

    public function test_generated_questions_must_cover_every_selected_skill_exactly(): void
    {
        $generator = app(AssessmentQuestionGenerator::class);
        $skills = collect([new Skill(['name' => 'PHP']), new Skill(['name' => 'MySQL'])]);
        $skills[0]->id = 10;
        $skills[1]->id = 20;
        $valid = $this->generatedAssessment([10, 20]);

        $result = $generator->validate($valid, $skills, 2);
        $this->assertSame(['q1', 'q2', 'q3', 'q4'], array_column($result['questions'], 'id'));
        $this->assertSame([10, 10, 20, 20], array_column($result['questions'], 'skill_id'));
        $this->assertSame(10, $result['questions'][0]['max_points']);
        $this->assertArrayNotHasKey('options', $result['questions'][0]);

        // مهارة مكررة على حساب أخرى، أو عدد خاطئ، أو أسئلة مكررة
        $skewed = $valid;
        $skewed['questions'][3]['skill_id'] = 10;
        $this->assertNull($generator->validate($skewed, $skills, 2));
        $this->assertNull($generator->validate(['questions' => array_slice($valid['questions'], 0, 3)], $skills, 2));
        $duplicate = $valid;
        $duplicate['questions'][1]['prompt'] = $duplicate['questions'][0]['prompt'];
        $this->assertNull($generator->validate($duplicate, $skills, 2));

        foreach ([
            ['format' => 'multiple_choice'],
            ['skill_id' => '10'],
            ['criteria' => []],
            ['criteria' => [['description' => 'x', 'points' => 0]]],
            ['criteria' => array_fill(0, 7, ['description' => 'x', 'points' => 1])],
            ['minimum_evidence' => ''],
            ['key_concepts' => []],
            ['prompt' => 'قصير'],
        ] as $change) {
            $broken = $valid;
            $broken['questions'][0] = $change + $broken['questions'][0];
            $this->assertNull($generator->validate($broken, $skills, 2), json_encode($change));
        }
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
}
