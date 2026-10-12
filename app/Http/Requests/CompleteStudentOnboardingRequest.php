<?php

namespace App\Http\Requests;

use App\Services\Projects\SpecializationRoles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * إكمال ملف الطالب في طلب واحد: البيانات الشخصية والدراسية والتخصص ومهاراته.
 * المهارات يجب أن تكون من مهارات التخصص المختار في جدول skill_specialization.
 */
class CompleteStudentOnboardingRequest extends FormRequest
{
    public const MAX_SKILLS = 15;

    public function authorize(): bool
    {
        return $this->user()?->role === 'student';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+\d{7,15}$/'],
            'university' => ['required', 'string', 'max:255'],
            'graduation_year' => ['required', 'digits:4'],
            'professional_specialization' => ['required', 'string', Rule::exists('specializations', 'key')],
            'skill_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_SKILLS],
            'skill_ids.*' => ['integer', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'الاسم الكامل مطلوب.',
            'phone.regex' => 'رقم الهاتف غير صحيح.',
            'university.required' => 'اختر جامعتك أو كليتك.',
            'graduation_year.required' => 'اختر سنة التخرج.',
            'graduation_year.digits' => 'سنة التخرج غير صحيحة.',
            'professional_specialization.required' => 'اختر تخصصك.',
            'professional_specialization.exists' => 'هذا التخصص غير موجود في القائمة.',
            'skill_ids.required' => 'اختر مهارة واحدة على الأقل.',
            'skill_ids.min' => 'اختر مهارة واحدة على الأقل.',
            'skill_ids.max' => 'يمكنك اختيار '.self::MAX_SKILLS.' مهارة كحد أقصى.',
            'skill_ids.*' => 'قائمة المهارات غير صحيحة.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['professional_specialization', 'skill_ids', 'skill_ids.*'])) {
                    return;
                }

                $allowed = SpecializationRoles::skillIds($this->input('professional_specialization'));
                $outside = collect($this->input('skill_ids'))->map(fn ($id): int => (int) $id)->diff($allowed);
                if ($outside->isNotEmpty()) {
                    $validator->errors()->add('skill_ids', 'اختر مهاراتك من مهارات تخصصك فقط.');
                }
            },
        ];
    }
}
