<?php

namespace App\Services\AI;

use App\Models\IntakeResponse;

/**
 * الحالة التراكمية المنظمة لمتطلبات المشروع أثناء الاكتشاف.
 *
 * تُخزَّن داخل intake_sessions.current_state['requirements'] (عمود JSON موجود، بلا ترحيل).
 * كل بند له معرّف ثابت (r1, r2...) ونوع ومصدر، والتصحيحات لا تحذف البنود القديمة بل
 * تجعلها superseded مع الإشارة إلى البند الجديد، حتى يبقى أثر التعديل محفوظًا.
 */
final class DiscoveryRequirements
{
    /** محاور التحليل الإحدى عشرة (ليست أسئلة إلزامية). */
    public const AREAS = [
        'problem' => 'المشكلة',
        'current_process' => 'العملية الحالية',
        'desired_outcome' => 'النتيجة المطلوبة',
        'users' => 'المستخدمون',
        'business_needs' => 'الاحتياجات',
        'important_features' => 'الميزات',
        'constraints' => 'القيود',
        'expected_timeline' => 'الموعد',
        'budget' => 'الميزانية',
        'operational_requirements' => 'التشغيل',
        'additional_information' => 'إضافات',
    ];

    /** المحاور التي يجب أن تُفهم (أو يُصرَّح بأنها غير معروفة) قبل إعداد المقترح. */
    public const CORE_AREAS = [
        'problem',
        'current_process',
        'desired_outcome',
        'users',
        'business_needs',
        'important_features',
    ];

    public const KINDS = ['fact', 'unknown', 'assumption', 'open_question', 'contradiction'];

    public const COVERAGE = ['missing', 'partial', 'sufficient', 'unknown', 'not_applicable'];

    public const PRIORITIES = ['essential', 'optional', 'unspecified'];

    public const MAX_UPDATES_PER_TURN = 15;

    public const MAX_TEXT_LENGTH = 600;

    private const MAX_ITEMS = 300;

    private const PRIORITY_AREAS = ['business_needs', 'important_features'];

    public static function isTracked(?array $currentState): bool
    {
        return is_array($currentState['requirements'] ?? null);
    }

    public static function from(?array $currentState): array
    {
        $state = is_array($currentState['requirements'] ?? null) ? $currentState['requirements'] : [];

        return [
            'items' => array_values(array_filter(
                is_array($state['items'] ?? null) ? $state['items'] : [],
                fn (mixed $item): bool => is_array($item) && isset($item['id'], $item['area'], $item['kind'], $item['text'])
            )),
            'coverage' => array_filter(
                is_array($state['coverage'] ?? null) ? $state['coverage'] : [],
                fn (mixed $status, mixed $area): bool => isset(self::AREAS[$area]) && in_array($status, self::COVERAGE, true),
                ARRAY_FILTER_USE_BOTH
            ),
            'processed_through' => max(0, (int) ($state['processed_through'] ?? 0)),
            'form_processed' => (bool) ($state['form_processed'] ?? false),
            'next_id' => max(1, (int) ($state['next_id'] ?? 1)),
        ];
    }

    /**
     * يدمج التحديثات المستخرجة (بعد التحقق من شكلها في ProjectDiscoveryService).
     *
     * @param  array<int, array{area: string, kind: string, text: string, priority?: string, replaces?: array<int, string>}>  $updates
     * @param  array<string, string>  $coverage
     * @param  array{type: string, sequence: ?int}  $source
     */
    public static function apply(array $requirements, array $updates, array $coverage, array $source): array
    {
        $items = $requirements['items'];
        $nextId = $requirements['next_id'];
        $activeIndex = [];
        foreach ($items as $index => $item) {
            if (($item['status'] ?? 'active') === 'active') {
                $activeIndex[$item['id']] = $index;
            }
        }

        foreach (array_slice($updates, 0, self::MAX_UPDATES_PER_TURN) as $update) {
            $area = $update['area'] ?? null;
            $kind = $update['kind'] ?? null;
            $text = self::cleanText($update['text'] ?? null);
            if (! isset(self::AREAS[$area]) || ! in_array($kind, self::KINDS, true) || $text === '') {
                continue;
            }

            // البنود المستبدلة يجب أن تكون موجودة ونشطة، وإلا تُتجاهل
            $replaces = array_values(array_unique(array_filter(
                is_array($update['replaces'] ?? null) ? $update['replaces'] : [],
                fn (mixed $id): bool => is_string($id) && isset($activeIndex[$id])
            )));

            if ($replaces === [] && self::hasActiveDuplicate($items, $area, $kind, $text)) {
                continue;
            }

            $id = 'r'.$nextId++;
            foreach ($replaces as $replacedId) {
                $items[$activeIndex[$replacedId]]['status'] = 'superseded';
                $items[$activeIndex[$replacedId]]['superseded_by'] = $id;
                unset($activeIndex[$replacedId]);
            }

            $priority = $update['priority'] ?? 'unspecified';
            $items[] = [
                'id' => $id,
                'area' => $area,
                'kind' => $kind,
                'text' => $text,
                'priority' => in_array($area, self::PRIORITY_AREAS, true) && in_array($priority, self::PRIORITIES, true)
                    ? $priority
                    : null,
                'source' => $source['type'],
                'source_sequence' => $source['sequence'],
                'status' => 'active',
            ];
            $activeIndex[$id] = array_key_last($items);
        }

        foreach ($coverage as $area => $status) {
            if (isset(self::AREAS[$area]) && in_array($status, self::COVERAGE, true)) {
                $requirements['coverage'][$area] = $status;
            }
        }

        $requirements['items'] = self::capItems($items);
        $requirements['next_id'] = $nextId;

        return $requirements;
    }

