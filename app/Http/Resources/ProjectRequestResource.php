<?php

namespace App\Http\Resources;

use App\Models\ProjectRole;
use App\Models\ProjectRoleSkill;
use App\Services\Assessments\SkillLevels;
use App\Services\Projects\OpportunityMatcher;
use App\Services\Projects\ProjectStaffing;
use App\Services\Projects\ProposalPublisher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'title' => $this->title,
            'problem_description' => $this->problem_description,
            'current_process' => $this->current_process ?? [],
            'target_users' => $this->target_users ?? [],
            'desired_outcomes' => $this->desired_outcomes ?? [],
            'existing_tools' => $this->existing_tools ?? [],
            'deadline' => $this->deadline?->toDateString(),
            'priority' => $this->priority,
            'budget_range' => $this->budget_range,
            'special_requirements' => $this->special_requirements,
            'status' => $this->status,
            'intake_data' => $this->intake_data ?? [],
            'proposal' => $this->proposal,
            // متطلبات الفريق في المسودة الحالية مع حالة ربط كل مهارة بمهارات المنصة
            'staffing' => is_array($this->proposal) ? ProjectStaffing::fromProposal($this->proposal) : null,
            'publication' => $this->publication(),
            'revision_note' => $this->revision_note,
            'proposal_generated_at' => $this->proposal_generated_at,
            'approved_at' => $this->approved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function publication(): ?array
    {
        $opportunity = $this->opportunity;
        if (! $opportunity) {
            return null;
        }

        $version = $opportunity->activeVersion;
        $publication = [
            'opportunity_id' => $opportunity->id,
            'status' => $opportunity->status,
            'published_at' => $opportunity->published_at,
            'version' => $version->version,
            'approved_at' => $version->approved_at,
            'versions_count' => $this->proposalVersions()->count(),
            // مسودة معدلة لم تُعتمد بعد؛ النسخة المنشورة تبقى كما هي حتى الاعتماد
            'has_pending_changes' => is_array($this->proposal)
                && ProposalPublisher::hash($this->proposal) !== $version->proposal_hash,
        ];

        // تفاصيل المطابقة تُحسب فقط في صفحة الطلب نفسها (حيث تُحمَّل الأدوار مسبقًا)
        if ($version->relationLoaded('roles')) {
            $counts = app(OpportunityMatcher::class)->eligibleCounts($opportunity);
            $publication['roles'] = $version->roles->map(fn (ProjectRole $role): array => [
                'id' => $role->id,
                'category' => $role->category,
                'category_label' => ProjectStaffing::ROLE_CATEGORIES[$role->category] ?? $role->category,
                'title' => $role->title,
                'headcount' => $role->headcount,
                'mandatory_skills' => $this->skillNames($role, ProjectRoleSkill::MANDATORY),
                'mandatory_requirements' => $role->skills
                    ->where('importance', ProjectRoleSkill::MANDATORY)
                    ->map(fn (ProjectRoleSkill $skill): array => [
                        'name' => $skill->skill->name,
                        'min_level' => $skill->min_level,
                        'min_level_label' => SkillLevels::label($skill->min_level),
                    ])
                    ->values()
                    ->all(),
                'desirable_skills' => $this->skillNames($role, ProjectRoleSkill::DESIRABLE),
                'eligible_students' => $counts[$role->id] ?? 0,
            ])->all();
            // نسخة نُشرت قبل إضافة المستويات: تتطلب "أي مستوى مُثبت" حتى تعيد المؤسسة الاعتماد بمستويات مؤكدة
            $publication['levels_confirmed'] = $version->roles->every(
                fn (ProjectRole $role): bool => $role->skills->where('importance', ProjectRoleSkill::MANDATORY)->every(fn (ProjectRoleSkill $skill): bool => $skill->min_level !== null)
            );
        }

        return $publication;
    }

    private function skillNames(ProjectRole $role, string $importance): array
    {
        return $role->skills
            ->where('importance', $importance)
            ->map(fn (ProjectRoleSkill $skill): string => $skill->skill->name)
            ->values()
            ->all();
    }
}
