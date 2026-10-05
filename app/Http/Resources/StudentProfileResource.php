<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class StudentProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->user?->name,
            'email' => $this->user?->email,

            'bio' => $this->bio,
            'status' => $this->status,

            'university' => $this->university,
            'academic_major' => $this->academic_major,
            'graduation_year' => $this->graduation_year,
            'phone' => $this->phone,
            'linkedin_url' => $this->linkedin_url,
            'github_url' => $this->github_url,
            'image_path' => $this->image_path,
            'image_url' => $this->image_path
                ? Storage::disk('public')->url($this->image_path)
                : null,
            'portfolio' => $this->whenLoaded(
                'profileDetails',
                fn () => $this->profileDetails?->portfolio ?? []
            ),
            'external_links' => $this->whenLoaded(
                'profileDetails',
                fn () => $this->profileDetails?->external_links ?? []
            ),
            'certifications' => $this->whenLoaded(
                'profileDetails',
                fn () => $this->profileDetails?->certifications ?? []
            ),
            'testimonials' => $this->whenLoaded(
                'profileDetails',
                fn () => $this->profileDetails?->testimonials ?? []
            ),
            'skills' => $this->whenLoaded(
                'studentSkills',
                fn () => $this->studentSkills->map(fn ($studentSkill) => [
                    'id' => $studentSkill->skill_id,
                    'name' => $studentSkill->skill?->name,
                    'proficiency' => $studentSkill->proficiency,
                ])->values()
            ),
            'onboarding_completed' => $this->onboarding_completed,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
