<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** فريق المشروع. القائد عمود واحد (قائد واحد فقط) ويجب أن يكون عضوًا في الفريق نفسه. */
class ProjectTeam extends Model
{
    public const STATUS_FORMING = 'forming';

    public const STATUS_READY = 'ready';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_FORMING, self::STATUS_READY, self::STATUS_ACTIVE, self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    /** فريق قائم يشغل وقت أعضائه (يُحتسب في توفر الطالب لمشاريع أخرى) */
    public const COMMITTED_STATUSES = [self::STATUS_FORMING, self::STATUS_READY, self::STATUS_ACTIVE];

    protected $fillable = [
        'project_opportunity_id',
        'status',
        'leader_student_profile_id',
        'leader_assigned_at',
        'leader_assigned_by_user_id',
        'created_by_user_id',
        'status_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'leader_student_profile_id' => 'integer',
            'project_opportunity_id' => 'integer',
            'leader_assigned_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(ProjectOpportunity::class, 'project_opportunity_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectTeamMember::class);
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'leader_student_profile_id');
    }
}
