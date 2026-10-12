<?php

namespace App\Services\Assessments;

use App\Models\Skill;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * يولّد Gemini أسئلة مفتوحة الإجابة لمهارة واحدة بالمستوى الذي اختاره الطالب، مع معايير تصحيح خاصة لكل سؤال.
 * الخادم لا يثق بالرد: يتحقق من أن كل سؤال لهذه المهارة وبهذا المستوى، ومن تركيبة الأسئلة العملية والكتابية
 * بالضبط حسب نوع كل سؤال (لا حسب نسبة يدّعيها النموذج)، ومن حدود النقاط، ويرفض الاختيار من متعدد والأسئلة
 * المطابقة حرفيًا لأسئلة سابقة للطالب. محاولات محدودة، وعند الفشل لا تُنشأ جلسة ولا يُخترع سؤال.
 */
class AssessmentQuestionGenerator
{
    public const PROMPT_VERSION = 'free-response-generation-v2';

    public const FORMATS = ['text', 'code'];

    public const CATEGORY_PRACTICAL = 'practical';

    public const CATEGORY_WRITTEN = 'written';

    /** أنواع الأسئلة العملية للمهارات البرمجية */
    public const PROGRAMMING_PRACTICAL_TYPES = ['coding', 'debugging', 'sql', 'implementation', 'code_analysis'];

    /** أنواع الأسئلة العملية للمهارات غير البرمجية (تصميم، اختبار يدوي، تحليل بيانات بالأدوات) */
    public const NON_PROGRAMMING_PRACTICAL_TYPES = ['scenario', 'design_task', 'case_study'];

    public const WRITTEN_TYPES = ['explanation', 'reasoning', 'technical_analysis'];

    /** أنواع يُجاب عنها في محرر الكود؛ البقية نص */
    public const CODE_TYPES = ['coding', 'debugging', 'sql', 'implementation'];

    /** أنواع تحتاج كودًا معطى في السؤال */
    public const CONTEXT_TYPES = ['debugging', 'code_analysis'];

    public const MAX_CRITERIA = 6;

    public const MAX_CRITERION_POINTS = 10;

    private const LEVEL_GUIDANCE = [
        SkillLevels::BEGINNER => 'beginner: أساسيات المهارة وصياغتها، مهام قصيرة بمفهوم واحد (كود حتى نحو 15 سطرًا)، أخطاء شائعة واضحة، وشرح مفاهيم أساسية بأمثلة بسيطة.',
        SkillLevels::INTERMEDIATE => 'intermediate: الجمع بين عدة مفاهيم في ميزة واقعية صغيرة، تصحيح أخطاء في كود متوسط الطول، التحقق من المدخلات والحالات الحدية، ومقارنة بديلين مع التبرير.',
        SkillLevels::ADVANCED => 'advanced: قرارات تصميم ومعمارية، الأداء والأمان والتزامن والحالات الحدية المعقدة، تصحيح أخطاء خفية، وتحليل مقايضات بعمق في سياق مشروع حقيقي.',
    ];

    public function __construct(private readonly AssessmentAiClient $client) {}

    /**
     * @param  array{key: string, label: string}  $specialization
     * @param  array{practical: int, written: int}  $mix
     * @param  array<int, string>  $previousPrompts  أسئلة سابقة للطالب في المهارة والمستوى نفسيهما
     * @return array{title: string, questions: array<int, array>}
     */
    public function generate(
        array $specialization,
        Skill $skill,
        string $level,
        bool $programming,
        bool $practicalSkill,
        array $mix,
        int $timeLimitMinutes,
        array $previousPrompts = [],
    ): array {
        try {
            $context = json_encode([
                'specialization' => $specialization,
                'skill' => [
                    'skill_id' => $skill->id,
                    'name' => $skill->name,
                    'programming' => $programming,
                    'requires_human_verification' => $practicalSkill,
                ],
                'level' => $level,
                'level_guidance' => self::LEVEL_GUIDANCE[$level],
                'question_mix' => $mix,
                'total_questions' => $mix['practical'] + $mix['written'],
                'practical_types' => $programming ? self::PROGRAMMING_PRACTICAL_TYPES : self::NON_PROGRAMMING_PRACTICAL_TYPES,
                'written_types' => self::WRITTEN_TYPES,
                'time_limit_minutes' => $timeLimitMinutes,
                'avoid_questions' => array_values($previousPrompts),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException) {
            throw AssessmentException::ai('ai_configuration_error', false, 'generation');
        }

        $previous = array_map(self::fingerprint(...), $previousPrompts);
        $attempts = max(1, (int) config('assessments.generation_attempts', 2));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $data = $this->client->json([
                'systemInstruction' => ['parts' => [['text' => $this->instructions()]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $context]]]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $this->schema($programming),
                ],
            ], 'generation');

            $assessment = $this->validate($data, $skill, $level, $programming, $mix, $previous);
            if ($assessment !== null) {
                return $assessment;
            }

            Log::warning('Gemini assessment generation failed validation.', [
                'provider' => 'gemini',
                'attempt' => $attempt,
                'max_attempts' => $attempts,
            ]);
        }

