<?php

namespace App\Services\AI;

use App\Contracts\AIProvider;
use App\Contracts\ProjectProposalGenerator;
use App\Models\IntakeResponse;
use App\Models\ProjectRequest;
use App\Services\Projects\ProjectStaffing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use JsonException;

class GeminiProjectProposalGenerator implements ProjectProposalGenerator
{
    public function __construct(private readonly AIProvider $provider) {}

    /** أساس كل متطلب حرج: مؤكد من المؤسسة، توصية بانتظار التأكيد، افتراض، أو غير معروف. */
    public const REQUIREMENT_BASES = ['confirmed', 'recommendation', 'assumption', 'unknown'];

    private const RESPONSE_SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'project_overview' => [
                'type' => 'OBJECT',
                'properties' => [
                    'project_name' => ['type' => 'STRING'],
                    'executive_summary' => ['type' => 'STRING'],
                    'business_problem' => ['type' => 'STRING'],
                    'proposed_solution' => ['type' => 'STRING'],
                ],
                'required' => ['project_name', 'executive_summary', 'business_problem', 'proposed_solution'],
            ],
            'business_problem' => [
                'type' => 'OBJECT',
                'properties' => [
                    'current_situation' => ['type' => 'STRING'],
                    'pain_points' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                    'impact' => ['type' => 'STRING'],
                ],
                'required' => ['current_situation', 'pain_points', 'impact'],
            ],
            'objectives' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'target_users' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'user' => ['type' => 'STRING'],
                        'role' => ['type' => 'STRING'],
                        'interaction' => ['type' => 'STRING'],
                    ],
                    'required' => ['user', 'role', 'interaction'],
                ],
            ],
            'proposed_solution' => ['type' => 'STRING'],
            'functional_scope' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'group' => ['type' => 'STRING'],
                        'requirements' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                    ],
                    'required' => ['group', 'requirements'],
                ],
            ],
            'out_of_scope' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'technical_recommendation' => [
                'type' => 'OBJECT',
                'properties' => [
                    'application_type' => ['type' => 'STRING'],
                    'frontend' => ['type' => 'STRING'],
                    'backend' => ['type' => 'STRING'],
                    'database' => ['type' => 'STRING'],
                    'integrations' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                    'rationale' => ['type' => 'STRING'],
                ],
                'required' => ['application_type', 'frontend', 'backend', 'database', 'integrations', 'rationale'],
            ],
            'required_skills' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'proposed_team' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'role' => ['type' => 'STRING'],
                        'responsibilities' => ['type' => 'STRING'],
                    ],
                    'required' => ['role', 'responsibilities'],
                ],
            ],
            'milestones' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'title' => ['type' => 'STRING'],
                        'description' => ['type' => 'STRING'],
                        'duration' => ['type' => 'STRING'],
                    ],
                    'required' => ['title', 'description', 'duration'],
                ],
            ],
            'estimated_duration' => ['type' => 'STRING'],
            'estimated_cost' => ['type' => 'STRING'],
            'operational_cost' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'item' => ['type' => 'STRING'],
                        'estimate' => ['type' => 'STRING'],
                        'notes' => ['type' => 'STRING'],
                    ],
                    'required' => ['item', 'estimate', 'notes'],
                ],
            ],
            'assumptions' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'needs_confirmation' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'success_criteria' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'srs_summary' => [
                'type' => 'OBJECT',
                'properties' => [
                    'functional_requirements' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                    'non_functional_considerations' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                ],
                'required' => ['functional_requirements', 'non_functional_considerations'],
            ],
            // أقسام اختيارية: تُحذف عند غياب الأساس بدل اختلاق قيم
            'risks' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'payment_schedule' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'milestone' => ['type' => 'STRING'],
                        'share' => ['type' => 'STRING'],
                        'notes' => ['type' => 'STRING'],
                    ],
                    'required' => ['milestone', 'share', 'notes'],
                ],
            ],
        ],
        'required' => [
            'project_overview',
            'business_problem',
            'objectives',
            'target_users',
            'proposed_solution',
            'functional_scope',
            'out_of_scope',
            'technical_recommendation',
            'required_skills',
            'proposed_team',
            'milestones',
            'estimated_duration',
            'estimated_cost',
            'operational_cost',
            'assumptions',
            'needs_confirmation',
            'success_criteria',
            'srs_summary',
        ],
    ];

    public function generate(ProjectRequest $projectRequest): array
    {
        try {
            $context = json_encode(
                $this->proposalContext($projectRequest),
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            Log::warning('Gemini project proposal context could not be encoded.', [
                'provider' => 'gemini',
            ]);

            throw ProjectProposalException::unavailable();
        }

        try {
            $response = $this->provider->generateResponse([
                'systemInstruction' => [
                    'parts' => [[
                        'text' => implode("\n", [
                            'أنت مستشار تحليل أعمال وتصميم مشاريع في GoTraniee. أنشئ مقترحًا عربيًا مهنيًا واضحًا لمؤسسة غير تقنية.',
                            'استخدم حقائق السياق والإجابات المؤكدة فقط بوصفها حقائق. افصل الافتراضات صراحةً عن الحقائق، ولا تخترع متطلبات مؤكدة.',
                            'اقترح الحل والتقنيات والفريق بما يتناسب مع حجم المشكلة. لا تذكر تقنية بعينها إلا إذا كان لها مبرر واضح في هذا المشروع؛ اذكر "يُحدد لاحقًا" عند غياب مبرر كافٍ.',
                            'حوّل أهداف المؤسسة إلى أهداف ومعايير نجاح قابلة للفهم. اجعل نطاق الوظائف مجموعات عملية ومتطلبات محددة، واذكر ما هو خارج النطاق بوضوح.',
                            'قدّم مدة وتكلفة كنطاقين مبدئيين غير ملزمين، وابدأ نص التكلفة بالعبارة "تقدير مبدئي". لا تقدّمها كسعر ملزم. لا تخترع أسعارًا محلية دقيقة دون أساس؛ وضّح ما يلزم تأكيده.',
                            'قدّر التكاليف التشغيلية ذات الصلة مثل الاستضافة والنطاق والواجهات الخارجية والصيانة، واذكر عند تعذر التقدير أنها تحتاج إلى تأكيد.',
                            'أدرج القرارات الناقصة أو الغامضة ضمن needs_confirmation، ولا تحوّلها إلى حقائق أو افتراضات مؤكدة.',
                            'المصدر الأساسي هو structured_requirements المجمعة من المحادثة كاملة وليس آخر رسالة: البنود من نوع fact حقائق، وassumption افتراضات تذكرها في assumptions، وunknown معلومات لم تحددها المؤسسة فلا تخترع لها قيمًا، وopen_question وcontradiction تُدرج في needs_confirmation أو risks. استخدم أيضًا unincorporated_organization_input لأنها أحدث مدخلات المؤسسة.',
                            'اربط كل ميزة رئيسية في functional_scope باحتياج مذكور أو بافتراض صريح، واعرض الميزات الاختيارية كمرحلة لاحقة. لا تُبقِ ميزة استبعدتها المؤسسة.',
                            'افصل تكلفة التطوير لمرة واحدة (estimated_cost) عن التكاليف التشغيلية المتكررة (operational_cost). إن كانت الميزانية غير محددة فلا تفترض قيمة لها، واذكر أساس التقدير وما يلزم تأكيده.',
                            'المدة تقدير مبني على النطاق وليست التزامًا؛ إن ذكرت المؤسسة موعدًا فوضّح هل يبدو واقعيًا دون ضمانه.',
                            'risks اختياري: المخاطر والاعتماديات المهمة فقط. payment_schedule اختياري: أدرجه فقط عند وجود تقدير تكلفة، كجدول دفعات مقترح مرتبط بالمراحل، وإلا فاحذفه.',
                            'إذا وُجد revision_request فطبّق التعديل المطلوب على النطاق والمراحل والمدة والتكلفة والتشغيل بما يتسق مع المتطلبات المحدثة، دون أرقام غير مبررة.',
                            'staffing_requirements هو مصدر الحقيقة لأدوار الفريق: لكل دور اختر category من role_categories، واذكر المسؤوليات وrationale يربطه بميزات محددة في functional_scope. لا تضف دورًا لا يحتاجه النطاق فعلًا (مثل مصمم أو مدير مشروع كعضو مستقل) ولا تخلط بين مسؤولية ومهمة عضو فريق كامل.',
                            'المهارات الإلزامية والمرغوبة يجب أن تكون من skill_catalog حرفيًا. لا تفرض إطار عمل أو تقنية بعينها (مثل Flutter أو Laravel أو React Native) ما لم تؤكدها المؤسسة أو يفرضها النطاق المؤكد؛ عندها استخدم المهارة الأعم (مثل REST API أو JavaScript) أو اجعل التقنية مرغوبة لا إلزامية.',
                            'key_requirements: المتطلبات الحرجة للمشروع مع basis: confirmed إذا ذكرتها المؤسسة، recommendation إذا كانت توصية منك بانتظار تأكيدها، assumption إذا كانت افتراضًا، unknown إذا لم تُحسم بعد. لا تقدم توصية أو افتراضًا كحقيقة مؤكدة.',
                            'لا تختلق حقائق عن المؤسسة أو مواعيد أو أرقام أداء أو توفير مالي أو بيانات متاحة. يمكنك حساب نسبة من أرقام ذكرتها المؤسسة، لكن اعرضها كهدف محتمل مع افتراضاته وليس كنتيجة مضمونة. technical_recommendation توصية مبدئية لم تُعتمد بعد، فصغها كذلك.',
                            'اذكر في operational_cost والنطاق من سيشغّل النظام بعد التسليم (مثل موظف يحدّث البيانات) دون أن تجعله عضوًا في فريق التطوير. اكتب عناصر القوائم دون ترقيم يدوي في بدايتها.',
                            'أعد جميع أقسام المخطط. اكتب JSON صالحًا مطابقًا للمخطط فقط، دون Markdown أو نص إضافي.',
                        ]),
                    ]],
                ],
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $context]],
                ]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $this->responseSchema(),
                ],
            ]);
        } catch (AIProviderConfigurationException) {
            Log::error('Gemini project proposal generation is not configured.', [
                'provider' => 'gemini',
            ]);

            throw ProjectProposalException::unavailable();
        } catch (ConnectionException) {
            Log::warning('Gemini project proposal connection failed.', [
                'provider' => 'gemini',
            ]);

            throw ProjectProposalException::unavailable();
        }

        if ($response->failed()) {
            Log::warning('Gemini project proposal request failed.', [
                'provider' => 'gemini',
                'status' => $response->status(),
            ]);

            throw ProjectProposalException::unavailable();
        }

        $extracted = GeminiStructuredOutput::extract($response);
        $proposal = $this->normalize($extracted['data']);
        if ($proposal === null) {
            Log::warning('Gemini project proposal returned invalid structured output.', [
                'provider' => 'gemini',
                'parse_error' => $extracted['diagnostic']['parse_error'] ?? 'schema_validation',
                'finish_reason' => $extracted['diagnostic']['finish_reason'] ?? null,
            ]);

            throw ProjectProposalException::unavailable();
        }

        if (! str_starts_with($proposal['estimated_cost'], 'تقدير مبدئي')) {
            $proposal['estimated_cost'] = 'تقدير مبدئي: '.$proposal['estimated_cost'];
        }

        return $proposal;
    }

    /**
     * المخطط الثابت + أقسام الفريق والمتطلبات الحرجة. أسماء المهارات مقيدة بجدول skills حتى
     * ترتبط الأدوار بمهارات يمكن التحقق منها لدى الطلاب.
     */
    private function responseSchema(): array
    {
        $skillNames = array_column(ProjectStaffing::catalog(), 'name');
        $skillSchema = $skillNames === []
            ? ['type' => 'STRING']
            : ['type' => 'STRING', 'enum' => array_values($skillNames)];

        $schema = self::RESPONSE_SCHEMA;
        $schema['properties']['staffing_requirements'] = [
            'type' => 'ARRAY',
            'items' => [
                'type' => 'OBJECT',
                'properties' => [
                    'category' => ['type' => 'STRING', 'enum' => array_keys(ProjectStaffing::ROLE_CATEGORIES)],
                    'title' => ['type' => 'STRING'],
                    'responsibilities' => ['type' => 'STRING'],
                    'rationale' => ['type' => 'STRING'],
                    'headcount' => ['type' => 'INTEGER'],
                    'mandatory_skills' => ['type' => 'ARRAY', 'items' => $skillSchema],
                    'desirable_skills' => ['type' => 'ARRAY', 'items' => $skillSchema],
                ],
                'required' => ['category', 'title', 'responsibilities', 'rationale', 'headcount', 'mandatory_skills', 'desirable_skills'],
            ],
        ];
        $schema['properties']['key_requirements'] = [
            'type' => 'ARRAY',
            'items' => [
                'type' => 'OBJECT',
                'properties' => [
                    'requirement' => ['type' => 'STRING'],
                    'basis' => ['type' => 'STRING', 'enum' => self::REQUIREMENT_BASES],
                    'note' => ['type' => 'STRING'],
                ],
                'required' => ['requirement', 'basis', 'note'],
            ],
        ];
        $schema['required'][] = 'staffing_requirements';
        $schema['required'][] = 'key_requirements';

        return $schema;
    }

    private function proposalContext(ProjectRequest $projectRequest): array
    {
        $projectRequest->loadMissing(['organization', 'intakeSession.responses']);
        $history = $projectRequest->intakeSession?->responses
            ->whereNotNull('answered_at')
            ->map(fn (IntakeResponse $response): array => [
                'question' => $response->question_text,
                'answer' => $this->displayAnswer($response),
                'custom_answer' => $response->custom_answer,
            ])
            ->values()
            ->all() ?? [];

        $currentState = $projectRequest->intakeSession?->current_state ?? [];
        $requirements = DiscoveryRequirements::from($currentState);
        // إجابات/طلبات تعديل لم تُستخرج بعد إلى الحالة المنظمة (مثل تعديل أُرسل ثم طُلب المقترح مباشرة)
        $unincorporated = ! DiscoveryRequirements::isTracked($currentState) ? [] : $projectRequest->intakeSession?->responses
            ->whereNotNull('answered_at')
            ->filter(fn (IntakeResponse $response): bool => $response->sequence > $requirements['processed_through'])
            ->map(fn (IntakeResponse $response): array => [
                'question' => $response->question_text,
                'answer' => $this->displayAnswer($response),
                'custom_answer' => $response->custom_answer,
            ])
            ->values()
            ->all() ?? [];

        return [
            'organization' => array_filter([
                'name' => $projectRequest->organization?->organization_name,
                'type' => $projectRequest->organization?->organization_type,
                'industry' => $projectRequest->organization?->industry,
                'description' => $projectRequest->organization?->description,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            'project' => [
                'title' => $projectRequest->title,
                'problem' => $projectRequest->problem_description,
                'current_process' => $projectRequest->current_process ?? [],
                'target_users' => $projectRequest->target_users ?? [],
                'desired_outcomes' => $projectRequest->desired_outcomes ?? [],
                'existing_tools' => $projectRequest->existing_tools ?? [],
                'constraints' => $projectRequest->special_requirements,
                'deadline' => $projectRequest->deadline?->toDateString(),
                'priority' => $projectRequest->priority,
                'budget_range' => $projectRequest->budget_range,
                'intake_notes' => $projectRequest->intake_data ?? [],
            ],
            'discovery_answers' => $history,
            'confirmed_requirements' => [
                'goals' => $projectRequest->desired_outcomes ?? [],
                'constraints' => $projectRequest->special_requirements,
                'confirmed_discovery_answers' => $projectRequest->intakeSession?->current_state['confirmed_requirements']
                    ?? $history,
            ],
            // المصدر الأساسي: المتطلبات المنظمة التراكمية (حقائق/افتراضات/مجهولات/نقاط مفتوحة)
            'structured_requirements' => DiscoveryRequirements::isTracked($currentState)
                ? DiscoveryRequirements::forPrompt($requirements)
                : null,
            'unincorporated_organization_input' => $unincorporated,
            'assumptions' => $this->itemsOfKind($requirements, 'assumption'),
            'unresolved_questions' => array_values(array_merge(
                $projectRequest->intake_data['unresolved_questions'] ?? [],
                $this->itemsOfKind($requirements, 'open_question'),
                $this->itemsOfKind($requirements, 'contradiction')
            )),
            'unknown_information' => $this->itemsOfKind($requirements, 'unknown'),
            'revision_request' => $projectRequest->revision_note,
            'skill_catalog' => array_values(array_column(ProjectStaffing::catalog(), 'name')),
            'role_categories' => ProjectStaffing::ROLE_CATEGORIES,
        ];
    }

    private function itemsOfKind(array $requirements, string $kind): array
    {
        return array_values(array_map(
            fn (array $item): string => (DiscoveryRequirements::AREAS[$item['area']] ?? $item['area']).': '.$item['text'],
            array_filter(
                DiscoveryRequirements::activeItems($requirements),
                fn (array $item): bool => $item['kind'] === $kind
            )
        ));
    }

    private function displayAnswer(IntakeResponse $response): mixed
    {
        $answer = $response->selected_answer;
        $options = $response->options ?? [];
        if ($response->question_type === 'yes_no' && is_bool($answer)) {
            return $answer ? 'نعم' : 'لا';
        }

        $labelsByValue = array_column($options, 'label_ar', 'value');
        if (is_array($answer)) {
            return array_map(
                fn (mixed $value): mixed => $labelsByValue[$value] ?? $value,
                $answer
            );
        }

        return $labelsByValue[$answer] ?? $answer;
    }

    private function normalize(mixed $proposal): ?array
    {
        if (! is_array($proposal) || ! $this->hasRequiredKeys($proposal, self::requiredKeys(self::RESPONSE_SCHEMA))) {
            return null;
        }

        foreach ([
            'project_overview' => ['project_name', 'executive_summary', 'business_problem', 'proposed_solution'],
            'business_problem' => ['current_situation', 'pain_points', 'impact'],
            'technical_recommendation' => ['application_type', 'frontend', 'backend', 'database', 'integrations', 'rationale'],
            'srs_summary' => ['functional_requirements', 'non_functional_considerations'],
        ] as $key => $requiredKeys) {
            if (! is_array($proposal[$key]) || ! $this->hasRequiredKeys($proposal[$key], $requiredKeys)) {
                return null;
            }
        }

        $stringPaths = [
            ['project_overview', 'project_name'],
            ['project_overview', 'executive_summary'],
            ['project_overview', 'business_problem'],
            ['project_overview', 'proposed_solution'],
            ['business_problem', 'current_situation'],
            ['business_problem', 'impact'],
            ['proposed_solution'],
            ['technical_recommendation', 'application_type'],
            ['technical_recommendation', 'frontend'],
            ['technical_recommendation', 'backend'],
            ['technical_recommendation', 'database'],
            ['technical_recommendation', 'rationale'],
            ['estimated_duration'],
            ['estimated_cost'],
        ];
        foreach ($stringPaths as $path) {
            $value = data_get($proposal, $path);
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 5000) {
                return null;
            }
            data_set($proposal, $path, trim($value));
        }

        $stringArrays = [
            'business_problem.pain_points' => 1,
            'objectives' => 1,
            'out_of_scope' => 0,
            'technical_recommendation.integrations' => 0,
            'required_skills' => 1,
            'assumptions' => 0,
            'needs_confirmation' => 0,
            'success_criteria' => 1,
            'srs_summary.functional_requirements' => 1,
            'srs_summary.non_functional_considerations' => 1,
        ];
        foreach ($stringArrays as $path => $minimum) {
            $items = data_get($proposal, $path);
            if (! $this->validStringList($items, $minimum)) {
                return null;
            }
        }

        foreach ([
            'target_users' => [['user', 'role', 'interaction'], 1],
            'proposed_team' => [['role', 'responsibilities'], 1],
            'milestones' => [['title', 'description', 'duration'], 2],
            'operational_cost' => [['item', 'estimate', 'notes'], 0],
        ] as $key => [$requiredKeys, $minimum]) {
            if (! $this->validObjectList($proposal[$key], $requiredKeys, $minimum)) {
                return null;
            }
        }

        if (
            ! is_array($proposal['functional_scope'])
            || count($proposal['functional_scope']) < 1
            || count($proposal['functional_scope']) > 30
        ) {
            return null;
        }
        foreach ($proposal['functional_scope'] as $group) {
            if (
                ! is_array($group)
                || ! $this->hasRequiredKeys($group, ['group', 'requirements'])
                || ! is_string($group['group'])
                || trim($group['group']) === ''
                || mb_strlen($group['group']) > 2000
                || ! $this->validStringList($group['requirements'], 1)
            ) {
                return null;
            }
        }

        // الأقسام الاختيارية: قيمة غير صالحة تُحذف وحدها ولا تُسقط المقترح
        if (array_key_exists('risks', $proposal) && ! $this->validStringList($proposal['risks'], 1)) {
            unset($proposal['risks']);
        }
        if (array_key_exists('payment_schedule', $proposal) && ! $this->validPaymentSchedule($proposal['payment_schedule'])) {
            unset($proposal['payment_schedule']);
        }

        // الفريق المنظم هو المصدر: قائمة الفريق والمهارات تُشتق منه حتى لا تتعارض
        $staffing = ProjectStaffing::sanitize($proposal['staffing_requirements'] ?? null);
        if ($staffing === []) {
            unset($proposal['staffing_requirements']);
        } else {
            $proposal['staffing_requirements'] = $staffing;
            $proposal['proposed_team'] = array_map(fn (array $role): array => [
                'role' => $role['title'],
                'responsibilities' => $role['responsibilities'],
            ], $staffing);
            $proposal['required_skills'] = array_values(array_unique(array_merge(
                ...array_map(fn (array $role): array => array_merge($role['mandatory_skills'], $role['desirable_skills']), $staffing)
            )));
        }

        $keyRequirements = $this->keyRequirements($proposal['key_requirements'] ?? null);
        if ($keyRequirements === []) {
            unset($proposal['key_requirements']);
        } else {
            $proposal['key_requirements'] = $keyRequirements;
        }

        return $proposal;
    }

    /** @return array<int, array{requirement: string, basis: string, note: string}> */
    private function keyRequirements(mixed $items): array
    {
        if (! is_array($items) || ! array_is_list($items)) {
            return [];
        }

        $valid = [];
        foreach ($items as $item) {
            $requirement = is_array($item) && is_string($item['requirement'] ?? null) ? trim($item['requirement']) : '';
            if ($requirement === '' || ! in_array($item['basis'] ?? null, self::REQUIREMENT_BASES, true)) {
                continue;
            }
            $valid[] = [
                'requirement' => mb_substr($requirement, 0, 1000),
                'basis' => $item['basis'],
                'note' => is_string($item['note'] ?? null) ? mb_substr(trim($item['note']), 0, 1000) : '',
            ];
        }

        return array_slice($valid, 0, 30);
    }

    private function validPaymentSchedule(mixed $items): bool
    {
        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > 12) {
            return false;
        }

        foreach ($items as $item) {
            if (
                ! is_array($item)
                || ! is_string($item['milestone'] ?? null) || trim($item['milestone']) === ''
                || ! is_string($item['share'] ?? null) || trim($item['share']) === ''
                || ! is_string($item['notes'] ?? '')
                || mb_strlen($item['milestone'].$item['share'].($item['notes'] ?? '')) > 2000
            ) {
                return false;
            }
        }

        return true;
    }

    private function validStringList(mixed $items, int $minimum): bool
    {
        if (! is_array($items) || count($items) < $minimum || count($items) > 30) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_string($item) || trim($item) === '' || mb_strlen($item) > 2000) {
                return false;
            }
        }

        return true;
    }

    private function validObjectList(mixed $items, array $keys, int $minimum): bool
    {
        if (! is_array($items) || count($items) < $minimum || count($items) > 30) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_array($item) || ! $this->hasRequiredKeys($item, $keys)) {
                return false;
            }
            foreach ($keys as $key) {
                if (! is_string($item[$key]) || trim($item[$key]) === '' || mb_strlen($item[$key]) > 2000) {
                    return false;
                }
            }
        }

        return true;
    }

    private function hasRequiredKeys(array $value, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $value)) {
                return false;
            }
        }

        return true;
    }

    private static function requiredKeys(array $schema): array
    {
        return $schema['required'];
    }
}
