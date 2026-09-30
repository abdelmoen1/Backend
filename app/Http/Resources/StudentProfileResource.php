<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,

            'bio' => $this->bio,
            'status' => $this->status,

            'university' => $this->university,
            'academic_major' => $this->academic_major,
            'specific_major' => $this->specific_major,
            'graduation_year' => $this->graduation_year,
            'phone' => $this->phone,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
