<?php

namespace App\Services\Projects;

use App\Models\AuditLog;
use App\Models\PlatformNotification;
use App\Models\ProjectOpportunity;
use App\Models\ProjectPlanComment;
use App\Models\ProjectPlanVersion;
use App\Models\ProjectTeam;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * مساحة التخطيط والاتفاق بين قائد الفريق ومالك المؤسسة.
 *
 * - القائد وحده يكتب المسودة (draft). المسودة الوحيدة القابلة للتعديل في أي لحظة.
 * - تقديم النسخة = موافقة القائد على هذه النسخة بعينها: تُجمَّد وتُحسب بصمة محتواها (SHA-256).
 * - المؤسسة توافق أو تطلب تعديلًا على أحدث نسخة مقدمة فقط، وتُرسل البصمة التي راجعتها؛ اختلافها = موافقة قديمة تُرفض.
 * - الموافقة المزدوجة على النسخة والبصمة نفسيهما تجعلها خط الأساس (agreed_plan_version_id) ولا تُعدل بعدها.
 * - لا يبدأ التنفيذ إلا بخطة متفق عليها وفريق مؤكد.
 */
class ProjectPlanning
{
    public function rules(): array
    {
        $currencies = (array) config('gotrainee.currencies', ['USD']);

        return [
            'scope' => ['required', 'string', 'min:10', 'max:5000'],
            'deliverables' => ['required', 'array', 'min:1', 'max:30'],
            'deliverables.*.title' => ['required', 'string', 'max:200'],
            'deliverables.*.description' => ['nullable', 'string', 'max:1000'],
            'acceptance_criteria' => ['required', 'array', 'min:1', 'max:50'],
            'acceptance_criteria.*' => ['required', 'string', 'max:500'],
            'execution_budget' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'currency' => ['required', 'string', Rule::in($currencies)],
            'payment_schedule' => ['required', 'array', 'min:1', 'max:20'],
            'payment_schedule.*.label' => ['required', 'string', 'max:200'],
            'payment_schedule.*.percentage' => ['required', 'numeric', 'gt:0', 'max:100'],
            'payment_schedule.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'timeline' => ['required', 'array'],
            'timeline.start_date' => ['required', 'date_format:Y-m-d'],
            'timeline.end_date' => ['required', 'date_format:Y-m-d', 'after:timeline.start_date'],
            'milestones' => ['required', 'array', 'min:1', 'max:30'],
            'milestones.*.title' => ['required', 'string', 'max:200'],
            'milestones.*.due_date' => ['required', 'date_format:Y-m-d'],
            'milestones.*.description' => ['nullable', 'string', 'max:1000'],
            'sprints' => ['present', 'array', 'max:52'],
            'sprints.*.name' => ['required', 'string', 'max:120'],
            'sprints.*.start_date' => ['required', 'date_format:Y-m-d'],
            'sprints.*.end_date' => ['required', 'date_format:Y-m-d'],
            'sprints.*.goal' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** يتحقق من المحتوى ويطبّعه ويحسب البنود المالية في الخادم (لا يُقبل أي رقم مالي محسوب من العميل). */
    public function normalize(array $input): array
    {
        $validator = Validator::make($input, $this->rules(), [
            'timeline.end_date.after' => 'تاريخ نهاية المشروع يجب أن يكون بعد تاريخ البداية.',
        ]);
        $validator->after(function ($validator) use ($input): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $total = array_sum(array_map(fn ($row): float => (float) $row['percentage'], $input['payment_schedule']));
            if (abs($total - 100) > 0.01) {
                $validator->errors()->add('payment_schedule', 'مجموع نسب الدفعات يجب أن يساوي 100%.');
            }
            $start = $input['timeline']['start_date'];
            $end = $input['timeline']['end_date'];
            foreach ($input['milestones'] as $index => $milestone) {
                if ($milestone['due_date'] < $start || $milestone['due_date'] > $end) {
                    $validator->errors()->add("milestones.$index.due_date", 'موعد المرحلة يجب أن يقع ضمن مدة المشروع.');
                }
            }
            foreach ($input['sprints'] as $index => $sprint) {
                if ($sprint['end_date'] < $sprint['start_date'] || $sprint['start_date'] < $start || $sprint['end_date'] > $end) {
                    $validator->errors()->add("sprints.$index.end_date", 'تواريخ السبرنت يجب أن تكون مرتبة وضمن مدة المشروع.');
                }
            }
        });
        $data = $validator->validate();

        $budget = round((float) $data['execution_budget'], 2);
        $rate = (float) config('gotrainee.platform_commission_rate', 0.10);
        $commission = round($budget * $rate, 2);
        $fee = round((float) config('gotrainee.requirements_gathering_fee', 0), 2);

        return [
            'scope' => trim($data['scope']),
            'deliverables' => array_values(array_map(fn (array $row): array => [
                'title' => trim($row['title']),
                'description' => isset($row['description']) ? trim((string) $row['description']) : null,
            ], $data['deliverables'])),
            'acceptance_criteria' => array_values(array_map('trim', $data['acceptance_criteria'])),
            'currency' => $data['currency'],
            'execution_budget' => $budget,
            'payment_schedule' => array_values(array_map(fn (array $row): array => [
                'label' => trim($row['label']),
                'percentage' => round((float) $row['percentage'], 2),
                'amount' => round($budget * (float) $row['percentage'] / 100, 2),
                'due_date' => $row['due_date'] ?? null,
            ], $data['payment_schedule'])),
            'timeline' => [
                'start_date' => $data['timeline']['start_date'],
                'end_date' => $data['timeline']['end_date'],
                'duration_days' => (int) CarbonImmutable::parse($data['timeline']['start_date'])->diffInDays(CarbonImmutable::parse($data['timeline']['end_date'])),
            ],
            'milestones' => array_values(array_map(fn (array $row): array => [
                'title' => trim($row['title']),
                'due_date' => $row['due_date'],
                'description' => isset($row['description']) ? trim((string) $row['description']) : null,
            ], $data['milestones'])),
            'sprints' => array_values(array_map(fn (array $row): array => [
                'name' => trim($row['name']),
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'goal' => isset($row['goal']) ? trim((string) $row['goal']) : null,
            ], $data['sprints'])),
            // ميزانية التنفيذ للفريق منفصلة عن عمولة المنصة ورسوم جمع المتطلبات؛ الإجمالي على المؤسسة مجموعها
            'financials' => [
                'execution_budget' => $budget,
                'platform_commission_rate' => $rate,
                'platform_commission' => $commission,
                'requirements_gathering_fee' => $fee,
                'organization_total' => round($budget + $commission + $fee, 2),
            ],
        ];
    }

    /**
     * بصمة المحتوى على شكل قانوني: مفاتيح مرتبة وأرقام موحدة، لأن عمود JSON في MySQL يعيد ترتيب المفاتيح ويحوّل
     * 100.0 إلى 100، فتبقى البصمة نفسها بعد القراءة من أي محرك.
     */
    public static function hash(array $content): string
    {
        return hash('sha256', json_encode(self::canonical($content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::canonical(...), $value);
        }
        if (is_int($value) || is_float($value)) {
            return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
        }

        return $value;
    }

    public function saveDraft(ProjectOpportunity $opportunity, User $leader, array $input): ProjectPlanVersion
    {
        $content = $this->normalize($input);

        return DB::transaction(function () use ($opportunity, $leader, $content): ProjectPlanVersion {
            $project = $this->lockedPlannableProject($opportunity);

            $latest = $project->planVersions()->orderByDesc('version')->lockForUpdate()->first();
            if ($latest?->status === ProjectPlanVersion::STATUS_SUBMITTED) {
                throw new ProjectActionException('النسخة المقدمة بانتظار قرار المؤسسة. انتظر قرارها قبل إنشاء نسخة جديدة.', 'awaiting_decision', 409);
            }

            if ($latest?->status === ProjectPlanVersion::STATUS_DRAFT) {
                $latest->forceFill(['content' => $content])->save();

                return $latest;
            }

            return $project->planVersions()->create([
                'version' => ($latest?->version ?? 0) + 1,
                'status' => ProjectPlanVersion::STATUS_DRAFT,
                'content' => $content,
                'created_by_user_id' => $leader->id,
            ]);
        });
    }

    public function submit(ProjectOpportunity $opportunity, User $leader, int $versionId): ProjectPlanVersion
    {
        $version = DB::transaction(function () use ($opportunity, $leader, $versionId): ProjectPlanVersion {
            $project = $this->lockedPlannableProject($opportunity);
            $version = $project->planVersions()->whereKey($versionId)->lockForUpdate()->first();
            if (! $version) {
                throw new ProjectActionException('نسخة الخطة غير موجودة.', 'not_found', 404);
            }
            if (! $version->isEditable()) {
                throw new ProjectActionException('هذه النسخة مقدمة أو مغلقة بالفعل.', 'not_draft', 409);
            }

            // إعادة التحقق عند التقديم: القواعد أو الإعدادات المالية قد تكون تغيرت منذ حفظ المسودة
            $content = $this->normalize($version->content);
            $version->forceFill([
                'content' => $content,
                'content_hash' => self::hash($content),
                'status' => ProjectPlanVersion::STATUS_SUBMITTED,
                'submitted_by_user_id' => $leader->id,
                'submitted_at' => now(),
            ])->save();

            AuditLog::record($leader, 'plan.submitted', $project, ['version' => $version->version, 'content_hash' => $version->content_hash]);

            return $version;
        });

        PlatformNotification::send($opportunity->organization?->user,
            'plan.submitted',
            'خطة تنفيذ بانتظار موافقتك',
            "قدّم قائد الفريق النسخة {$version->version} من خطة «{$opportunity->title}». راجعها ووافق عليها أو اطلب تعديلًا.",
            "/projects/{$opportunity->id}/plan",
            ['project_id' => $opportunity->id, 'version' => $version->version],
        );

        return $version;
    }

    public function decide(ProjectOpportunity $opportunity, User $owner, int $versionId, string $decision, string $contentHash, ?string $note): ProjectPlanVersion
    {
        if (! in_array($decision, ['approve', 'request_changes'], true)) {
            throw new ProjectActionException('قرار غير معروف.', 'unknown_decision', 422);
        }
        $note = $note !== null ? trim($note) : null;
        if ($decision === 'request_changes' && ($note === null || $note === '')) {
            throw new ProjectActionException('اكتب التعديلات المطلوبة ليعرفها قائد الفريق.', 'note_required', 422);
        }

        $version = DB::transaction(function () use ($opportunity, $owner, $versionId, $decision, $contentHash, $note): ProjectPlanVersion {
            $project = $this->lockedPlannableProject($opportunity);
            $version = $project->planVersions()->whereKey($versionId)->lockForUpdate()->first();
            if (! $version) {
                throw new ProjectActionException('نسخة الخطة غير موجودة.', 'not_found', 404);
            }
            $latestSubmitted = $project->planVersions()->where('status', ProjectPlanVersion::STATUS_SUBMITTED)->max('version');
            if ($version->status !== ProjectPlanVersion::STATUS_SUBMITTED || (int) $latestSubmitted !== $version->version) {
                throw new ProjectActionException('هذه النسخة لم تعد بانتظار قرارك. حدّث الصفحة لرؤية أحدث نسخة.', 'stale_version', 409);
            }
            // حماية من الموافقة على محتوى غير الذي رآه المالك (أو تعديل لاحق): البصمة يجب أن تطابق تمامًا
            if (! hash_equals((string) $version->content_hash, $contentHash) || self::hash($version->content) !== $version->content_hash) {
                throw new ProjectActionException('محتوى الخطة تغير منذ أن فتحتها. حدّث الصفحة وراجع النسخة الحالية.', 'stale_content', 409);
            }

            $version->forceFill([
                'status' => $decision === 'approve' ? ProjectPlanVersion::STATUS_APPROVED : ProjectPlanVersion::STATUS_CHANGES_REQUESTED,
                'organization_decision' => $decision,
                'organization_decided_by_user_id' => $owner->id,
                'organization_decided_at' => now(),
                'organization_note' => $note ?: null,
            ])->save();

            if ($decision === 'approve') {
                $project->forceFill(['agreed_plan_version_id' => $version->id, 'agreed_at' => now()])->save();
            }

            AuditLog::record($owner, $decision === 'approve' ? 'plan.approved' : 'plan.changes_requested', $project, [
                'version' => $version->version,
                'content_hash' => $version->content_hash,
                'note' => $note ?: null,
            ]);

            return $version;
        });

        $leader = $opportunity->activeLeaderAssignment()->with('leader')->first()?->leader;
        PlatformNotification::send($leader,
            'plan.'.$decision,
            $decision === 'approve' ? 'وافقت المؤسسة على خطة التنفيذ' : 'طلبت المؤسسة تعديل خطة التنفيذ',
            $decision === 'approve'
                ? "أصبحت النسخة {$version->version} من خطة «{$opportunity->title}» خط الأساس المتفق عليه. يمكنك بدء التنفيذ بعد تأكيد الفريق."
                : "ملاحظات المؤسسة على النسخة {$version->version}: {$note}",
            "/projects/{$opportunity->id}/plan",
            ['project_id' => $opportunity->id, 'version' => $version->version],
        );

        return $version;
    }

    public function comment(ProjectOpportunity $opportunity, User $author, string $authorRole, int $versionId, string $body): ProjectPlanComment
    {
        $version = $opportunity->planVersions()->whereKey($versionId)->first();
        if (! $version) {
            throw new ProjectActionException('نسخة الخطة غير موجودة.', 'not_found', 404);
        }

        return $version->comments()->create([
            'user_id' => $author->id,
            'author_role' => $authorRole,
            'body' => trim($body),
        ]);
    }

    public function startExecution(ProjectOpportunity $opportunity, User $leader): ProjectOpportunity
    {
        return DB::transaction(function () use ($opportunity, $leader): ProjectOpportunity {
            $project = ProjectOpportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            if ($project->project_status === ProjectOpportunity::PROJECT_IN_PROGRESS) {
                throw new ProjectActionException('بدأ تنفيذ المشروع بالفعل.', 'already_started', 409);
            }
            if (! in_array($project->project_status, ProjectOpportunity::TEAM_STAGES, true) || $project->status !== ProjectOpportunity::STATUS_PUBLISHED) {
                throw new ProjectActionException('لا يمكن بدء التنفيذ في حالة المشروع الحالية.', 'invalid_transition', 409);
            }

            $plan = $project->agreed_plan_version_id ? ProjectPlanVersion::query()->find($project->agreed_plan_version_id) : null;
            if (! $plan || $plan->status !== ProjectPlanVersion::STATUS_APPROVED || self::hash($plan->content) !== $plan->content_hash) {
                throw new ProjectActionException('لا يبدأ التنفيذ قبل أن يوافق القائد والمؤسسة على النسخة نفسها من الخطة.', 'plan_not_agreed', 409);
            }

            $team = $project->teams()->where('status', '!=', ProjectTeam::STATUS_CANCELLED)->lockForUpdate()->latest('id')->first();
            if (! $team || $team->status !== ProjectTeam::STATUS_READY) {
                throw new ProjectActionException('أكّد تشكيل الفريق قبل بدء التنفيذ.', 'team_not_ready', 409);
            }

            $team->forceFill(['status' => ProjectTeam::STATUS_ACTIVE, 'status_changed_at' => now()])->save();
            $project->forceFill([
                'project_status' => ProjectOpportunity::PROJECT_IN_PROGRESS,
                'status_changed_at' => now(),
                'execution_started_at' => now(),
            ])->save();
            // مراحل الخطة المتفق عليها تصبح مراحل تتبع التقدم
            app(ProjectExecution::class)->ensureMilestones($project);

            AuditLog::record($leader, 'project.execution_started', $project, ['plan_version' => $plan->version, 'team_id' => $team->id]);

            PlatformNotification::send($project->organization?->user,
                'project.execution_started',
                'بدأ تنفيذ مشروعك',
                "بدأ فريق «{$project->title}» التنفيذ وفق النسخة {$plan->version} المتفق عليها من الخطة.",
                "/organization/projects/{$project->id}",
                ['project_id' => $project->id],
            );

            return $project;
        });
    }

    private function lockedPlannableProject(ProjectOpportunity $opportunity): ProjectOpportunity
    {
        $project = ProjectOpportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
        if ($project->status !== ProjectOpportunity::STATUS_PUBLISHED) {
            throw new ProjectActionException('التخطيط متاح للمشاريع المنشورة فقط.', 'project_not_published', 409);
        }
        if ($project->agreed_plan_version_id !== null) {
            throw new ProjectActionException('تم الاتفاق على الخطة وأصبحت خط الأساس، فلا يمكن تعديلها.', 'plan_agreed', 409);
        }

        return $project;
    }
}
