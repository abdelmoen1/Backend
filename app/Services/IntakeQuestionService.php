<?php

namespace App\Services;

use App\Models\ProjectRequest;

class IntakeQuestionService
{
    public function questions(ProjectRequest $projectRequest): array
    {
        return [
            [
                'key' => 'main_challenge',
                'insight' => $projectRequest->problem_description
                    ? 'فهمنا التحدي الذي وصفتموه، ونريد تحديد الأولوية الأهم.'
                    : 'أخبرونا بالمزيد حتى نتمكن من بناء تصور مناسب.',
                'question' => 'ما التحدي الأكبر الذي تواجهونه؟',
                'options' => ['تنظيم العمل', 'متابعة المستفيدين', 'تقليل الأخطاء'],
            ],
        ];
    }
}
