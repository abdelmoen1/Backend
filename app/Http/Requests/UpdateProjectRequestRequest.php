<?php

namespace App\Http\Requests;

use App\Enums\ProjectRequestOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'organization';
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'problem_description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'current_process' => ['sometimes', 'nullable', 'array', 'max:20'],
            'current_process.*' => ['string', Rule::in(ProjectRequestOptions::CURRENT_PROCESS)],
            'target_users' => ['sometimes', 'nullable', 'array', 'max:20'],
            'target_users.*' => ['string', Rule::in(ProjectRequestOptions::TARGET_USERS)],
            'desired_outcomes' => ['sometimes', 'nullable', 'array', 'max:20'],
            'desired_outcomes.*' => ['string', Rule::in(ProjectRequestOptions::DESIRED_OUTCOMES)],
            'existing_tools' => ['sometimes', 'nullable', 'array', 'max:20'],
            'existing_tools.*' => ['string', Rule::in(ProjectRequestOptions::EXISTING_TOOLS)],
            'deadline' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
            'priority' => ['sometimes', 'nullable', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'budget_range' => ['sometimes', 'nullable', Rule::in(ProjectRequestOptions::BUDGET_RANGES)],
            'special_requirements' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
