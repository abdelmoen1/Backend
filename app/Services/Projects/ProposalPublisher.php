<?php

namespace App\Services\Projects;

use App\Enums\ProjectRequestStatus;
use App\Models\ProjectOpportunity;
use App\Models\ProjectProposalVersion;
use App\Models\ProjectRequest;
use App\Models\ProjectRoleSkill;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * اعتماد المقترح ونشره: يجمّد نسخة المقترح ومتطلبات الفريق، وينشئ فرصة واحدة للطلب أو
 * يحدّث نسختها النشطة. العملية متكررة الأمان: إعادة الطلب بنفس المحتوى لا تنشئ شيئًا جديدًا.
 */
class ProposalPublisher
{
    private const APPROVABLE_STATUSES = [
        ProjectRequestStatus::PROPOSAL_READY,
        ProjectRequestStatus::REVISION_REQUESTED,
        ProjectRequestStatus::APPROVED,
        ProjectRequestStatus::CONVERTED_TO_OPPORTUNITY,
    ];

    public function approve(ProjectRequest $projectRequest, User $approver): ProjectOpportunity
    {
        return DB::transaction(function () use ($projectRequest, $approver): ProjectOpportunity {
            // القفل على الطلب يسلسل النقرات المتكررة وإعادة المحاولة عبر الشبكة
            $record = ProjectRequest::query()->whereKey($projectRequest->id)->lockForUpdate()->firstOrFail();
            $opportunity = ProjectOpportunity::query()->where('project_request_id', $record->id)->lockForUpdate()->first();

            if (! is_array($record->proposal) || $record->proposal === [] || ! in_array($record->status, self::APPROVABLE_STATUSES, true)) {
                throw new ProposalApprovalException('لا يوجد مقترح جاهز للاعتماد.');
            }

            $hash = self::hash($record->proposal);
            $version = ProjectProposalVersion::query()
                ->where('project_request_id', $record->id)
                ->where('proposal_hash', $hash)
                ->first();

            if ($version && $opportunity && $opportunity->active_version_id === $version->id) {
                $this->markPublished($record);

                return $opportunity;
            }

            $staffing = $this->publishableStaffing($record);
            $version ??= $this->freezeVersion($record, $staffing, $hash, $approver);
            $proposal = $version->proposal;

            $opportunity ??= new ProjectOpportunity(['project_request_id' => $record->id]);
            $opportunity->organization_id = $record->organization_id;
            $opportunity->active_version_id = $version->id;
            $opportunity->title = $proposal['project_overview']['project_name'] ?? $record->title;
            $opportunity->summary = $proposal['project_overview']['executive_summary'] ?? null;
            // تعليق الإدارة لا يُلغى باعتماد المؤسسة لنسخة جديدة
            if (! in_array($opportunity->status, [ProjectOpportunity::STATUS_SUSPENDED, ProjectOpportunity::STATUS_WITHDRAWN], true)) {
                if ($opportunity->status !== ProjectOpportunity::STATUS_PUBLISHED) {
                    $opportunity->status_changed_at = now();
                }
                $opportunity->status = ProjectOpportunity::STATUS_PUBLISHED;
                $opportunity->published_at ??= now();
            }
            $opportunity->save();

            $this->markPublished($record);

            Log::info('Project proposal approved and published.', [
                'project_request_id' => $record->id,
                'opportunity_id' => $opportunity->id,
                'version' => $version->version,
                'opportunity_status' => $opportunity->status,
                'approved_by_user_id' => $approver->id,
            ]);

            return $opportunity;
        });
    }

    public static function hash(array $proposal): string
    {
        return hash('sha256', json_encode($proposal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** يتحقق من البيانات اللازمة للنشر ويعيد متطلبات الفريق المرتبطة بمهارات المنصة. */
    private function publishableStaffing(ProjectRequest $record): array
    {
        $proposal = $record->proposal;
        $title = trim((string) ($proposal['project_overview']['project_name'] ?? $record->title));
        $scope = $proposal['functional_scope'] ?? null;

        if ($title === '' || ! is_array($scope) || $scope === [] || ! is_string($proposal['estimated_duration'] ?? null)) {
            throw new ProposalApprovalException('المقترح ينقصه العنوان أو نطاق العمل أو المدة التقديرية. حدّث المقترح ثم اعتمده.');
        }

        $staffing = ProjectStaffing::fromProposal($proposal);
        if ($staffing['roles'] === []) {
            throw new ProposalApprovalException('لا يتضمن المقترح أدوار الفريق والمهارات المطلوبة بشكل منظم. حدّث المقترح ليُحدد الأدوار قبل النشر.');
        }
        if ($staffing['matchable_roles'] === 0) {
            throw new ProposalApprovalException('لا يحتوي أي دور على مهارة إلزامية من مهارات المنصة، لذلك لا يمكن مطابقة الطلاب معه. وضّح الأدوار والمهارات في المحادثة ثم حدّث المقترح.');
        }

        return $staffing;
    }

    private function freezeVersion(ProjectRequest $record, array $staffing, string $hash, User $approver): ProjectProposalVersion
    {
        $version = ProjectProposalVersion::create([
            'project_request_id' => $record->id,
            'version' => (int) ProjectProposalVersion::query()->where('project_request_id', $record->id)->max('version') + 1,
            'proposal' => $record->proposal,
            'staffing' => $staffing,
            'proposal_hash' => $hash,
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
        ]);

        foreach ($staffing['roles'] as $position => $roleData) {
            $role = $version->roles()->create([
                'category' => $roleData['category'],
                'title' => $roleData['title'],
                'responsibilities' => $roleData['responsibilities'],
                'rationale' => $roleData['rationale'] !== '' ? $roleData['rationale'] : null,
                'headcount' => $roleData['headcount'],
                'position' => $position,
            ]);

            $skills = [];
            foreach ([ProjectRoleSkill::MANDATORY => 'mandatory_skills', ProjectRoleSkill::DESIRABLE => 'desirable_skills'] as $importance => $key) {
                foreach ($roleData[$key] as $skill) {
                    if ($skill['id'] !== null && ! isset($skills[$skill['id']])) {
                        $skills[$skill['id']] = ['skill_id' => $skill['id'], 'importance' => $importance];
                    }
                }
            }
            $role->skills()->createMany(array_values($skills));
        }

        return $version;
    }

    private function markPublished(ProjectRequest $record): void
    {
        if ($record->status === ProjectRequestStatus::CONVERTED_TO_OPPORTUNITY && $record->approved_at) {
            return;
        }

        $record->status = ProjectRequestStatus::CONVERTED_TO_OPPORTUNITY;
        $record->approved_at = now();
        $record->revision_note = null;
        $record->save();
    }
}
