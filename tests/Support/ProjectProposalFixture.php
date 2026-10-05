<?php

namespace Tests\Support;

class ProjectProposalFixture
{
    public static function valid(): array
    {
        return [
            'project_overview' => [
                'project_name' => 'نظام إدارة مواعيد العيادة',
                'executive_summary' => 'منصة لتنظيم المواعيد ومتابعة المرضى.',
                'business_problem' => 'تدار المواعيد عبر واتساب ودفتر ورقي.',
                'proposed_solution' => 'نظام مركزي لإدارة المواعيد.',
            ],
            'business_problem' => [
                'current_situation' => 'تسجيل يدوي للمواعيد.',
                'pain_points' => ['تداخل المواعيد'],
                'impact' => 'وقت ضائع وتجربة أقل جودة للمريض.',
            ],
            'objectives' => ['تقليل تعارض المواعيد'],
            'target_users' => [[
                'user' => 'موظفو الاستقبال',
                'role' => 'إدارة المواعيد',
                'interaction' => 'إضافة المواعيد وتحديثها.',
            ]],
            'proposed_solution' => 'تطبيق ويب لإدارة جداول العيادة.',
            'functional_scope' => [[
                'group' => 'المواعيد',
                'requirements' => ['إنشاء الموعد وتعديله'],
            ]],
            'out_of_scope' => ['التشخيص الطبي'],
            'technical_recommendation' => [
                'application_type' => 'تطبيق ويب',
                'frontend' => 'واجهة ويب متجاوبة',
                'backend' => 'خدمة خلفية مناسبة',
                'database' => 'قاعدة بيانات علائقية',
                'integrations' => ['رسائل التذكير بعد تأكيد القناة'],
                'rationale' => 'يلائم الاستخدام من أجهزة العيادة المختلفة.',
            ],
            'required_skills' => ['تحليل الأعمال', 'تطوير الويب'],
            'proposed_team' => [[
                'role' => 'قائد فريق',
                'responsibilities' => 'تنسيق التنفيذ ومراجعة الجودة.',
            ]],
            'milestones' => [
                ['title' => 'التحليل', 'description' => 'تأكيد الإجراءات.', 'duration' => '1-2 أسبوع'],
                ['title' => 'التطوير', 'description' => 'تنفيذ الوظائف الأساسية.', 'duration' => '3-5 أسابيع'],
            ],
            'estimated_duration' => '4-7 أسابيع',
            'estimated_cost' => 'تقدير مبدئي: يحدد بعد اعتماد النطاق',
            'operational_cost' => [[
                'item' => 'الاستضافة',
                'estimate' => 'يحدد حسب الاستخدام',
                'notes' => 'يتطلب اختيار مزود الاستضافة.',
            ]],
            'assumptions' => ['تتوفر أجهزة متصلة بالإنترنت.'],
            'needs_confirmation' => ['قناة إرسال التذكيرات.'],
            'success_criteria' => ['انخفاض تعارض المواعيد.'],
            'srs_summary' => [
                'functional_requirements' => ['إدارة المواعيد'],
                'non_functional_considerations' => ['حماية بيانات المرضى'],
            ],
        ];
    }
}
