<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntakeResponse extends Model
{
    protected $fillable = [
        'intake_session_id',
        'sequence',
        'question_id',
        'question_text',
        'question_type',
        'options',
        'selected_answer',
        'custom_answer',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'selected_answer' => 'array',
            'answered_at' => 'datetime',
        ];
    }

    public function intakeSession(): BelongsTo
    {
        return $this->belongsTo(IntakeSession::class);
    }
}
