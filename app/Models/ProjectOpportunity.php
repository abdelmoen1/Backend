<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** المشروع المنشور للطلاب. واحد لكل طلب مشروع؛ إعادة الاعتماد تحدّث النسخة النشطة فقط. */
class ProjectOpportunity extends Model
{
    public const STATUS_PUBLISHED = 'published';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUSES = [self::STATUS_PUBLISHED, self::STATUS_SUSPENDED, self::STATUS_WITHDRAWN];

    protected $fillable = [
        'project_request_id',
        'organization_id',
        'active_version_id',
        'status',
        'title',
        'summary',
        'moderation_note',
        'published_at',
        'status_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
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
}
