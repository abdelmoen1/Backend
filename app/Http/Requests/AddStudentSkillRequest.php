<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddStudentSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'skill_id' => [
                'required',
                'integer',
                'exists:skills,id',
            ],
            'proficiency' => [
                'sometimes',
                'string',
                Rule::in(['beginner', 'intermediate', 'advanced', 'expert']),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'skill_id.required' => 'Skill is required.',
            'skill_id.exists' => 'The selected skill does not exist.',
        ];
    }
}
