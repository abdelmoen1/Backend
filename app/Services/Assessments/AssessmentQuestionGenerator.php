<?php

namespace App\Services\Assessments;

use App\Models\Skill;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * يولد Gemini مهامًا برمجية عملية للجلسة كلها: لكل مهارة مختارة عدد من المهام بمستواها، يكتب الطالب في كل منها كودًا
 * (كتابة أو إكمال أو تعديل أو تصحيح). لكل مهمة حل مرجعي ومعايير تصحيح خاصة بالخادم.
 *
 * الخادم لا يثق بالرد: المهارة يجب أن تكون مختارة، والصعوبة والنوع يحددهما الخادم من الخطة، والمهمة التي ينقصها ما
 * يلزم للتصحيح (نص المهمة، الحل المرجعي، معايير بنقاط صحيحة) أو تشبه الاختيار من متعدد تُستبعد وحدها. المجموعة
 * تُرفض فقط إذا لم يبقَ العدد المطلوب أو قلّت مهام إحدى المهارات عن الحد الأدنى؛ عندها يُعاد التوليد مرة محدودة.
 */
class AssessmentQuestionGenerator
{
    public const PROMPT_VERSION = 'coding-task-generation-v4';

    public const CATEGORY_PRACTICAL = 'practical';

    /** أسئلة كتابية في جلسات الإصدارات السابقة فقط؛ لا تولد بعد الآن */
    public const CATEGORY_WRITTEN = 'written';

    public const TYPE_CODING = 'coding';

    public const MAX_CRITERIA = 6;

    public const MAX_CRITERION_POINTS = 10;

    /** حد مخرجات التوليد: يكفي 10 مهام موجزة ويمنع ردًا طويلًا يتجاوز مهلة الطلب */
    private const MAX_OUTPUT_TOKENS = 16384;

    public const LEVEL_GUIDANCE = [
        SkillLevels::BEGINNER => 'beginner: دالة أو مقطع قصير بمفهوم واحد (حتى نحو 15 سطرًا)، مدخلات بسيطة، أو إصلاح خطأ واضح.',
        SkillLevels::INTERMEDIATE => 'intermediate: ميزة صغيرة واقعية تجمع عدة مفاهيم، التحقق من المدخلات والحالات الحدية، أو تصحيح كود متوسط الطول.',
        SkillLevels::ADVANCED => 'advanced: كود بقرارات تصميم، أداء أو أمان أو تزامن، حالات حدية معقدة، أو تصحيح خطأ خفي.',
        SkillLevels::EXPERT => 'expert: مشكلة بمستوى الإنتاج: تحسين أداء، معالجة أعطال وحمل، أمان متقدم، أو إعادة هيكلة كود معقد مع الحفاظ على سلوكه.',
    ];

    /** @var array{reason: string}|null سبب رفض آخر مجموعة برمز ثابت للسجل، دون أي نص من الرد */
    private ?array $rejection = null;

    /** @var array<string, int> أسباب استبعاد المهام في آخر تحقق وعددها */
    private array $dropped = [];

    public function __construct(private readonly AssessmentAiClient $client) {}

    /**
     * يوزع المهام على المهارات بالتساوي قدر الإمكان، والمهارات الأولى في اختيار الطالب تأخذ الباقي.
     *
     * @return array<int, int> عدد المهام لكل مهارة بترتيب الاختيار
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
     * @param  array<int, array{skill: Skill, level: string, practical: int}>  $plan  بترتيب اختيار الطالب
     * @param  array<int, string>  $previousPrompts  مهام سابقة للطالب في المهارات والمستويات نفسها
     * @return array{title: string, questions: array<int, array>}
     */
    public function generate(array $specialization, array $plan, int $timeLimitMinutes, array $previousPrompts = [], int $minimumPerSkill = 1): array
    {
        $total = array_sum(array_column($plan, 'practical'));
        try {
            $context = json_encode([
                'specialization' => $specialization,
                'skills' => array_map(fn (array $item): array => [
                    'skill_id' => $item['skill']->id,
                    'name' => $item['skill']->name,
                    'level' => $item['level'],
                    'level_guidance' => self::LEVEL_GUIDANCE[$item['level']],
                    'tasks' => $item['practical'],
                ], $plan),
                'total_tasks' => $total,
                'time_limit_minutes' => $timeLimitMinutes,
                'avoid_tasks' => array_values($previousPrompts),
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
                    'responseSchema' => $this->schema(),
                    'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
                ],
            ], 'generation');

            $assessment = $this->validate($data, $plan, $previous, $minimumPerSkill);
            if ($assessment !== null) {
                if ($this->dropped !== []) {
                    Log::info('Gemini assessment generation accepted after dropping unusable tasks.', ['provider' => 'gemini', 'attempt' => $attempt, 'dropped' => $this->dropped]);
                }

                return $assessment;
            }

            Log::warning('Gemini assessment generation failed validation.', [
                'provider' => 'gemini',
                'attempt' => $attempt,
                'max_attempts' => $attempts,
                ...($this->rejection ?? ['reason' => 'unknown']),
                'dropped' => $this->dropped,
            ]);
        }

