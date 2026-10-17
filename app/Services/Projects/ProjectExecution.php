<?php

namespace App\Services\Projects;

use App\Models\AuditLog;
use App\Models\PlatformNotification;
use App\Models\ProjectClientFeedback;
use App\Models\ProjectDeliverable;
use App\Models\ProjectMemberEvaluation;
use App\Models\ProjectMilestone;
use App\Models\ProjectOpportunity;
use App\Models\ProjectPlanVersion;
use App\Models\ProjectTask;
use App\Models\ProjectTeam;
use App\Models\ProjectTeamMember;
use App\Models\StudentEvidence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * مرحلة التنفيذ بعد الاتفاق على الخطة (FR-25 حتى FR-32).
 *
 * - مراحل المشروع تُنسخ من الخطة المتفق عليها عند بدء التنفيذ، والقائد يحدّث حالتها.
 * - القائد ينشئ المهام ويسندها لأعضاء الفريق فقط، بتواريخ ضمن مدة الخطة.
 * - العضو المسند إليه وحده يسلّم؛ القائد يقبل التسليم أو يطلب تعديلًا بملاحظات.
 * - إكمال المشروع يتطلب إغلاق كل المهام والمراحل، وينشئ أدلة الخبرة للمشاركين فعلًا فقط.
 * - بعد الإكمال: تقييم القائد لكل عضو (مرة واحدة) وتقييم المؤسسة للمشروع (مرة واحدة)، وكلاهما يُضاف إلى الأدلة.
 */
class ProjectExecution
{
    /** ينسخ مراحل الخطة المتفق عليها إلى جدول المراحل مرة واحدة. يُستدعى داخل معاملة تقفل صف المشروع. */
    public function ensureMilestones(ProjectOpportunity $project): void
    {
        if ($project->agreed_plan_version_id === null || $project->milestones()->exists()) {
            return;
        }
        $plan = ProjectPlanVersion::query()->find($project->agreed_plan_version_id);
        foreach (array_values($plan?->content['milestones'] ?? []) as $index => $milestone) {
            $project->milestones()->create([
                'project_plan_version_id' => $plan->id,
                'position' => $index + 1,
                'title' => $milestone['title'],
                'description' => $milestone['description'] ?? null,
                'due_date' => $milestone['due_date'],
                'status' => ProjectMilestone::STATUS_PENDING,
            ]);
        }
    }

    /** للمشاريع التي بدأ تنفيذها قبل وجود جدول المراحل: تُنسخ المراحل عند أول فتح لمساحة التنفيذ. */
    public function ensureMilestonesFor(ProjectOpportunity $project): void
    {
        if (! in_array($project->project_status, [ProjectOpportunity::PROJECT_IN_PROGRESS, ProjectOpportunity::PROJECT_COMPLETED], true)
            || $project->milestones()->exists()) {
            return;
        }
        DB::transaction(function () use ($project): void {
            $this->ensureMilestones(ProjectOpportunity::query()->whereKey($project->id)->lockForUpdate()->firstOrFail());
        });
    }

    public function createTask(ProjectOpportunity $opportunity, User $leader, array $input): ProjectTask
    {
        $task = DB::transaction(function () use ($opportunity, $leader, $input): ProjectTask {
            $project = $this->lockedActiveProject($opportunity);
            $team = $this->executionTeam($project);
            $data = $this->validateTask($project, $team, $input, creating: true);

            $task = $project->tasks()->create([
                'project_team_id' => $team->id,
                'project_milestone_id' => $data['milestone_id'] ?? null,
                'assignee_member_id' => $data['assignee_member_id'] ?? null,
                'title' => trim($data['title']),
                'description' => isset($data['description']) ? trim((string) $data['description']) : null,
                'priority' => $data['priority'] ?? 'medium',
                'due_date' => $data['due_date'] ?? null,
                'status' => ProjectTask::STATUS_TODO,
                'created_by_user_id' => $leader->id,
            ]);
            AuditLog::record($leader, 'task.created', $task, ['project_id' => $project->id, 'assignee_member_id' => $task->assignee_member_id]);

            return $task;
        });

        $this->notifyAssignee($task, $opportunity);

        return $task;
    }

