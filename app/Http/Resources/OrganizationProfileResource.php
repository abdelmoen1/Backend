<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'email' => $this->user?->email,
            'organization_name' => $this->organization_name,
            'organization_type' => $this->organization_type,
            'industry' => $this->industry,
            'description' => $this->description,
            'location' => $this->location,
            'website' => $this->website,
            'contact_person' => $this->contact_person,
            'onboarding_completed' => (bool) $this->onboarding_completed,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