        throw AssessmentException::ai('ai_invalid_response', true, 'generation');
    }

    /**
     * يعيد null لأي رد لا يطابق القواعد بالكامل.
     *
     * @param  array{practical: int, written: int}  $mix
     * @param  array<int, string>  $previousFingerprints
     */
    public function validate(mixed $data, Skill $skill, string $level, bool $programming, array $mix, array $previousFingerprints = []): ?array
    {
        if (! is_array($data) || ! is_array($data['questions'] ?? null) || ! array_is_list($data['questions'])) {
            return null;
        }
        if (count($data['questions']) !== $mix['practical'] + $mix['written']) {
            return null;
        }

        $counts = [self::CATEGORY_PRACTICAL => 0, self::CATEGORY_WRITTEN => 0];
        $seen = [];
        $questions = [];
        foreach ($data['questions'] as $item) {
            $question = $this->question($item, (int) $skill->id, $level, $programming);
            if ($question === null) {
                return null;
            }
            // لا يتكرر السؤال داخل المحاولة، ولا يطابق حرفيًا سؤالًا سبق أن طُرح على الطالب
            $fingerprint = self::fingerprint($question['prompt']);
            if (in_array($fingerprint, $seen, true) || in_array($fingerprint, $previousFingerprints, true)) {
                return null;
            }
            $seen[] = $fingerprint;
            $counts[$question['category']]++;
            $questions[] = $question;
        }

        // التركيبة الفعلية من نوع كل سؤال، لا من أي نسبة يذكرها النموذج
        if ($counts[self::CATEGORY_PRACTICAL] !== $mix['practical'] || $counts[self::CATEGORY_WRITTEN] !== $mix['written']) {
            return null;
        }

        // معرّفات وترتيب يحددها الخادم
        $ordered = [];
        foreach ($questions as $index => $question) {
            $ordered[] = ['id' => 'q'.($index + 1), 'position' => $index + 1] + $question;
        }

        $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';

        return [
            'title' => mb_substr($title !== '' ? $title : 'تقييم '.$skill->name.' - '.SkillLevels::label($level), 0, 200),
            'questions' => $ordered,
        ];
    }

    /** بصمة نصية لمقارنة الأسئلة حرفيًا بعد توحيد المسافات وحالة الأحرف. */
    public static function fingerprint(string $prompt): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $prompt)));
    }

    public static function categoryOf(string $type, bool $programming): ?string
    {
        if (in_array($type, self::WRITTEN_TYPES, true)) {
            return self::CATEGORY_WRITTEN;
        }
        $practical = $programming ? self::PROGRAMMING_PRACTICAL_TYPES : self::NON_PROGRAMMING_PRACTICAL_TYPES;

        return in_array($type, $practical, true) ? self::CATEGORY_PRACTICAL : null;
    }

    private function question(mixed $item, int $skillId, string $level, bool $programming): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $type = is_string($item['type'] ?? null) ? $item['type'] : '';
        $category = self::categoryOf($type, $programming);
        $prompt = $this->text($item['prompt'] ?? null, 2000);
        $codeContext = is_string($item['code_context'] ?? null) ? rtrim($item['code_context']) : '';
        $language = $this->text($item['language'] ?? null, 30);
        $reference = $this->text($item['reference_answer'] ?? null, 4000);
        $minimum = $this->text($item['minimum_evidence'] ?? null, 1000);
        $concepts = $this->stringList($item['key_concepts'] ?? null, 10, 300);
        $mistakes = $this->stringList($item['common_mistakes'] ?? [], 8, 300);

        // كل سؤال مرتبط بالمهارة المقيَّمة وحدها وبالمستوى المطلوب بالضبط
        if (($item['skill_id'] ?? null) !== $skillId
            || $category === null
            || ($item['difficulty'] ?? null) !== $level
            || mb_strlen($prompt) < 15
            || mb_strlen($codeContext) > 4000
            || (in_array($type, self::CONTEXT_TYPES, true) && trim($codeContext) === '')
            || $reference === '' || $minimum === ''
            || $concepts === null || $concepts === [] || $mistakes === null
            || self::looksLikeMultipleChoice($prompt)) {
            return null;
        }

        $criteria = $this->criteria($item['criteria'] ?? null);
        if ($criteria === null) {
            return null;
        }

        return [
            'skill_id' => $skillId,
            'type' => $type,
            'category' => $category,
            // محرر الإجابة يحدده الخادم من النوع
            'format' => in_array($type, self::CODE_TYPES, true) ? 'code' : 'text',
            'difficulty' => $level,
            'prompt' => $prompt,
            'code_context' => $codeContext,
            'language' => $language,
            'max_points' => array_sum(array_column($criteria, 'points')),
            // خاص بالخادم: لا يُرسل للطالب أبدًا
            'rubric' => [
                'criteria' => $criteria,
                'reference_answer' => $reference,
                'key_concepts' => $concepts,
                'common_mistakes' => $mistakes,
                'minimum_evidence' => $minimum,
            ],
        ];
    }

    private function criteria(mixed $items): ?array
    {
        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > self::MAX_CRITERIA) {
            return null;
        }

        $criteria = [];
        foreach ($items as $index => $item) {
            $description = is_array($item) ? $this->text($item['description'] ?? null, 500) : '';
            $points = is_array($item) ? ($item['points'] ?? null) : null;
            if ($description === '' || ! is_int($points) || $points < 1 || $points > self::MAX_CRITERION_POINTS) {
                return null;
            }
            // معرّفات المعايير يحددها الخادم حتى لا تعتمد مطابقة التصحيح على نص يولده النموذج
            $criteria[] = ['id' => 'c'.($index + 1), 'description' => $description, 'points' => $points];
        }

        return $criteria;
    }

    /** صيغة اختيار من متعدد ممنوعة: خيارات مرقمة بالحروف أو طلب "اختر الإجابة". */
    public static function looksLikeMultipleChoice(string $prompt): bool
    {
        $optionLines = preg_match_all('/^\s*(?:\(?[a-dA-Dأبجد][\)\.\-:]|[a-dA-D]\s*\))\s+\S/mu', $prompt);
        if ($optionLines >= 2) {
            return true;
        }

        return preg_match('/اختر\s+(?:الإجابة|الخيار|من\s+بين)|(?:choose|select|pick)\s+(?:the\s+)?(?:correct|right|best)\s+(?:answer|option)|which\s+of\s+the\s+following|صح\s+أم\s+خطأ|true\s+or\s+false/iu', $prompt) === 1;
    }

    private function text(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }

    /** @return array<int, string>|null */
    private function stringList(mixed $items, int $maxItems, int $maxLength): ?array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            return null;
        }

        $list = [];
        foreach (array_slice($items, 0, $maxItems) as $item) {
            $text = $this->text($item, $maxLength);
            if ($text !== '') {
                $list[] = $text;
            }
        }

        return $list;
    }

    private function instructions(): string
    {
        return implode("\n", [
            'أنت مُعِدّ تقييمات تقنية في منصة GoTraniee لطلاب الجامعات والخريجين. أنشئ أسئلة مفتوحة الإجابة فقط يكتب الطالب إجابتها بنفسه نصًا أو كودًا.',
            'ممنوع تمامًا: الاختيار من متعدد، أو سرد خيارات مرقمة (أ/ب/ج أو A/B/C)، أو صح وخطأ، أو عبارات مثل "اختر الإجابة الصحيحة" أو "Which of the following".',
            'التقييم لمهارة واحدة هي skill، وكل سؤال يقيس هذه المهارة وحدها: ضع skill_id حرفيًا في كل سؤال. السياق هو المسار المهني specialization.',
            'المستوى المطلوب level يحدد الصعوبة حسب level_guidance؛ ضع difficulty = level في كل سؤال بالضبط. اجعل الأسئلة مناسبة لهذا المستوى فعلًا: لا أسهل منه ولا أصعب.',
            'أنشئ total_questions سؤالًا: question_mix.practical سؤالًا عمليًا نوعها type من practical_types، و question_mix.written سؤالًا كتابيًا نوعها من written_types. العدد لكل فئة إلزامي بالضبط.',
            'الأنواع العملية للمهارات البرمجية: coding كتابة كود، debugging إيجاد الخطأ في code_context وإصلاحه بكود، sql كتابة استعلام، implementation تنفيذ جزء من ميزة أو أوامر، code_analysis تحليل سلوك أو مشكلة في code_context وشرحها. للمهارات غير البرمجية: scenario موقف عملي يتطلب قرارًا وخطوات، design_task مهمة تصميم يصف الطالب حلها بالتفصيل، case_study تحليل حالة واقعية.',
            'الأنواع الكتابية: explanation شرح مفهوم بكلمات الطالب، reasoning تبرير اختيار أو مقارنة بدائل، technical_analysis تحليل تقني لمشكلة أو مقايضة.',
            'debugging و code_analysis يجب أن تحتوي code_context على الكود المطلوب تحليله. لا تضع الحل في code_context. language: لغة الكود مثل php أو sql أو dart، أو فارغ.',
            'المدة الإجمالية time_limit_minutes دقيقة لكل الأسئلة: اجعل كل سؤال قابلًا للإجابة بإجابة مركّزة ضمنها. اكتب نص السؤال بالعربية مع المصطلحات التقنية بالإنجليزية؛ قد يجيب الطالب بالعربية أو الإنجليزية.',
            'إذا كانت requires_human_verification = true (مثل أدوات التصميم) فهي مهارة لا تُثبت بالكتابة: اطرح سيناريوهات ومهام يصف فيها الطالب قراراته وخطواته، ولا تطلب ملفات أو تنفيذًا فعليًا بالأداة.',
            'avoid_questions أسئلة طُرحت على الطالب سابقًا في المهارة والمستوى نفسيهما: لا تكرر أيًا منها ولا تعِد صياغته بتغيير بسيط؛ اختر مواقف ومهام جديدة.',
            'لكل سؤال معايير تصحيح خاصة: criteria من 1 إلى 6 معايير، لكل منها description يوضح ما يستحق الدرجة الكاملة والجزئية و points عدد صحيح من 1 إلى 10؛ reference_answer نموذج لإجابة صحيحة (وليس الإجابة الوحيدة المقبولة)؛ key_concepts المفاهيم الأساسية؛ common_mistakes الأخطاء الشائعة أو الحرجة؛ minimum_evidence الحد الأدنى الذي يجب أن تُظهره الإجابة لتُعد مقبولة.',
            'لا تكشف الإجابة أو المعايير في نص السؤال. title عنوان قصير للتقييم. أعد JSON مطابقًا للمخطط فقط دون أي نص إضافي.',
        ]);
    }

    private function schema(bool $programming): array
    {
        $string = ['type' => 'STRING'];
        $strings = ['type' => 'ARRAY', 'items' => $string];
        $types = array_merge($programming ? self::PROGRAMMING_PRACTICAL_TYPES : self::NON_PROGRAMMING_PRACTICAL_TYPES, self::WRITTEN_TYPES);

        return [
            'type' => 'OBJECT',
            'properties' => [
                'title' => $string,
                'questions' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'skill_id' => ['type' => 'INTEGER'],
                            'type' => ['type' => 'STRING', 'enum' => $types],
                            'difficulty' => ['type' => 'STRING', 'enum' => SkillLevels::ALL],
                            'prompt' => $string,
                            'code_context' => $string,
                            'language' => $string,
                            'criteria' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => ['description' => $string, 'points' => ['type' => 'INTEGER']],
                                    'required' => ['description', 'points'],
                                ],
                            ],
                            'reference_answer' => $string,
                            'key_concepts' => $strings,
                            'common_mistakes' => $strings,
                            'minimum_evidence' => $string,
                        ],
                        'required' => ['skill_id', 'type', 'difficulty', 'prompt', 'criteria', 'reference_answer', 'key_concepts', 'minimum_evidence'],
                    ],
                ],
            ],
            'required' => ['title', 'questions'],
        ];
    }
}
