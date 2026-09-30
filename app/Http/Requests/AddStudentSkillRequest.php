<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddStudentSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'skill_id' => [
                'required',
                'integer',
                'exists:skills,id',
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
