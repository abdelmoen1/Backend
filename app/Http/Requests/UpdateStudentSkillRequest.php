<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'proficiency' => [
                'required',
                'string',
                Rule::in(['beginner', 'intermediate', 'advanced', 'expert']),
            ],
        ];
    }
}
