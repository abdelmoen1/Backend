<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'bio' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000',
            ],

            'status' => [
                'sometimes',
                'nullable',
                Rule::in([
                    'student',
                    'graduated',
                ]),
            ],

            'university' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'academic_major' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'specific_major' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'graduation_year' => [
                'sometimes',
                'nullable',
                'string',
                'max:4',
            ],

            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:30',
            ],
        ];
    }
}
