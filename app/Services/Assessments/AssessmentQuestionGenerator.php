<?php

namespace App\Services\Assessments;

use App\Models\Skill;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * يولّد Gemini أسئلة جلسة تقييم واحدة تغطي حتى 3 مهارات، لكل مهارة مستواها الذي اختاره الطالب، مع معايير تصحيح
 * خاصة لكل سؤال. الخادم يحدد خطة التوزيع (عدد الأسئلة العملية لكل مهارة) ولا يثق بالرد: يتحقق من أن كل سؤال
 * عملي لمهارة مختارة وبمستواها وبنوع يناسبها، ومن العدد لكل مهارة بالضبط، ومن أن معايير السؤال الكتابي (المقال)
 * تحدد كل منها المهارة التي تقيّمها، ومن حدود النقاط، ويرفض الاختيار من متعدد والأسئلة المطابقة حرفيًا لأسئلة
 * سابقة للطالب. محاولات محدودة، وعند الفشل لا تُنشأ جلسة ولا يُخترع سؤال.
 */
class AssessmentQuestionGenerator
{
    public const PROMPT_VERSION = 'free-response-generation-v3';

    public const FORMATS = ['text', 'code'];

    public const CATEGORY_PRACTICAL = 'practical';

    public const CATEGORY_WRITTEN = 'written';

    /** أنواع الأسئلة العملية للمهارات البرمجية */
    public const PROGRAMMING_PRACTICAL_TYPES = ['coding', 'debugging', 'sql', 'implementation', 'code_analysis'];

    /** أنواع الأسئلة العملية للمهارات غير البرمجية (تصميم، اختبار يدوي، تحليل بيانات بالأدوات) */
    public const NON_PROGRAMMING_PRACTICAL_TYPES = ['scenario', 'design_task', 'case_study'];

    /** essay هو السؤال الكتابي للجلسة متعددة المهارات؛ البقية من جلسات الإصدار السابق */
    public const WRITTEN_TYPES = ['essay', 'explanation', 'reasoning', 'technical_analysis'];

    /** أنواع يُجاب عنها في محرر الكود؛ البقية نص */
    public const CODE_TYPES = ['coding', 'debugging', 'sql', 'implementation'];

    /** أنواع تحتاج كودًا معطى في السؤال */
    public const CONTEXT_TYPES = ['debugging', 'code_analysis'];

    public const MAX_CRITERIA = 6;

    public const MAX_CRITERION_POINTS = 10;

    public const LEVEL_GUIDANCE = [
        SkillLevels::BEGINNER => 'beginner: أساسيات المهارة وصياغتها، مهام قصيرة بمفهوم واحد (كود حتى نحو 15 سطرًا)، أخطاء شائعة واضحة، وشرح مفاهيم أساسية بأمثلة بسيطة.',
        SkillLevels::INTERMEDIATE => 'intermediate: الجمع بين عدة مفاهيم في ميزة واقعية صغيرة، تصحيح أخطاء في كود متوسط الطول، التحقق من المدخلات والحالات الحدية، ومقارنة بديلين مع التبرير.',
        SkillLevels::ADVANCED => 'advanced: قرارات تصميم ومعمارية، الأداء والأمان والتزامن والحالات الحدية المعقدة، تصحيح أخطاء خفية، وتحليل مقايضات بعمق في سياق مشروع حقيقي.',
        SkillLevels::EXPERT => 'expert: مشكلات مفتوحة على مستوى الإنتاج: تصميم أنظمة تتحمل الحمل والأعطال، تحسين أداء مقيس، أمان متقدم، تشخيص أعطال معقدة من أعراض جزئية، ومقايضات يبررها خبير مع حدود كل حل.',
    ];

    public function __construct(private readonly AssessmentAiClient $client) {}

    /**
     * يوزع الأسئلة العملية على المهارات بالتساوي قدر الإمكان، والمهارات الأولى في اختيار الطالب تأخذ الباقي.
     *
     * @return array<int, int> عدد الأسئلة العملية لكل مهارة بترتيب الاختيار
     */
    public static function practicalDistribution(int $skills, int $practical): array
    {
        if ($skills < 1) {
            return [];
        }

        $base = intdiv($practical, $skills);
        $extra = $practical % $skills;

        return array_map(fn (int $index): int => $base + ($index < $extra ? 1 : 0), range(0, $skills - 1));
    }

