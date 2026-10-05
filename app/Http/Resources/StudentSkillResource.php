<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentSkillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'student_profile_id' => $this->student_profile_id,
            'skill_id' => $this->skill_id,
            'proficiency' => $this->proficiency,
            'skill' => $this->whenLoaded('skill', fn () => [
                'id' => $this->skill->id,
                'name' => $this->skill->name,
            ]),
        ];
    }
}
