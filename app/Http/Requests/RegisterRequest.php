<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'password_confirmation' => ['required', 'string', 'same:password'],
            'role' => ['required', Rule::in(['student', 'organization'])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'الاسم مطلوب.',
            'name.max' => 'الاسم يجب ألا يتجاوز 255 حرفًا.',
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email' => 'صيغة البريد الإلكتروني غير صحيحة.',
            'email.max' => 'البريد الإلكتروني يجب ألا يتجاوز 255 حرفًا.',
            'email.unique' => 'هذا البريد الإلكتروني مسجّل مسبقًا.',
            'password.required' => 'كلمة المرور مطلوبة.',
            'password.min' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.',
            'password.password.letters' => 'كلمة المرور يجب أن تحتوي على حرف واحد على الأقل.',
            'password.password.numbers' => 'كلمة المرور يجب أن تحتوي على رقم واحد على الأقل.',
            'password_confirmation.required' => 'تأكيد كلمة المرور مطلوب.',
            'password_confirmation.same' => 'تأكيد كلمة المرور غير مطابق.',
            'role.required' => 'نوع الحساب مطلوب.',
            'role.in' => 'نوع الحساب غير صالح.',
        ];
    }
}
