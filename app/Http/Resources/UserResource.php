<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'onboarding_completed' => match ($this->role) {
                'student' => (bool) $this->studentProfile?->onboarding_completed,
                'organization' => (bool) $this->organizationProfile?->onboarding_completed,
                'admin' => true,
                default => null,
            },
            'is_active' => $this->isActive(),
            // قيادة المشاريع مرتبطة بالمشروع: طالب يقود مشروعًا ويمكنه التقديم على غيره
            'leading_projects_count' => $this->role === 'student'
                ? $this->leaderAssignments()->active()->count()
                : 0,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
