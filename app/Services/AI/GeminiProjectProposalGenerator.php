<?php

namespace App\Services\AI;

use App\Contracts\AIProvider;
use App\Contracts\ProjectProposalGenerator;
use App\Models\IntakeResponse;
use App\Models\ProjectRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use JsonException;

class GeminiProjectProposalGenerator implements ProjectProposalGenerator
{
    public function __construct(private readonly AIProvider $provider) {}

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
                    'responseSchema' => self::RESPONSE_SCHEMA,
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

        $proposal = $this->normalize($this->extractOutput($response));
        if ($proposal === null) {
            Log::warning('Gemini project proposal returned invalid structured output.', [
                'provider' => 'gemini',
            ]);

            throw ProjectProposalException::unavailable();
        }

        if (! str_starts_with($proposal['estimated_cost'], 'تقدير مبدئي')) {
            $proposal['estimated_cost'] = 'تقدير مبدئي: '.$proposal['estimated_cost'];
        }

        return $proposal;
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
            ],
            'assumptions' => [],
            'unresolved_questions' => $projectRequest->intake_data['unresolved_questions'] ?? [],
            'revision_request' => $projectRequest->revision_note,
        ];
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

    private function extractOutput(Response $response): mixed
    {
        $body = $response->json();
        if (
            ! is_array($body)
            || ! is_array($body['candidates'] ?? null)
            || count($body['candidates']) !== 1
        ) {
            return null;
        }

        $candidate = $body['candidates'][0] ?? null;
        $parts = $candidate['content']['parts'] ?? null;
        if (! is_array($parts) || count($parts) !== 1 || ! is_string($parts[0]['text'] ?? null)) {
            return null;
        }

        try {
            return json_decode($parts[0]['text'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    private function normalize(mixed $proposal): ?array
    {
        if (! is_array($proposal) || ! $this->hasExactKeys($proposal, self::requiredKeys(self::RESPONSE_SCHEMA))) {
            return null;
        }

        foreach ([
            'project_overview' => ['project_name', 'executive_summary', 'business_problem', 'proposed_solution'],
            'business_problem' => ['current_situation', 'pain_points', 'impact'],
            'technical_recommendation' => ['application_type', 'frontend', 'backend', 'database', 'integrations', 'rationale'],
            'srs_summary' => ['functional_requirements', 'non_functional_considerations'],
        ] as $key => $requiredKeys) {
            if (! is_array($proposal[$key]) || ! $this->hasExactKeys($proposal[$key], $requiredKeys)) {
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
                || ! $this->hasExactKeys($group, ['group', 'requirements'])
                || ! is_string($group['group'])
                || trim($group['group']) === ''
                || mb_strlen($group['group']) > 2000
                || ! $this->validStringList($group['requirements'], 1)
            ) {
                return null;
            }
        }

        return $proposal;
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
            if (! is_array($item) || ! $this->hasExactKeys($item, $keys)) {
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

    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    private static function requiredKeys(array $schema): array
    {
        return $schema['required'];
    }
}