    /**
     * @param  array{key: string, label: string}  $specialization
     * @param  array<int, array{skill: Skill, level: string, programming: bool, human_verification: bool, practical: int}>  $plan  بترتيب اختيار الطالب
     * @param  array<int, string>  $previousPrompts  أسئلة سابقة للطالب في المهارات والمستويات نفسها
     * @return array{title: string, questions: array<int, array>}
     */
    public function generate(array $specialization, array $plan, int $written, int $timeLimitMinutes, array $previousPrompts = []): array
    {
        try {
            $context = json_encode([
                'specialization' => $specialization,
                'skills' => array_map(fn (array $item): array => [
                    'skill_id' => $item['skill']->id,
                    'name' => $item['skill']->name,
                    'level' => $item['level'],
                    'level_guidance' => self::LEVEL_GUIDANCE[$item['level']],
                    'programming' => $item['programming'],
                    'requires_human_verification' => $item['human_verification'],
                    'practical_questions' => $item['practical'],
                    'practical_types' => $item['programming'] ? self::PROGRAMMING_PRACTICAL_TYPES : self::NON_PROGRAMMING_PRACTICAL_TYPES,
                ], $plan),
                'written_questions' => $written,
                'written_type' => 'essay',
                'total_questions' => array_sum(array_column($plan, 'practical')) + $written,
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
                    'responseSchema' => $this->schema($plan),
                ],
            ], 'generation');

            $assessment = $this->validate($data, $plan, $written, $previous);
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
     * يعيد null لأي رد لا يطابق الخطة والقواعد بالكامل.
     *
     * @param  array<int, array{skill: Skill, level: string, programming: bool, human_verification: bool, practical: int}>  $plan
     * @param  array<int, string>  $previousFingerprints
     */
    public function validate(mixed $data, array $plan, int $written, array $previousFingerprints = []): ?array
    {
        if (! is_array($data) || ! is_array($data['questions'] ?? null) || ! array_is_list($data['questions'])) {
            return null;
        }
        if (count($data['questions']) !== array_sum(array_column($plan, 'practical')) + $written) {
            return null;
        }

        $bySkill = [];
        foreach ($plan as $item) {
            $bySkill[(int) $item['skill']->id] = ['plan' => $item, 'questions' => []];
        }
        $writtenQuestions = [];
        $seen = [];

        foreach ($data['questions'] as $item) {
            $question = $this->question($item, $bySkill);
            if ($question === null) {
                return null;
            }
            // لا يتكرر السؤال داخل الجلسة، ولا يطابق حرفيًا سؤالًا سبق أن طُرح على الطالب
            $fingerprint = self::fingerprint($question['prompt']);
            if (in_array($fingerprint, $seen, true) || in_array($fingerprint, $previousFingerprints, true)) {
                return null;
            }
            $seen[] = $fingerprint;

            if ($question['category'] === self::CATEGORY_WRITTEN) {
                $writtenQuestions[] = $question;
            } else {
                $bySkill[$question['skill_id']]['questions'][] = $question;
            }
        }

        // التغطية الفعلية: لكل مهارة عدد أسئلتها العملية المخطط بالضبط، والسؤال الكتابي بالعدد المطلوب
        if (count($writtenQuestions) !== $written) {
            return null;
        }
        foreach ($bySkill as $entry) {
            if (count($entry['questions']) !== $entry['plan']['practical']) {
                return null;
            }
        }

        // ترتيب ثابت: أسئلة كل مهارة بترتيب الاختيار ثم السؤال الكتابي، ومعرّفات يحددها الخادم
        $ordered = [];
        foreach ([...array_merge(...array_column(array_values($bySkill), 'questions')), ...$writtenQuestions] as $question) {
            $position = count($ordered) + 1;
            $ordered[] = ['id' => "q{$position}", 'position' => $position] + $question;
        }

        $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';
        $fallback = 'تقييم '.implode('، ', array_map(fn (array $item): string => $item['skill']->name, $plan));

        return [
            'title' => mb_substr($title !== '' ? $title : $fallback, 0, 200),
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

    /** @param array<int, array{plan: array, questions: array}> $bySkill */
    private function question(mixed $item, array $bySkill): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $type = is_string($item['type'] ?? null) ? $item['type'] : '';
        $prompt = $this->text($item['prompt'] ?? null, 2000);
        $codeContext = is_string($item['code_context'] ?? null) ? rtrim($item['code_context']) : '';
        $language = $this->text($item['language'] ?? null, 30);
        $reference = $this->text($item['reference_answer'] ?? null, 4000);
        $minimum = $this->text($item['minimum_evidence'] ?? null, 1000);
        $concepts = $this->stringList($item['key_concepts'] ?? null, 10, 300);
        $mistakes = $this->stringList($item['common_mistakes'] ?? [], 8, 300);

        if (mb_strlen($prompt) < 15
            || mb_strlen($codeContext) > 4000
            || $reference === '' || $minimum === ''
            || $concepts === null || $concepts === [] || $mistakes === null
            || self::looksLikeMultipleChoice($prompt)) {
            return null;
        }

        $selected = array_keys($bySkill);
        if (in_array($type, self::WRITTEN_TYPES, true)) {
            // المقال: كل معيار يحدد المهارة التي يقيّمها، ولا تُمنح نقاط مهارة لا يقيّمها أي معيار
            $criteria = $this->criteria($item['criteria'] ?? null, $selected, null);
            if ($criteria === null) {
                return null;
            }
            $skillIds = array_values(array_intersect($selected, array_unique(array_column($criteria, 'skill_id'))));

            return $this->build(null, $skillIds, $type, self::CATEGORY_WRITTEN, 'text', null, $prompt, $codeContext, $language, $criteria, $reference, $concepts, $mistakes, $minimum);
        }

        // سؤال عملي: لمهارة مختارة واحدة، بمستواها بالضبط، وبنوع يناسبها (كود للبرمجية، مهام لغيرها)
        $skillId = $item['skill_id'] ?? null;
        $entry = is_int($skillId) ? ($bySkill[$skillId] ?? null) : null;
        if ($entry === null
            || self::categoryOf($type, $entry['plan']['programming']) !== self::CATEGORY_PRACTICAL
            || ($item['difficulty'] ?? null) !== $entry['plan']['level']
            || (in_array($type, self::CONTEXT_TYPES, true) && trim($codeContext) === '')) {
            return null;
        }
        $criteria = $this->criteria($item['criteria'] ?? null, $selected, $skillId);
        if ($criteria === null) {
            return null;
        }

        return $this->build(
            $skillId,
            [$skillId],
            $type,
            self::CATEGORY_PRACTICAL,
            in_array($type, self::CODE_TYPES, true) ? 'code' : 'text',
            $entry['plan']['level'],
            $prompt,
            $codeContext,
            $language,
            $criteria,
            $reference,
            $concepts,
            $mistakes,
            $minimum
        );
    }

    private function build(?int $skillId, array $skillIds, string $type, string $category, string $format, ?string $difficulty, string $prompt, string $codeContext, string $language, array $criteria, string $reference, array $concepts, array $mistakes, string $minimum): array
    {
        return [
            // null للمقال: مهاراته في skill_ids ومعاييره
            'skill_id' => $skillId,
            'skill_ids' => $skillIds,
            'type' => $type,
            'category' => $category,
            'format' => $format,
            'difficulty' => $difficulty,
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

    /**
     * @param  array<int, int>  $selected
     * @param  ?int  $questionSkill  مهارة السؤال العملي (كل معاييره لها)، أو null للمقال (كل معيار يحدد مهارته)
     */
    private function criteria(mixed $items, array $selected, ?int $questionSkill): ?array
    {
        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > self::MAX_CRITERIA) {
            return null;
        }

        $criteria = [];
        foreach ($items as $index => $item) {
            $description = is_array($item) ? $this->text($item['description'] ?? null, 500) : '';
            $points = is_array($item) ? ($item['points'] ?? null) : null;
            $skillId = is_array($item) ? ($item['skill_id'] ?? null) : null;
            if ($description === '' || ! is_int($points) || $points < 1 || $points > self::MAX_CRITERION_POINTS) {
                return null;
            }
            if ($questionSkill !== null) {
                // معيار سؤال عملي لا يقيّم مهارة أخرى
                if ($skillId !== null && $skillId !== $questionSkill) {
                    return null;
                }
                $skillId = $questionSkill;
            } elseif (! is_int($skillId) || ! in_array($skillId, $selected, true)) {
                return null;
            }
            // معرّفات المعايير يحددها الخادم حتى لا تعتمد مطابقة التصحيح على نص يولده النموذج
            $criteria[] = ['id' => 'c'.($index + 1), 'skill_id' => $skillId, 'description' => $description, 'points' => $points];
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
            'التقييم جلسة واحدة للمهارات في skills، ولكل مهارة مستواها level الخاص بها حسب level_guidance. السياق هو المسار المهني specialization.',
            'لكل مهارة أنشئ practical_questions سؤالًا عمليًا بالضبط: ضع skill_id حرفيًا، و difficulty = level تلك المهارة بالضبط، و type من practical_types الخاصة بها. السؤال العملي يقيس مهارته وحدها بمستواها، لا أسهل ولا أصعب.',
            'الأنواع العملية للمهارات البرمجية: coding كتابة كود، debugging إيجاد الخطأ في code_context وإصلاحه بكود، sql كتابة استعلام، implementation تنفيذ جزء من ميزة أو أوامر، code_analysis تحليل سلوك أو مشكلة في code_context وشرحها. للمهارات غير البرمجية (programming = false): scenario موقف عملي يتطلب قرارًا وخطوات، design_task مهمة تصميم يصف الطالب حلها بالتفصيل، case_study تحليل حالة واقعية؛ لا تطلب منها كتابة كود.',
            'ثم أنشئ written_questions سؤالًا كتابيًا واحدًا type = essay: مقال قصير يربط المهارات المختارة بموقف واحد حيث يكون ذلك منطقيًا (مثل قرار تصميم يشمل أكثر من مهارة). اترك skill_id و difficulty فارغين للمقال. في معايير المقال ضع لكل معيار skill_id للمهارة التي يقيّمها فعلًا؛ لا تضع معيارًا لمهارة لا يتيح المقال للطالب إظهارها.',
            'debugging و code_analysis يجب أن تحتوي code_context على الكود المطلوب تحليله. لا تضع الحل في code_context. language: لغة الكود مثل php أو sql أو dart، أو فارغ.',
            'المدة الإجمالية time_limit_minutes دقيقة لكل الأسئلة: اجعل كل سؤال قابلًا للإجابة بإجابة مركّزة ضمنها. اكتب نص السؤال بالعربية مع المصطلحات التقنية بالإنجليزية؛ قد يجيب الطالب بالعربية أو الإنجليزية.',
            'إذا كانت requires_human_verification = true (مثل أدوات التصميم) فهي مهارة لا تُثبت بالكتابة: اطرح سيناريوهات ومهام يصف فيها الطالب قراراته وخطواته، ولا تطلب ملفات أو تنفيذًا فعليًا بالأداة.',
            'avoid_questions أسئلة طُرحت على الطالب سابقًا: لا تكرر أيًا منها ولا تعِد صياغته بتغيير بسيط؛ اختر مواقف ومهام جديدة.',
            'لكل سؤال معايير تصحيح خاصة: criteria من 1 إلى 6 معايير، لكل منها description يوضح ما يستحق الدرجة الكاملة والجزئية و points عدد صحيح من 1 إلى 10 و skill_id؛ reference_answer نموذج لإجابة صحيحة (وليس الإجابة الوحيدة المقبولة)؛ key_concepts المفاهيم الأساسية؛ common_mistakes الأخطاء الشائعة أو الحرجة؛ minimum_evidence الحد الأدنى الذي يجب أن تُظهره الإجابة لتُعد مقبولة.',
            'لا تكشف الإجابة أو المعايير في نص السؤال. title عنوان قصير للتقييم. أعد JSON مطابقًا للمخطط فقط دون أي نص إضافي.',
        ]);
    }

    private function schema(array $plan): array
    {
        $string = ['type' => 'STRING'];
        $strings = ['type' => 'ARRAY', 'items' => $string];
        $anyProgramming = in_array(true, array_column($plan, 'programming'), true);
        $anyOther = in_array(false, array_column($plan, 'programming'), true);
        $types = array_values(array_unique(array_merge(
            $anyProgramming ? self::PROGRAMMING_PRACTICAL_TYPES : [],
            $anyOther ? self::NON_PROGRAMMING_PRACTICAL_TYPES : [],
            ['essay'],
        )));

        return [
            'type' => 'OBJECT',
            'properties' => [
                'title' => $string,
                'questions' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'skill_id' => ['type' => 'INTEGER', 'nullable' => true],
                            'type' => ['type' => 'STRING', 'enum' => $types],
                            'difficulty' => ['type' => 'STRING', 'enum' => SkillLevels::ALL, 'nullable' => true],
                            'prompt' => $string,
                            'code_context' => $string,
                            'language' => $string,
                            'criteria' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'skill_id' => ['type' => 'INTEGER'],
                                        'description' => $string,
                                        'points' => ['type' => 'INTEGER'],
                                    ],
                                    'required' => ['skill_id', 'description', 'points'],
                                ],
                            ],
                            'reference_answer' => $string,
                            'key_concepts' => $strings,
                            'common_mistakes' => $strings,
                            'minimum_evidence' => $string,
                        ],
                        'required' => ['type', 'prompt', 'criteria', 'reference_answer', 'key_concepts', 'minimum_evidence'],
                    ],
                ],
            ],
            'required' => ['title', 'questions'],
        ];
    }
}