    public function updateTask(ProjectOpportunity $opportunity, User $leader, int $taskId, array $input): ProjectTask
    {
        [$task, $reassigned] = DB::transaction(function () use ($opportunity, $leader, $taskId, $input): array {
            $project = $this->lockedActiveProject($opportunity);
            $team = $this->executionTeam($project);
            $task = $project->tasks()->whereKey($taskId)->lockForUpdate()->first();
            if (! $task) {
                throw new ProjectActionException('المهمة غير موجودة في هذا المشروع.', 'not_found', 404);
            }
            $data = $this->validateTask($project, $team, $input, creating: false);

            if (array_key_exists('status', $data) && $data['status'] !== $task->status) {
                if ($task->status === ProjectTask::STATUS_IN_REVIEW) {
                    throw new ProjectActionException('للمهمة تسليم بانتظار مراجعتك. راجعه أولًا.', 'pending_review', 409);
                }
                if (in_array($task->status, [ProjectTask::STATUS_DONE, ProjectTask::STATUS_CANCELLED], true)
                    && $data['status'] !== ProjectTask::STATUS_TODO && $data['status'] !== ProjectTask::STATUS_IN_PROGRESS) {
                    throw new ProjectActionException('المهمة مغلقة. أعد فتحها أولًا.', 'invalid_transition', 409);
                }
            }

            $previousAssignee = $task->assignee_member_id;
            $changes = [];
            foreach (['title', 'description', 'priority', 'due_date', 'status'] as $field) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
                }
            }
            if (array_key_exists('milestone_id', $data)) {
                $changes['project_milestone_id'] = $data['milestone_id'];
            }
            if (array_key_exists('assignee_member_id', $data)) {
                $changes['assignee_member_id'] = $data['assignee_member_id'];
            }
            if (isset($changes['status'])) {
                $changes['completed_at'] = $changes['status'] === ProjectTask::STATUS_DONE ? now() : null;
            }
            $task->forceFill($changes)->save();
            AuditLog::record($leader, 'task.updated', $task, ['project_id' => $project->id, 'fields' => array_keys($changes)]);

            return [$task, $task->assignee_member_id !== null && $task->assignee_member_id !== $previousAssignee];
        });

        if ($reassigned) {
            $this->notifyAssignee($task, $opportunity);
        }

        return $task;
    }

    public function updateMilestone(ProjectOpportunity $opportunity, User $leader, int $milestoneId, string $status): ProjectMilestone
    {
        if (! in_array($status, ProjectMilestone::STATUSES, true)) {
            throw new ProjectActionException('حالة المرحلة غير معروفة.', 'unknown_status', 422);
        }

        $milestone = DB::transaction(function () use ($opportunity, $leader, $milestoneId, $status): ProjectMilestone {
            $project = $this->lockedActiveProject($opportunity);
            $milestone = $project->milestones()->whereKey($milestoneId)->lockForUpdate()->first();
            if (! $milestone) {
                throw new ProjectActionException('المرحلة غير موجودة في هذا المشروع.', 'not_found', 404);
            }
            if ($status === ProjectMilestone::STATUS_COMPLETED
                && $milestone->tasks()->whereIn('status', ProjectTask::OPEN_STATUSES)->exists()) {
                throw new ProjectActionException('أغلق مهام هذه المرحلة قبل اعتبارها مكتملة.', 'open_tasks', 409);
            }
            $milestone->forceFill([
                'status' => $status,
                'completed_at' => $status === ProjectMilestone::STATUS_COMPLETED ? now() : null,
                'completed_by_user_id' => $status === ProjectMilestone::STATUS_COMPLETED ? $leader->id : null,
            ])->save();
            AuditLog::record($leader, 'milestone.'.$status, $milestone, ['project_id' => $project->id]);

            return $milestone;
        });

        if ($status === ProjectMilestone::STATUS_COMPLETED) {
            PlatformNotification::send($opportunity->organization?->user,
                'milestone.completed',
                'اكتملت مرحلة في مشروعك',
                "أنهى فريق «{$opportunity->title}» مرحلة «{$milestone->title}».",
                "/projects/{$opportunity->id}/execution",
                ['project_id' => $opportunity->id, 'milestone_id' => $milestone->id],
            );
        }

        return $milestone;
    }

    public function submitDeliverable(ProjectOpportunity $opportunity, ProjectTeamMember $member, User $student, int $taskId, array $input): ProjectDeliverable
    {
        $data = Validator::make($input, [
            'reference_url' => ['nullable', 'url:https,http', 'max:2048'],
            'notes' => ['required', 'string', 'min:5', 'max:5000'],
        ], [
            'reference_url.url' => 'رابط التسليم يجب أن يبدأ بـ https:// أو http://.',
            'notes.required' => 'اكتب وصفًا لما سلّمته.',
        ])->validate();

        $deliverable = DB::transaction(function () use ($opportunity, $member, $student, $taskId, $data): ProjectDeliverable {
            $project = $this->lockedActiveProject($opportunity);
            $task = $project->tasks()->whereKey($taskId)->lockForUpdate()->first();
            // مهمة غير مسندة إليه = غير موجودة بالنسبة له
            if (! $task || $task->assignee_member_id !== $member->id) {
                throw new ProjectActionException('المهمة غير موجودة أو غير مسندة إليك.', 'not_found', 404);
            }
            if (! in_array($task->status, ProjectTask::SUBMITTABLE_STATUSES, true)) {
                throw new ProjectActionException($task->status === ProjectTask::STATUS_IN_REVIEW
                    ? 'تسليمك السابق بانتظار مراجعة القائد.'
                    : 'هذه المهمة لا تقبل تسليمًا الآن.', 'not_submittable', 409);
            }

            $deliverable = $task->deliverables()->create([
                'project_team_member_id' => $member->id,
                'submitted_by_user_id' => $student->id,
                'revision' => (int) $task->deliverables()->max('revision') + 1,
                'reference_url' => $data['reference_url'] ?? null,
                'notes' => trim($data['notes']),
                'status' => ProjectDeliverable::STATUS_PENDING_REVIEW,
            ]);
            $task->forceFill(['status' => ProjectTask::STATUS_IN_REVIEW])->save();
            AuditLog::record($student, 'deliverable.submitted', $deliverable, ['project_id' => $project->id, 'task_id' => $task->id, 'revision' => $deliverable->revision]);

            return $deliverable;
        });

        $leader = $opportunity->activeLeaderAssignment()->with('leader')->first()?->leader;
        PlatformNotification::send($leader,
            'deliverable.submitted',
            'تسليم جديد بانتظار مراجعتك',
            "سلّم {$student->name} مهمة «{$deliverable->task->title}» في مشروع «{$opportunity->title}».",
            "/projects/{$opportunity->id}/execution",
            ['project_id' => $opportunity->id, 'task_id' => $deliverable->project_task_id, 'deliverable_id' => $deliverable->id],
        );

        return $deliverable;
    }

    public function reviewDeliverable(ProjectOpportunity $opportunity, User $leader, int $deliverableId, string $decision, ?string $feedback): ProjectDeliverable
    {
        if (! in_array($decision, ['accept', 'request_revision'], true)) {
            throw new ProjectActionException('قرار غير معروف.', 'unknown_decision', 422);
        }
        $feedback = $feedback !== null ? trim($feedback) : null;
        if ($decision === 'request_revision' && ($feedback === null || $feedback === '')) {
            throw new ProjectActionException('اكتب التعديلات المطلوبة ليعرفها العضو.', 'feedback_required', 422);
        }

        $deliverable = DB::transaction(function () use ($opportunity, $leader, $deliverableId, $decision, $feedback): ProjectDeliverable {
            $project = $this->lockedActiveProject($opportunity);
            $deliverable = ProjectDeliverable::query()
                ->whereKey($deliverableId)
                ->whereHas('task', fn ($query) => $query->where('project_opportunity_id', $project->id))
                ->lockForUpdate()
                ->first();
            if (! $deliverable) {
                throw new ProjectActionException('التسليم غير موجود في هذا المشروع.', 'not_found', 404);
            }
            if ($deliverable->status !== ProjectDeliverable::STATUS_PENDING_REVIEW) {
                throw new ProjectActionException('تمت مراجعة هذا التسليم بالفعل.', 'already_reviewed', 409);
            }
            $task = ProjectTask::query()->whereKey($deliverable->project_task_id)->lockForUpdate()->firstOrFail();

            $deliverable->forceFill([
                'status' => $decision === 'accept' ? ProjectDeliverable::STATUS_ACCEPTED : ProjectDeliverable::STATUS_REVISION_REQUESTED,
                'feedback' => $feedback ?: null,
                'reviewed_by_user_id' => $leader->id,
                'reviewed_at' => now(),
            ])->save();
            $task->forceFill($decision === 'accept'
                ? ['status' => ProjectTask::STATUS_DONE, 'completed_at' => now()]
                : ['status' => ProjectTask::STATUS_CHANGES_REQUESTED, 'completed_at' => null])->save();
            AuditLog::record($leader, 'deliverable.'.($decision === 'accept' ? 'accepted' : 'revision_requested'), $deliverable, [
                'project_id' => $project->id,
                'task_id' => $task->id,
            ]);

            return $deliverable;
        });

        $deliverable->load(['task', 'member.studentProfile.user']);
        PlatformNotification::send($deliverable->member?->studentProfile?->user,
            'deliverable.'.($decision === 'accept' ? 'accepted' : 'revision_requested'),
            $decision === 'accept' ? 'قُبل تسليمك' : 'مطلوب تعديل على تسليمك',
            $decision === 'accept'
                ? "قبل قائد الفريق تسليمك لمهمة «{$deliverable->task->title}»."
                : "ملاحظات القائد على مهمة «{$deliverable->task->title}»: {$feedback}",
            "/student/projects/{$opportunity->id}",
            ['project_id' => $opportunity->id, 'task_id' => $deliverable->project_task_id],
        );

        return $deliverable;
    }

    /** إكمال المشروع: كل المهام مغلقة وكل المراحل مكتملة. ينشئ أدلة الخبرة للأعضاء والقائد. */
    public function complete(ProjectOpportunity $opportunity, User $leader): ProjectOpportunity
    {
        $project = DB::transaction(function () use ($opportunity, $leader): ProjectOpportunity {
            $project = $this->lockedActiveProject($opportunity);
            $team = $this->executionTeam($project);
            $this->ensureMilestones($project);

            $openTasks = $project->tasks()->whereIn('status', ProjectTask::OPEN_STATUSES)->count();
            if ($openTasks > 0) {
                throw new ProjectActionException("ما زالت {$openTasks} مهمة مفتوحة. أغلقها أو ألغها قبل إكمال المشروع.", 'open_tasks', 409);
            }
            if ($project->milestones()->where('status', '!=', ProjectMilestone::STATUS_COMPLETED)->exists()) {
                throw new ProjectActionException('علّم كل مراحل الخطة مكتملة قبل إكمال المشروع.', 'open_milestones', 409);
            }

            $project->forceFill([
                'project_status' => ProjectOpportunity::PROJECT_COMPLETED,
                'status_changed_at' => now(),
                'completed_at' => now(),
            ])->save();
            $team->forceFill(['status' => ProjectTeam::STATUS_COMPLETED, 'status_changed_at' => now()])->save();
            $issued = $this->issueEvidence($project, $team, $leader);
            AuditLog::record($leader, 'project.completed', $project, ['team_id' => $team->id, 'evidence_issued' => $issued]);

            return $project;
        });

        PlatformNotification::send($opportunity->organization?->user,
            'project.completed',
            'اكتمل مشروعك',
            "أعلن قائد الفريق اكتمال «{$opportunity->title}». شاركنا تقييمك النهائي للمشروع.",
            "/projects/{$opportunity->id}/execution",
            ['project_id' => $opportunity->id],
        );
        foreach ($this->executionTeam($project, completed: true)->members()->with('studentProfile.user')->get() as $member) {
            PlatformNotification::send($member->studentProfile?->user,
                'project.completed',
                'اكتمل المشروع وأُضيف إلى سجل خبرتك',
                "اكتمل «{$opportunity->title}» وأُضيفت مشاركتك إلى سجل أدلة الخبرة.",
                '/student/evidence',
                ['project_id' => $opportunity->id],
            );
        }

        return $project;
    }

    public function evaluateMember(ProjectOpportunity $opportunity, User $leader, array $input): ProjectMemberEvaluation
    {
        $data = Validator::make($input, [
            'member_id' => ['required', 'integer'],
            'criteria' => ['required', 'array'],
            ...collect(ProjectMemberEvaluation::CRITERIA)->mapWithKeys(fn (string $key): array => ["criteria.$key" => ['required', 'integer', 'between:1,5']])->all(),
            'comments' => ['required', 'string', 'min:10', 'max:3000'],
        ], [
            'comments.required' => 'اكتب تقييمًا نوعيًا لأداء العضو.',
            'comments.min' => 'التقييم النوعي قصير جدًا.',
        ])->validate();

        $evaluation = DB::transaction(function () use ($opportunity, $leader, $data): ProjectMemberEvaluation {
            $project = ProjectOpportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            if ($project->project_status !== ProjectOpportunity::PROJECT_COMPLETED) {
                throw new ProjectActionException('يُسجَّل تقييم الأعضاء بعد إكمال المشروع.', 'evaluation_not_open', 409);
            }
            $team = $this->executionTeam($project, completed: true);
            $member = $team->members()->whereKey($data['member_id'])->first();
            if (! $member) {
                throw new ProjectActionException('العضو غير موجود في فريق هذا المشروع.', 'not_found', 404);
            }
            if (ProjectMemberEvaluation::query()->where('project_team_member_id', $member->id)->exists()) {
                throw new ProjectActionException('سُجّل تقييم هذا العضو بالفعل.', 'already_evaluated', 409);
            }

            $criteria = collect(ProjectMemberEvaluation::CRITERIA)->mapWithKeys(fn (string $key): array => [$key => (int) $data['criteria'][$key]])->all();
            $evaluation = ProjectMemberEvaluation::create([
                'project_opportunity_id' => $project->id,
                'project_team_member_id' => $member->id,
                'evaluator_user_id' => $leader->id,
                'overall_score' => (int) round(array_sum($criteria) / count($criteria)),
                'criteria' => $criteria,
                'comments' => trim($data['comments']),
            ]);
            StudentEvidence::query()
                ->where('project_opportunity_id', $project->id)
                ->where('project_team_member_id', $member->id)
                ->update(['member_evaluation_id' => $evaluation->id]);
            AuditLog::record($leader, 'member.evaluated', $evaluation, ['project_id' => $project->id, 'member_id' => $member->id]);

            return $evaluation;
        });

        $evaluation->load('member.studentProfile.user');
        PlatformNotification::send($evaluation->member?->studentProfile?->user,
            'evaluation.recorded',
            'سجّل قائد الفريق تقييم أدائك',
            "أُضيف تقييم أدائك في «{$opportunity->title}» إلى سجل أدلة الخبرة.",
            '/student/evidence',
            ['project_id' => $opportunity->id],
        );

        return $evaluation;
    }

    public function submitClientFeedback(ProjectOpportunity $opportunity, User $owner, array $input): ProjectClientFeedback
    {
        $data = Validator::make($input, [
            'satisfaction_score' => ['required', 'integer', 'between:1,5'],
            'leader_score' => ['nullable', 'integer', 'between:1,5'],
            'would_recommend' => ['required', 'boolean'],
            'comments' => ['required', 'string', 'min:10', 'max:3000'],
        ], [
            'comments.required' => 'اكتب رأيك في نتيجة المشروع.',
        ])->validate();

        $feedback = DB::transaction(function () use ($opportunity, $owner, $data): ProjectClientFeedback {
            $project = ProjectOpportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            if ($project->project_status !== ProjectOpportunity::PROJECT_COMPLETED) {
                throw new ProjectActionException('يُتاح التقييم النهائي بعد اكتمال المشروع.', 'feedback_not_open', 409);
            }
            if ($project->clientFeedback()->exists()) {
                throw new ProjectActionException('أرسلت تقييمك لهذا المشروع بالفعل.', 'already_submitted', 409);
            }
            $feedback = $project->clientFeedback()->create([
                'organization_user_id' => $owner->id,
                'satisfaction_score' => (int) $data['satisfaction_score'],
                'leader_score' => isset($data['leader_score']) ? (int) $data['leader_score'] : null,
                'would_recommend' => (bool) $data['would_recommend'],
                'comments' => trim($data['comments']),
            ]);
            StudentEvidence::query()->where('project_opportunity_id', $project->id)
                ->update(['client_satisfaction_score' => $feedback->satisfaction_score]);
            AuditLog::record($owner, 'project.client_feedback', $project, ['satisfaction_score' => $feedback->satisfaction_score]);

            return $feedback;
        });

        $leader = $opportunity->leaderAssignments()->with('leader')->latest('assigned_at')->first()?->leader;
        PlatformNotification::send($leader,
            'project.client_feedback',
            'أرسلت المؤسسة تقييمها النهائي',
            "قيّمت المؤسسة رضاها عن «{$opportunity->title}» بـ {$feedback->satisfaction_score} من 5.",
            "/projects/{$opportunity->id}/execution",
            ['project_id' => $opportunity->id],
        );

        return $feedback;
    }

    /** فريق التنفيذ: active أثناء التنفيذ، أو completed بعد الإكمال. */
    public function executionTeam(ProjectOpportunity $project, bool $completed = false): ProjectTeam
    {
        $team = $project->teams()
            ->whereIn('status', $completed ? [ProjectTeam::STATUS_COMPLETED] : [ProjectTeam::STATUS_ACTIVE])
            ->latest('id')
            ->first();
        if (! $team) {
            throw new ProjectActionException('لا يوجد فريق تنفيذ لهذا المشروع.', 'team_not_active', 409);
        }

        return $team;
    }

    private function lockedActiveProject(ProjectOpportunity $opportunity): ProjectOpportunity
    {
        $project = ProjectOpportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
        if ($project->project_status !== ProjectOpportunity::PROJECT_IN_PROGRESS || $project->status !== ProjectOpportunity::STATUS_PUBLISHED) {
            throw new ProjectActionException('هذا الإجراء متاح أثناء تنفيذ المشروع فقط.', 'project_not_in_progress', 409);
        }

        return $project;
    }

    private function validateTask(ProjectOpportunity $project, ProjectTeam $team, array $input, bool $creating): array
    {
        $timeline = ProjectPlanVersion::query()->find($project->agreed_plan_version_id)?->content['timeline'] ?? [];
        $required = $creating ? 'required' : 'sometimes';

        return Validator::make($input, [
            'title' => [$required, 'string', 'min:3', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::in(ProjectTask::PRIORITIES)],
            'status' => [$creating ? 'prohibited' : 'sometimes', Rule::in(ProjectTask::MANUAL_STATUSES)],
            'due_date' => array_values(array_filter([
                'sometimes', 'nullable', 'date_format:Y-m-d',
                isset($timeline['start_date']) ? 'after_or_equal:'.$timeline['start_date'] : null,
                isset($timeline['end_date']) ? 'before_or_equal:'.$timeline['end_date'] : null,
            ])),
            'milestone_id' => ['sometimes', 'nullable', 'integer', Rule::exists('project_milestones', 'id')->where('project_opportunity_id', $project->id)],
            // الإسناد لعضو في فريق هذا المشروع فقط (قاعدة سلامة البيانات 4.4)
            'assignee_member_id' => ['sometimes', 'nullable', 'integer', Rule::exists('project_team_members', 'id')->where('project_team_id', $team->id)],
        ], [
            'due_date.after_or_equal' => 'موعد المهمة يجب أن يقع ضمن مدة الخطة المتفق عليها.',
            'due_date.before_or_equal' => 'موعد المهمة يجب أن يقع ضمن مدة الخطة المتفق عليها.',
            'assignee_member_id.exists' => 'يمكن إسناد المهمة لأعضاء فريق هذا المشروع فقط.',
            'milestone_id.exists' => 'المرحلة غير موجودة في هذا المشروع.',
            'status.in' => 'هذه الحالة تُضبط عبر التسليم والمراجعة فقط.',
        ])->validate();
    }

    private function notifyAssignee(ProjectTask $task, ProjectOpportunity $project): void
    {
        if ($task->assignee_member_id === null) {
            return;
        }
        $task->loadMissing('assignee.studentProfile.user');
        PlatformNotification::send($task->assignee?->studentProfile?->user,
            'task.assigned',
            'أُسندت إليك مهمة جديدة',
            "أسند إليك قائد الفريق مهمة «{$task->title}» في مشروع «{$project->title}».",
            "/student/projects/{$project->id}",
            ['project_id' => $project->id, 'task_id' => $task->id],
        );
    }

    /** أدلة الخبرة لكل عضو في الفريق ولقائده عند الإكمال؛ لا دليل لمن لم يشارك (قاعدة 4.4). */
    private function issueEvidence(ProjectOpportunity $project, ProjectTeam $team, User $leader): int
    {
        $organization = $project->organization?->organization_name;
        $doneTasks = $project->tasks()->where('status', ProjectTask::STATUS_DONE)->orderBy('id')->get();
        $issued = 0;

        foreach ($team->members()->with(['role.skills.skill:id,name'])->get() as $member) {
            $tasks = $doneTasks->where('assignee_member_id', $member->id)->pluck('title')->values()->all();
            StudentEvidence::query()->updateOrCreate(
                ['student_profile_id' => $member->student_profile_id, 'project_opportunity_id' => $project->id],
                [
                    'project_team_member_id' => $member->id,
                    'participation' => StudentEvidence::PARTICIPATION_MEMBER,
                    'role_title' => $member->role?->title,
                    'summary' => trim("شارك بدور {$member->role?->title} في مشروع «{$project->title}»".($organization ? " لصالح {$organization}" : '').' وأنجز '.count($tasks).' مهمة مقبولة.'),
                    'skills_used' => $member->role?->skills->map(fn ($roleSkill) => $roleSkill->skill?->name)->filter()->unique()->values()->all() ?? [],
                    'completed_tasks' => $tasks,
                    'completed_tasks_count' => count($tasks),
                    'final_result' => 'completed',
                    'issued_at' => now(),
                ],
            );
            $issued++;
        }

        if ($leader->studentProfile) {
            StudentEvidence::query()->updateOrCreate(
                ['student_profile_id' => $leader->studentProfile->id, 'project_opportunity_id' => $project->id],
                [
                    'project_team_member_id' => null,
                    'participation' => StudentEvidence::PARTICIPATION_LEADER,
                    'role_title' => 'قائد الفريق',
                    'summary' => "قاد فريق مشروع «{$project->title}»".($organization ? " لصالح {$organization}" : '')." حتى الإكمال، وأدار {$doneTasks->count()} مهمة منجزة.",
                    'skills_used' => [],
                    'completed_tasks' => [],
                    'completed_tasks_count' => 0,
                    'final_result' => 'completed',
                    'issued_at' => now(),
                ],
            );
            $issued++;
        }

        return $issued;
    }
}