        throw AssessmentException::ai('ai_invalid_response', true, 'generation');
    }

    /**
     * يعيد المجموعة جاهزة للحفظ، أو null إن لم تكفِ المهام الصالحة للخطة. المهام الزائدة عن الخطة تُترك.
     *
     * @param  array<int, array{skill: Skill, level: string, practical: int}>  $plan
     * @param  array<int, string>  $previousFingerprints
     */
    public function validate(mixed $data, array $plan, array $previousFingerprints = [], int $minimumPerSkill = 1): ?array
    {
        $this->rejection = null;
        $this->dropped = [];
        if (! is_array($data) || ! is_array($data['questions'] ?? null) || ! array_is_list($data['questions'])) {
            return $this->reject('not_a_question_list');
        }

        $bySkill = [];
        foreach ($plan as $item) {
            $bySkill[(int) $item['skill']->id] = ['plan' => $item, 'questions' => []];
        }

        $seen = [];
        foreach ($data['questions'] as $item) {
            [$question, $reason] = $this->question($item, $bySkill);
            if ($question !== null) {
                // لا تتكرر المهمة داخل الجلسة، ولا تطابق حرفيًا مهمة سبق أن طُرحت على الطالب
                $fingerprint = self::fingerprint($question['prompt']);
                $reason = match (true) {
                    in_array($fingerprint, $seen, true) => 'duplicate_prompt',
                    in_array($fingerprint, $previousFingerprints, true) => 'repeats_previous_question',
                    default => null,
                };
                $seen[] = $fingerprint;
            }
            if ($reason !== null) {
                $this->dropped[$reason] = ($this->dropped[$reason] ?? 0) + 1;

                continue;
            }
            $bySkill[$question['skill_id']]['questions'][] = $question;
        }

        // العدد المخطط لكل مهارة أولًا، ثم يُكمل النقص من مهام صالحة زائدة لمهارة أخرى
        $take = [];
        foreach ($bySkill as $skillId => $entry) {
            $take[$skillId] = min($entry['plan']['practical'], count($entry['questions']));
        }
        $short = array_sum(array_column($plan, 'practical')) - array_sum($take);
        foreach ($bySkill as $skillId => $entry) {
            $extra = min($short, count($entry['questions']) - $take[$skillId]);
            $take[$skillId] += $extra;
            $short -= $extra;
        }
        if ($short > 0) {
            return $this->reject('question_count');
        }
        foreach ($bySkill as $skillId => $entry) {
            if ($take[$skillId] < min($minimumPerSkill, $entry['plan']['practical'])) {
                return $this->reject('skill_coverage');
            }
        }

        // ترتيب ثابت: مهام كل مهارة بترتيب اختيار الطالب، ومعرّفات يحددها الخادم
        $ordered = [];
        foreach ($bySkill as $skillId => $entry) {
            foreach (array_slice($entry['questions'], 0, $take[$skillId]) as $question) {
                $position = count($ordered) + 1;
                $ordered[] = ['id' => "q{$position}", 'position' => $position] + $question;
            }
        }

        $title = $this->text($data['title'] ?? null, 200);
        $fallback = 'تقييم '.implode('، ', array_map(fn (array $item): string => $item['skill']->name, $plan));

        return [
            'title' => $title !== '' ? $title : $fallback,
            'questions' => $ordered,
        ];
    }

    /** بصمة نصية لمقارنة المهام حرفيًا بعد توحيد المسافات وحالة الأحرف. */
    public static function fingerprint(string $prompt): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $prompt)));
    }

    /** صيغة اختيار من متعدد أو صح وخطأ ممنوعة: خيارات مرقمة بالحروف أو طلب "اختر الإجابة". */
    public static function looksLikeMultipleChoice(string $prompt): bool
    {
        $optionLines = preg_match_all('/^\s*(?:\(?[a-dA-Dأبجد][\)\.\-:]|[a-dA-D]\s*\))\s+\S/mu', $prompt);
        if ($optionLines >= 2) {
            return true;
        }

        return preg_match('/اختر\s+(?:الإجابة|الخيار|من\s+بين)|(?:choose|select|pick)\s+(?:the\s+)?(?:correct|right|best)\s+(?:answer|option)|which\s+of\s+the\s+following|صح\s+أم\s+خطأ|true\s+or\s+false/iu', $prompt) === 1;
    }

    /**
     * مهمة واحدة جاهزة للحفظ، أو سبب استبعادها.
     *
     * @param  array<int, array{plan: array, questions: array}>  $bySkill
     * @return array{0: ?array, 1: ?string}
     */
    private function question(mixed $item, array $bySkill): array
    {
        if (! is_array($item)) {
            return [null, 'not_an_object'];
        }

        $skillId = is_numeric($item['skill_id'] ?? null) ? (int) $item['skill_id'] : null;
        $entry = $skillId !== null ? ($bySkill[$skillId] ?? null) : null;
        $task = $this->text($item['task'] ?? null, 3000);
        $starter = is_string($item['starter_code'] ?? null) ? rtrim($item['starter_code']) : '';
        $solution = is_string($item['reference_solution'] ?? null) ? trim($item['reference_solution']) : '';
        $criteria = $this->criteria($item['rubric'] ?? null, $skillId);

        $reason = match (true) {
            $entry === null => 'unknown_skill',
            mb_strlen($task) < 20 => 'prompt_too_short',
            self::looksLikeMultipleChoice($task) => 'multiple_choice',
            mb_strlen($starter) > 4000 => 'code_context_too_long',
            $solution === '' || mb_strlen($solution) > 8000 => 'missing_reference_solution',
            $criteria === [] => 'invalid_criterion',
            default => null,
        };
        if ($reason !== null) {
            return [null, $reason];
        }

        $language = $this->text($item['language'] ?? null, 30);

        return [[
            'skill_id' => $skillId,
            'skill_ids' => [$skillId],
            'type' => self::TYPE_CODING,
            'category' => self::CATEGORY_PRACTICAL,
            'format' => 'code',
            // الصعوبة من خطة الخادم لا من رد النموذج
            'difficulty' => $entry['plan']['level'],
            'title' => $this->text($item['title'] ?? null, 120) ?: mb_substr($task, 0, 80),
            'prompt' => $task,
            'language' => $language !== '' ? $language : mb_strtolower($entry['plan']['skill']->name),
            'starter_code' => $starter,
            'requirements' => $this->stringList($item['requirements'] ?? [], 8, 300),
            'examples' => $this->examples($item['examples'] ?? []),
            'max_points' => array_sum(array_column($criteria, 'points')),
            // خاص بالخادم: لا يُرسل للطالب أبدًا
            'rubric' => [
                'criteria' => $criteria,
                'reference_solution' => $solution,
            ],
        ], null];
    }

    /**
     * معايير التصحيح الصالحة فقط (وصف ونقاط صحيحة 1..10، حتى MAX_CRITERIA)؛ مصفوفة فارغة إن لم يصلح أي معيار.
     *
     * @return array<int, array{id: string, skill_id: ?int, description: string, points: int}>
     */
    private function criteria(mixed $items, ?int $skillId): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            return [];
        }

        $criteria = [];
        foreach ($items as $item) {
            $description = is_array($item) ? $this->text($item['description'] ?? null, 500) : '';
            $points = is_array($item) ? ($item['points'] ?? null) : null;
            if ($description === '' || ! is_numeric($points) || (int) $points != $points || $points < 1 || $points > self::MAX_CRITERION_POINTS) {
                continue;
            }
            // معرّفات المعايير يحددها الخادم حتى لا تعتمد مطابقة التصحيح على نص يولده النموذج
            $criteria[] = ['id' => 'c'.(count($criteria) + 1), 'skill_id' => $skillId, 'description' => $description, 'points' => (int) $points];
            if (count($criteria) === self::MAX_CRITERIA) {
                break;
            }
        }

        return $criteria;
    }

    /** @return array<int, array{input: string, output: string}> أمثلة تظهر للطالب ضمن نص المهمة */
    private function examples(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $examples = [];
        foreach (array_slice(array_values($items), 0, 3) as $item) {
            $input = is_array($item) ? $this->text($item['input'] ?? null, 500) : '';
            $output = is_array($item) ? $this->text($item['output'] ?? null, 500) : '';
            if ($input !== '' || $output !== '') {
                $examples[] = ['input' => $input, 'output' => $output];
            }
        }

        return $examples;
    }

    private function reject(string $reason): null
    {
        $this->rejection = ['reason' => $reason];

        return null;
    }

    private function text(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }

    /** @return array<int, string> */
    private function stringList(mixed $items, int $maxItems, int $maxLength): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): string => $this->text($item, $maxLength),
            array_slice(array_values($items), 0, $maxItems)
        ), fn (string $item): bool => $item !== ''));
    }

    private function instructions(): string
    {
        return implode("\n", [
            'أنت مُعِدّ تقييمات برمجية في منصة GoTraniee لطلاب الجامعات والخريجين. أنشئ مهامًا برمجية عملية فقط: كل مهمة تطلب من الطالب أن يكتب كودًا أو يكمله أو يعدّله أو يصحح خطأ فيه.',
            'ممنوع: الاختيار من متعدد، صح وخطأ، المقالات، وأي سؤال يمكن الإجابة عنه بشرح مفهوم دون كتابة كود.',
            'لكل مهارة في skills أنشئ tasks مهمة بالضبط، بمستوى level حسب level_guidance، وضع skill_id حرفيًا. المجموع total_tasks مهمة.',
            'المدة time_limit_minutes دقيقة لكل المهام: اجعل كل مهمة صغيرة ومحددة يمكن حلها بكود قصير خلالها.',
            'لكل مهمة: title عنوان قصير؛ language لغة الكود أو الصياغة (مثل php أو javascript أو sql أو dart)؛ task نص المهمة بالعربية مع المصطلحات التقنية بالإنجليزية، يوضح ما يجب تنفيذه بالضبط؛ starter_code كود بداية أو الكود المطلوب تصحيحه عند الحاجة وإلا فارغ؛ requirements السلوك المطلوب والقيود في نقاط قصيرة؛ examples حتى 3 أمثلة input و output عند الفائدة.',
            'reference_solution حل صحيح موجز (واحد من الحلول الممكنة). rubric من 2 إلى 4 معايير لكل منها description لما يستحق النقاط و points عدد صحيح من 1 إلى 10. لا تضع الحل أو المعايير في task أو starter_code.',
            'avoid_tasks مهام طُرحت على الطالب سابقًا: لا تكرر أيًا منها. title للتقييم كله عنوان قصير. أعد JSON مطابقًا للمخطط فقط.',
        ]);
    }

    private function schema(): array
    {
        $string = ['type' => 'STRING'];

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
                            'title' => $string,
                            'language' => $string,
                            'task' => $string,
                            'starter_code' => $string,
                            'requirements' => ['type' => 'ARRAY', 'items' => $string],
                            'examples' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => ['input' => $string, 'output' => $string],
                                ],
                            ],
                            'reference_solution' => $string,
                            'rubric' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'description' => $string,
                                        'points' => ['type' => 'INTEGER'],
                                    ],
                                    'required' => ['description', 'points'],
                                ],
                            ],
                        ],
                        'required' => ['skill_id', 'title', 'language', 'task', 'reference_solution', 'rubric'],
                    ],
                ],
            ],
            'required' => ['questions'],
        ];
    }
}
