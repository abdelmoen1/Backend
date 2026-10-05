<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntakeSession extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'project_request_id',
        'status',
        'current_question_id',
        'current_state',
    ];

    protected function casts(): array
    {
        return [
            'current_state' => 'array',
        ];
    }

    public function projectRequest(): BelongsTo
    {
        return $this->belongsTo(ProjectRequest::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(IntakeResponse::class)->orderBy('sequence');
    }
}
