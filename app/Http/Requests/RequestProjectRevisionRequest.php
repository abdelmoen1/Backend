<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestProjectRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'organization';
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:5', 'max:5000'],
        ];
    }
}