    public static function activeItems(array $requirements): array
    {
        return array_values(array_filter(
            $requirements['items'],
            fn (array $item): bool => ($item['status'] ?? 'active') === 'active'
        ));
    }

    public static function areaStatus(array $requirements, string $area): string
    {
        $coverage = $requirements['coverage'][$area] ?? null;
        $active = array_filter(
            self::activeItems($requirements),
            fn (array $item): bool => $item['area'] === $area
        );
        $facts = array_filter($active, fn (array $item): bool => $item['kind'] === 'fact');
        $hasUnknown = array_filter($active, fn (array $item): bool => $item['kind'] === 'unknown') !== [];
        $hasContradiction = array_filter($active, fn (array $item): bool => $item['kind'] === 'contradiction') !== [];

        // التغطية تُحسب من الأدلة المحفوظة فقط: تقييم Gemini وحده لا يجعل المحور مغطى.
        // الحقائق المذكورة صراحة دليل، و"غير معروف" دليل فقط إذا صرحت به المؤسسة (بند unknown).
        if ($facts !== []) {
            return $coverage === 'sufficient' && ! $hasContradiction ? 'sufficient' : 'partial';
        }
        if ($hasUnknown) {
            return 'unknown';
        }
        if ($coverage === 'not_applicable' && ! in_array($area, self::CORE_AREAS, true)) {
            return 'not_applicable';
        }

        return 'missing';
    }

    /**
     * المحاور الأساسية التي تمنع إعداد المقترح: محور بلا دليل، أو محور "غير معروف" (العملية الحالية
     * وحدها يمكن أن تبقى غير معروفة)، أو محور فيه تعارض لم يُحسم.
     */
    public static function missingCoreAreas(array $requirements): array
    {
        $active = self::activeItems($requirements);

        return array_values(array_filter(
            self::CORE_AREAS,
            function (string $area) use ($requirements, $active): bool {
                $status = self::areaStatus($requirements, $area);
                $contradiction = array_filter(
                    $active,
                    fn (array $item): bool => $item['area'] === $area && $item['kind'] === 'contradiction'
                ) !== [];

                return $status === 'missing'
                    || ($status === 'unknown' && $area !== 'current_process')
                    || $contradiction;
            }
        ));
    }

    public static function isReady(?array $currentState): bool
    {
        return self::isTracked($currentState)
            && self::missingCoreAreas(self::from($currentState)) === [];
    }

    /**
     * عرض مضغوط لـ Gemini: البنود النشطة مجمعة حسب المحور مع معرّفاتها.
     */
    public static function forPrompt(array $requirements): array
    {
        $areas = [];
        foreach (self::AREAS as $area => $label) {
            $areas[$area] = [
                'label' => $label,
                'status' => self::areaStatus($requirements, $area),
                'items' => array_values(array_map(
                    fn (array $item): array => array_filter([
                        'id' => $item['id'],
                        'kind' => $item['kind'],
                        'text' => $item['text'],
                        'priority' => $item['priority'] ?? null,
                    ], fn (mixed $value): bool => $value !== null),
                    array_filter(
                        self::activeItems($requirements),
                        fn (array $item): bool => $item['area'] === $area
                    )
                )),
            ];
        }

        return $areas;
    }

