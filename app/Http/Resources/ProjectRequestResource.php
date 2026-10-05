<?php

namespace App\Http\Resources;

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
            'revision_note' => $this->revision_note,
            'proposal_generated_at' => $this->proposal_generated_at,
            'approved_at' => $this->approved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
