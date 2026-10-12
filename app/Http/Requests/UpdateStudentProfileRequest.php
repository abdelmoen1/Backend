<?php

namespace App\Http\Requests;

use App\Services\Projects\SpecializationRoles;
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
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

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

            // المسار المهني من القائمة التي يديرها الخادم (config/matching.php)
            'professional_specialization' => [
                'sometimes',
                'nullable',
                Rule::in(SpecializationRoles::keys()),
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

            'linkedin_url' => [
                'sometimes',
                'nullable',
                'url',
                'max:2048',
            ],

            'github_url' => [
                'sometimes',
                'nullable',
                'url',
                'max:2048',
            ],

            'image' => [
                'sometimes',
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            'portfolio' => ['sometimes', 'array', 'max:20'],
            'portfolio.*.title' => ['required', 'string', 'max:150'],
            'portfolio.*.description' => ['nullable', 'string', 'max:1000'],
            'portfolio.*.url' => ['nullable', 'url', 'max:2048'],
            'portfolio.*.image_url' => ['nullable', 'url', 'max:2048'],
            'portfolio.*.category' => [
                'nullable',
                Rule::in(['web', 'design', 'other']),
            ],
            'external_links' => ['sometimes', 'array', 'max:10'],
            'external_links.*.label' => ['required', 'string', 'max:80'],
            'external_links.*.url' => ['required', 'url', 'max:2048'],
            'certifications' => ['sometimes', 'array', 'max:20'],
            'certifications.*.name' => ['required', 'string', 'max:160'],
            'certifications.*.issuer' => ['nullable', 'string', 'max:160'],
            'certifications.*.issued_at' => ['nullable', 'date_format:Y-m'],
            'certifications.*.credential_url' => ['nullable', 'url', 'max:2048'],
            'testimonials' => ['sometimes', 'array', 'max:10'],
            'testimonials.*.author' => ['required', 'string', 'max:120'],
            'testimonials.*.role' => ['nullable', 'string', 'max:150'],
            'testimonials.*.quote' => ['required', 'string', 'max:1000'],
        ];
    }
}