    /**
     * عرض للواجهة: المحاور الإحدى عشرة بالترتيب مع حالتها وبنودها النشطة.
     */
    public static function publicView(?array $currentState): array
    {
        $tracked = self::isTracked($currentState);
        $requirements = self::from($currentState);
        $areas = [];
        $covered = 0;

        foreach (self::AREAS as $area => $label) {
            $status = self::areaStatus($requirements, $area);
            // "مفهوم" يعني وجود دليل من المؤسسة؛ ادعاء "لا ينطبق" دون دليل لا يُحتسب
            if (in_array($status, ['sufficient', 'partial', 'unknown'], true)) {
                $covered++;
            }

            $areas[] = [
                'key' => $area,
                'label' => $label,
                'status' => $status,
                'core' => in_array($area, self::CORE_AREAS, true),
                'items' => array_values(array_map(
                    fn (array $item): array => [
                        'id' => $item['id'],
                        'kind' => $item['kind'],
                        'text' => $item['text'],
                        'priority' => $item['priority'] ?? null,
                        'source' => $item['source'] ?? null,
                    ],
                    array_filter(
                        self::activeItems($requirements),
                        fn (array $item): bool => $item['area'] === $area
                    )
                )),
            ];
        }

        $missing = self::missingCoreAreas($requirements);

        return [
            'tracked' => $tracked,
            'areas' => $areas,
            'covered_count' => $covered,
            'total' => count(self::AREAS),
            'missing_core_areas' => $missing,
            'ready_for_proposal' => $tracked && $missing === [],
        ];
    }

    public static function labels(array $areas): array
    {
        return array_values(array_map(fn (string $area): string => self::AREAS[$area] ?? $area, $areas));
    }

    /** إجابة المؤسسة كما هي (بدون تفسير) لسجل confirmed_requirements الموجود. */
    public static function confirmedAnswer(IntakeResponse $response): array
    {
        $selectedValues = is_array($response->selected_answer)
            ? $response->selected_answer
            : [$response->selected_answer];
        $labelsByValue = array_column($response->options ?? [], 'label_ar', 'value');
        $selectedLabels = $response->question_type === 'yes_no'
            && is_bool($response->selected_answer)
            ? [$response->selected_answer ? 'نعم' : 'لا']
            : array_values(array_filter(array_map(
                fn (mixed $value): ?string => is_string($value)
                    ? ($labelsByValue[$value] ?? null)
                    : null,
                $selectedValues
            )));

        $requirement = [
            'sequence' => $response->sequence,
            'question_id' => $response->question_id,
            'question' => $response->question_text,
            'answer' => $response->selected_answer,
            'selected_option_labels' => $selectedLabels,
            'source' => 'user_answer',
        ];
        if (is_string($response->custom_answer) && trim($response->custom_answer) !== '') {
            $requirement['custom_answer'] = $response->custom_answer;
        }

        return $requirement;
    }

    public static function appendConfirmedAnswer(mixed $existing, IntakeResponse $response): array
    {
        $requirements = is_array($existing) ? array_values($existing) : [];
        $requirement = self::confirmedAnswer($response);

        foreach ($requirements as $index => $existingRequirement) {
            if (
                is_array($existingRequirement)
                && ($existingRequirement['question_id'] ?? null) === $requirement['question_id']
            ) {
                $requirements[$index] = array_merge($existingRequirement, $requirement);

                return $requirements;
            }
        }

        $requirements[] = $requirement;

        return $requirements;
    }

    private static function cleanText(mixed $text): string
    {
        if (! is_string($text)) {
            return '';
        }

        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_substr($text, 0, self::MAX_TEXT_LENGTH);
    }

    private static function hasActiveDuplicate(array $items, string $area, string $kind, string $text): bool
    {
        $normalized = mb_strtolower($text);
        foreach ($items as $item) {
            if (
                ($item['status'] ?? 'active') === 'active'
                && $item['area'] === $area
                && $item['kind'] === $kind
                && mb_strtolower((string) $item['text']) === $normalized
            ) {
                return true;
            }
        }

        return false;
    }

    /** حد أعلى للحجم: تُحذف أقدم البنود المستبدلة أولًا، ولا تُحذف البنود النشطة. */
    private static function capItems(array $items): array
    {
        while (count($items) > self::MAX_ITEMS) {
            $oldestSuperseded = null;
            foreach ($items as $index => $item) {
                if (($item['status'] ?? 'active') !== 'active') {
                    $oldestSuperseded = $index;
                    break;
                }
            }
            if ($oldestSuperseded === null) {
                break;
            }
            unset($items[$oldestSuperseded]);
            $items = array_values($items);
        }

        return $items;
    }
}
