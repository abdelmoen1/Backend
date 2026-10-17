<?php

namespace App\Services\Projects;

use App\Enums\ProjectRequestStatus;
use App\Models\AuditLog;
use App\Models\PlatformNotification;
use App\Models\ProjectOpportunity;
use App\Models\ProjectRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * قرارات مدير المنصة على طلبات المؤسسات المعتمدة: نشر (قبول) أو رفض أو طلب تعديل، ثم رقابة ما بعد النشر
 * (تعليق، سحب، إعادة نشر). كل قرار يُقفل صف المشروع، يتحقق من الانتقال المسموح، ويُسجل في سجل التدقيق.
 */
class ProjectReview
{
    public const DECISIONS = ['approve', 'reject', 'request_changes', 'suspend', 'withdraw', 'republish'];

    /** الانتقال المسموح لكل قرار: الحالات المصدر => الحالة الهدف */
    private const TRANSITIONS = [
        'approve' => [[ProjectOpportunity::STATUS_PENDING_REVIEW], ProjectOpportunity::STATUS_PUBLISHED],
        'reject' => [[ProjectOpportunity::STATUS_PENDING_REVIEW, ProjectOpportunity::STATUS_CHANGES_REQUESTED], ProjectOpportunity::STATUS_REJECTED],
        'request_changes' => [[ProjectOpportunity::STATUS_PENDING_REVIEW], ProjectOpportunity::STATUS_CHANGES_REQUESTED],
        'suspend' => [[ProjectOpportunity::STATUS_PUBLISHED], ProjectOpportunity::STATUS_SUSPENDED],
        'withdraw' => [[ProjectOpportunity::STATUS_PUBLISHED, ProjectOpportunity::STATUS_SUSPENDED], ProjectOpportunity::STATUS_WITHDRAWN],
        'republish' => [[ProjectOpportunity::STATUS_SUSPENDED], ProjectOpportunity::STATUS_PUBLISHED],
    ];

    /** القرارات التي يجب أن تُبرَّر للمؤسسة */
    private const NOTE_REQUIRED = ['reject', 'request_changes', 'suspend', 'withdraw'];

    public function decide(ProjectOpportunity $opportunity, User $admin, string $decision, ?string $note): ProjectOpportunity
    {
        if (! isset(self::TRANSITIONS[$decision])) {
            throw new ProjectActionException('قرار غير معروف.', 'unknown_decision', 422);
        }
        if (in_array($decision, self::NOTE_REQUIRED, true) && trim((string) $note) === '') {
            throw new ProjectActionException('اكتب سبب القرار ليصل إلى المؤسسة.', 'note_required', 422);
        }

        $record = DB::transaction(function () use ($opportunity, $admin, $decision, $note): ProjectOpportunity {
            $record = ProjectOpportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
            [$from, $to] = self::TRANSITIONS[$decision];
            if (! in_array($record->status, $from, true)) {
                throw new ProjectActionException('لا يمكن اتخاذ هذا القرار على المشروع في حالته الحالية.', 'invalid_transition', 409);
            }

            $previous = $record->status;
            $record->forceFill([
                'status' => $to,
                'status_changed_at' => now(),
                'moderation_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
            ]);
            if ($to === ProjectOpportunity::STATUS_PUBLISHED) {
                $record->published_at ??= now();
            }
            $record->save();

            // طلب التعديل يعيد الطلب إلى المؤسسة بالمسار نفسه الذي تستخدمه لتعديل المقترح (FR-20)
            if ($decision === 'request_changes' || $decision === 'reject') {
                $request = ProjectRequest::query()->whereKey($record->project_request_id)->lockForUpdate()->first();
                if ($request) {
                    $request->status = $decision === 'request_changes' ? ProjectRequestStatus::REVISION_REQUESTED : ProjectRequestStatus::REJECTED;
                    $request->revision_note = trim((string) $note);
                    $request->save();
                }
            }

            AuditLog::record($admin, 'project.review.'.$decision, $record, [
                'from' => $previous,
                'to' => $to,
                'note' => $record->moderation_note,
            ]);

            return $record;
        });

        $this->notifyOrganization($record, $decision);

        return $record;
    }

    private function notifyOrganization(ProjectOpportunity $record, string $decision): void
    {
        $owner = $record->organization?->user;
        if (! $owner) {
            return;
        }

        [$title, $body] = match ($decision) {
            'approve' => ['تمت الموافقة على مشروعك', "نُشر مشروع «{$record->title}» للطلاب بعد مراجعة إدارة المنصة."],
            'reject' => ['تم رفض طلب المشروع', "رفضت إدارة المنصة مشروع «{$record->title}». السبب: {$record->moderation_note}"],
            'request_changes' => ['مطلوب تعديل على مشروعك', "طلبت إدارة المنصة تعديلات على «{$record->title}»: {$record->moderation_note}"],
            'suspend' => ['تم تعليق مشروعك', "علّقت إدارة المنصة مشروع «{$record->title}»: {$record->moderation_note}"],
            'withdraw' => ['تم سحب مشروعك', "سحبت إدارة المنصة مشروع «{$record->title}»: {$record->moderation_note}"],
            'republish' => ['أعيد نشر مشروعك', "أعادت إدارة المنصة نشر مشروع «{$record->title}»."],
        };

        PlatformNotification::send($owner,
            'project.review.'.$decision,
            $title,
            $body,
            "/organization/requests/{$record->project_request_id}",
            ['project_id' => $record->id],
        );
    }
}
