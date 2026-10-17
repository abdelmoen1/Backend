<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** المشروع المنشور للطلاب. واحد لكل طلب مشروع؛ إعادة الاعتماد تحدّث النسخة النشطة فقط. */
class ProjectOpportunity extends Model
{
    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_WITHDRAWN = 'withdrawn';

    /** بانتظار مراجعة مدير المنصة؛ لا يراه الطلاب */
    public const STATUS_PENDING_REVIEW = 'pending_review';

    /** طلب مدير المنصة تعديلات من المؤسسة قبل النشر */
    public const STATUS_CHANGES_REQUESTED = 'changes_requested';

    /** رفضه مدير المنصة */
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_CHANGES_REQUESTED,
        self::STATUS_REJECTED,
        self::STATUS_PUBLISHED,
        self::STATUS_SUSPENDED,
        self::STATUS_WITHDRAWN,
    ];

    /** حالات المراجعة قبل النشر الأول أو بعد تعديل المقترح */
    public const REVIEW_STATUSES = [self::STATUS_PENDING_REVIEW, self::STATUS_CHANGES_REQUESTED, self::STATUS_REJECTED];

    /*
     * مرحلة المشروع (project_status) منفصلة عن حالة النشر أعلاه: النشر = هل يراه الطلاب،
     * والمرحلة = أين وصل المشروع. الطلاب يقدّمون فقط على مشروع منشور في مرحلة open.
     */
    public const PROJECT_DRAFT = 'draft';

    public const PROJECT_OPEN = 'open';

    public const PROJECT_MATCHING = 'matching';

    public const PROJECT_TEAM_FORMATION = 'team_formation';

    public const PROJECT_IN_PROGRESS = 'in_progress';

    public const PROJECT_COMPLETED = 'completed';

    public const PROJECT_CANCELLED = 'cancelled';

    public const PROJECT_STATUSES = [
        self::PROJECT_DRAFT,
        self::PROJECT_OPEN,
        self::PROJECT_MATCHING,
        self::PROJECT_TEAM_FORMATION,
        self::PROJECT_IN_PROGRESS,
        self::PROJECT_COMPLETED,
        self::PROJECT_CANCELLED,
    ];

    /** الانتقالات التي تملكها المؤسسة يدويًا؛ team_formation يُضبط تلقائيًا عند حفظ الفريق */
    public const MANUAL_TRANSITIONS = [
        self::PROJECT_OPEN => [self::PROJECT_MATCHING],
        self::PROJECT_MATCHING => [self::PROJECT_OPEN],
    ];

    /** مراحل يمكن فيها تكوين الفريق أو تعديله */
    public const TEAM_STAGES = [self::PROJECT_OPEN, self::PROJECT_MATCHING, self::PROJECT_TEAM_FORMATION];

    protected $fillable = [
        'project_request_id',
        'organization_id',
        'active_version_id',
        'agreed_plan_version_id',
        'agreed_at',
        'reviewed_by_user_id',
        'reviewed_at',
        'submitted_for_review_at',
        'execution_started_at',
        'completed_at',
        'status',
        'project_status',
        'title',
        'summary',
        'min_weekly_hours',
        'moderation_note',
        'published_at',
        'status_changed_at',
    ];

    protected $attributes = [
        'project_status' => self::PROJECT_OPEN,
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'min_weekly_hours' => 'integer',
            'organization_id' => 'integer',
            'agreed_plan_version_id' => 'integer',
            'agreed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'submitted_for_review_at' => 'datetime',
            'execution_started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function acceptsApplications(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->project_status === self::PROJECT_OPEN;
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ProjectApplication::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(ProjectTeam::class);
    }

    /** الفريق القائم (غير الملغى) للمشروع؛ واحد على الأكثر */
    public function currentTeam(): ?ProjectTeam
    {
        return $this->teams()->where('status', '!=', ProjectTeam::STATUS_CANCELLED)->latest('id')->first();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function projectRequest(): BelongsTo
    {
        return $this->belongsTo(ProjectRequest::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(OrganizationProfile::class, 'organization_id');
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(ProjectProposalVersion::class, 'active_version_id');
    }

    public function leaderAssignments(): HasMany
    {
        return $this->hasMany(ProjectLeaderAssignment::class);
    }

    /** قائد الفريق الساري لهذا المشروع (تعيين من مدير المنصة) */
    public function activeLeaderAssignment(): HasOne
    {
        return $this->hasOne(ProjectLeaderAssignment::class)->whereNotNull('active_project_opportunity_id');
    }

    public function isLedBy(User $user): bool
    {
        return $this->leaderAssignments()->active()->where('leader_user_id', $user->id)->exists();
    }

    public function planVersions(): HasMany
    {
        return $this->hasMany(ProjectPlanVersion::class);
    }

    public function agreedPlan(): BelongsTo
    {
        return $this->belongsTo(ProjectPlanVersion::class, 'agreed_plan_version_id');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('position');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    public function clientFeedback(): HasOne
    {
        return $this->hasOne(ProjectClientFeedback::class);
    }
}
