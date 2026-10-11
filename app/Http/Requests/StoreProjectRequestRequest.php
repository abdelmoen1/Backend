<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'organization'
            && $this->user()?->organizationProfile !== null;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'problem_description' => ['sometimes', 'nullable', 'string', 'min:10', 'max:10000'],
            'intake_data' => ['sometimes', 'array:short_title'],
            'intake_data.short_title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
