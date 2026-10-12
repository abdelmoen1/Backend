<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** إجابة الطالب كما كتبها حرفيًا، ونتيجة تصحيحها بعد التحقق منها في الخادم. */
class AssessmentSessionAnswer extends Model
{
    public const GRADE_PENDING = 'pending';

    public const GRADE_GRADED = 'graded';

    /** تكرر فشل التصحيح؛ لا تُحتسب لصالح المهارة ولا ضدها كأداء */
    public const GRADE_UNGRADABLE = 'ungradable';

    protected $fillable = [
        'assessment_session_id',
        'question_id',
        'position',
        'skill_id',
        'answer',
        'submitted_at',
        'grade_status',
        'grading_failures',
        'grade',
        'awarded_points',
        'max_points',
        'graded_at',
    ];

    protected $hidden = ['grade'];

    protected function casts(): array
    {
        return [
            'grade' => 'array',
            'position' => 'integer',
            'skill_id' => 'integer',
            'grading_failures' => 'integer',
            'awarded_points' => 'integer',
            'max_points' => 'integer',
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AssessmentSession::class, 'assessment_session_id');
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
