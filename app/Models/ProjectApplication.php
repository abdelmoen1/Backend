<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** طلب انضمام طالب لمشروع؛ واحد لكل طالب في كل مشروع (قيد فريد في قاعدة البيانات). */
class ProjectApplication extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_REVIEWED, self::STATUS_ACCEPTED, self::STATUS_REJECTED, self::STATUS_WITHDRAWN];

    /** طلبات ما زالت قائمة ويمكن اختيار أصحابها للفريق */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_REVIEWED, self::STATUS_ACCEPTED];

    protected $fillable = [
        'project_opportunity_id',
        'student_profile_id',
        'preferred_role_id',
        'status',
        'note',
        'status_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'project_opportunity_id' => 'integer',
            'student_profile_id' => 'integer',
            'preferred_role_id' => 'integer',
            'status_changed_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(ProjectOpportunity::class, 'project_opportunity_id');
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function preferredRole(): BelongsTo
    {
        return $this->belongsTo(ProjectRole::class, 'preferred_role_id');
    }
}
