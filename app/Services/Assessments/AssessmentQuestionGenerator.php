<?php

namespace App\Services\Assessments;

use App\Models\Skill;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * يولّد Gemini أسئلة مفتوحة الإجابة (شرح أو كود) لمهارات الجلسة مع معايير تصحيح خاصة لكل سؤال.
 * الخادم لا يثق بالرد: يتحقق من البنية، ومن أن كل سؤال لمهارة مختارة، ومن أن لكل مهارة العدد المطلوب
 * من الأسئلة بالضبط، ومن حدود النقاط، ويرفض أي سؤال بصيغة اختيار من متعدد. محاولات محدودة، وعند
 * الفشل لا تُنشأ جلسة ولا يُخترع سؤال.
 */
class AssessmentQuestionGenerator
{
    public const PROMPT_VERSION = 'free-response-generation-v1';

    public const FORMATS = ['text', 'code'];

    public const DIFFICULTIES = ['beginner', 'intermediate', 'advanced'];

    public const MAX_CRITERIA = 6;

    public const MAX_CRITERION_POINTS = 10;

    public function __construct(private readonly AssessmentAiClient $client) {}

    /**
     * @param  array{key: string, label: string}  $specialization
     * @param  Collection<int, Skill>  $skills  بترتيب اختيار الطالب
     * @param  array<int, int>  $practicalSkillIds
     * @return array{title: string, questions: array<int, array>}
     */
    public function generate(array $specialization, Collection $skills, array $practicalSkillIds): array
    {
        $perSkill = max(1, (int) config('assessments.questions_per_skill', 2));

        try {
            $context = json_encode([
                'specialization' => $specialization,
                'skills' => $skills->map(fn (Skill $skill): array => [
                    'skill_id' => $skill->id,
                    'name' => $skill->name,
                    'practical' => in_array($skill->id, $practicalSkillIds, true),
                ])->values()->all(),
                'questions_per_skill' => $perSkill,
                'total_questions' => $perSkill * $skills->count(),
                'difficulty' => (string) config('assessments.difficulty', 'intermediate'),
                'minutes_per_question' => (int) config('assessments.minutes_per_question', 10),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw AssessmentException::ai('ai_configuration_error', false, 'generation');
        }

        $attempts = max(1, (int) config('assessments.generation_attempts', 2));
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $data = $this->client->json([
                'systemInstruction' => ['parts' => [['text' => $this->instructions()]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $context]]]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $this->schema(),
                ],
            ], 'generation');

            $assessment = $this->validate($data, $skills, $perSkill);
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
     * @param  Collection<int, Skill>  $skills
     */
    public function validate(mixed $data, Collection $skills, int $perSkill): ?array
    {
        if (! is_array($data) || ! is_array($data['questions'] ?? null) || ! array_is_list($data['questions'])) {
            return null;
        }
        if (count($data['questions']) !== $perSkill * $skills->count()) {
            return null;
        }

        $skillIds = $skills->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $bySkill = array_fill_keys($skillIds, []);
        $prompts = [];

        foreach ($data['questions'] as $item) {
            $question = $this->question($item, $skillIds);
            if ($question === null) {
                return null;
            }
            // لا يتكرر السؤال نفسه داخل الجلسة
            $fingerprint = mb_strtolower(preg_replace('/\s+/u', ' ', $question['prompt']));
            if (in_array($fingerprint, $prompts, true)) {
                return null;
            }
            $prompts[] = $fingerprint;
            $bySkill[$question['skill_id']][] = $question;
        }

        // التحقق الفعلي من التغطية: لكل مهارة مختارة العدد المطلوب بالضبط
        foreach ($bySkill as $questions) {
            if (count($questions) !== $perSkill) {
                return null;
            }
        }

        // ترتيب ثابت حسب ترتيب المهارات المختارة، ومعرّفات يحددها الخادم
        $ordered = [];
        foreach ($skillIds as $skillId) {
            foreach ($bySkill[$skillId] as $question) {
                $position = count($ordered) + 1;
                $ordered[] = ['id' => "q{$position}", 'position' => $position] + $question;
            }
        }

        $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';

        return [
            'title' => mb_substr($title !== '' ? $title : 'تقييم '.$skills->pluck('name')->join('، '), 0, 200),
            'questions' => $ordered,
        ];
    }

    /** @param array<int, int> $skillIds */
    private function question(mixed $item, array $skillIds): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $skillId = $item['skill_id'] ?? null;
        $prompt = $this->text($item['prompt'] ?? null, 2000);
        $codeContext = is_string($item['code_context'] ?? null) ? rtrim($item['code_context']) : '';
        $language = $this->text($item['language'] ?? null, 30);
        $reference = $this->text($item['reference_answer'] ?? null, 4000);
        $minimum = $this->text($item['minimum_evidence'] ?? null, 1000);
        $concepts = $this->stringList($item['key_concepts'] ?? null, 10, 300);
        $mistakes = $this->stringList($item['common_mistakes'] ?? [], 8, 300);

        if (! is_int($skillId) || ! in_array($skillId, $skillIds, true)
            || ! in_array($item['format'] ?? null, self::FORMATS, true)
            || ! in_array($item['difficulty'] ?? null, self::DIFFICULTIES, true)
            || mb_strlen($prompt) < 15
            || mb_strlen($codeContext) > 4000
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
            'format' => $item['format'],
            'difficulty' => $item['difficulty'],
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
            'لكل مهارة في skills أنشئ questions_per_skill أسئلة بالضبط، وضع skill_id من القائمة حرفيًا. يقيس كل سؤال المهارة المحددة له وليس مهارة أخرى. السياق هو المسار المهني specialization.',
            'format: text للشرح بكلمات الطالب، أو تحليل سيناريو وتبرير الحل، أو اكتشاف خطأ في كود وشرحه، أو وصف طريقة تنفيذ أو تصحيح خطأ؛ code لكتابة كود أو استعلام SQL أو أمر. اختر ما يناسب المهارة، واجعل سؤالًا واحدًا على الأقل من نوع code للمهارات البرمجية.',
            'code_context: كود يحتاجه السؤال (مثل كود به خطأ) وإلا فاتركه فارغًا، ولا تضع فيه الحل. language: لغة الكود مثل php أو sql أو dart، أو فارغ.',
            'كل سؤال قابل للإجابة في حدود minutes_per_question دقيقة بإجابة مركّزة، بمستوى difficulty. اكتب نص السؤال بالعربية مع المصطلحات التقنية بالإنجليزية؛ قد يجيب الطالب بالعربية أو الإنجليزية.',
            'المهارات التي practical = true (مثل أدوات التصميم) لا تُثبت بالكتابة: اطرح عنها أسئلة سيناريو وتحليل قرارات وإمكانية وصول فقط، ولا تطلب تنفيذًا عمليًا بالأداة.',
            'لكل سؤال معايير تصحيح خاصة: criteria من 1 إلى 6 معايير، لكل منها description يوضح ما يستحق الدرجة الكاملة والجزئية و points عدد صحيح من 1 إلى 10؛ reference_answer نموذج لإجابة صحيحة (وليس الإجابة الوحيدة المقبولة)؛ key_concepts المفاهيم الأساسية؛ common_mistakes الأخطاء الشائعة أو الحرجة؛ minimum_evidence الحد الأدنى الذي يجب أن تُظهره الإجابة لتُعد مقبولة.',
            'لا تكشف الإجابة أو المعايير في نص السؤال. title عنوان قصير للتقييم. أعد JSON مطابقًا للمخطط فقط دون أي نص إضافي.',
        ]);
    }

    private function schema(): array
    {
        $string = ['type' => 'STRING'];
        $strings = ['type' => 'ARRAY', 'items' => $string];

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
                            'format' => ['type' => 'STRING', 'enum' => self::FORMATS],
                            'difficulty' => ['type' => 'STRING', 'enum' => self::DIFFICULTIES],
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
                        'required' => ['skill_id', 'format', 'difficulty', 'prompt', 'criteria', 'reference_answer', 'key_concepts', 'minimum_evidence'],
                    ],
                ],
            ],
            'required' => ['title', 'questions'],
        ];
    }
}
