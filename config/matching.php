<?php

/*
|--------------------------------------------------------------------------
| المسارات المهنية للطلاب وتوافقها مع أدوار المشاريع
|--------------------------------------------------------------------------
|
| professional_specialization في ملف الطالب مفتاح من هذه القائمة، وهو (لا التخصص الجامعي) ما تعتمد
| عليه مطابقة أدوار المشاريع. roles فئات الأدوار (ProjectStaffing::ROLE_CATEGORIES) المتوافقة مع المسار،
| و suggested_skills مهارات من جدول skills تُقترح على الطالب عند اختيار مهارات التقييم (أسماء غير
| موجودة في الجدول تُتجاهل).
|
| "other" دور تقني لم يندرج تحت فئة محددة، ومهاراته الإلزامية من قائمة المنصة؛ لذلك يُعد متوافقًا مع
| كل المسارات، ويبقى التحقق من مهاراته الإلزامية شرطًا لا يُستغنى عنه.
|
| لا يوجد مسار DevOps/Cloud: لا توجد له فئة دور في المقترحات ولا مهارات في جدول skills حاليًا.
| لإضافة مسار: أضف مفتاحًا هنا (وفئة دور في ProjectStaffing إن لزم).
|
*/

return [
    'professional_specializations' => [
        'frontend_development' => [
            'label' => 'تطوير واجهات الويب (Frontend)',
            'roles' => ['frontend_developer', 'other'],
            'suggested_skills' => ['HTML', 'CSS', 'JavaScript', 'TypeScript', 'React', 'Vue.js', 'Git'],
        ],
        'backend_development' => [
            'label' => 'تطوير الخدمات الخلفية (Backend)',
            'roles' => ['backend_developer', 'other'],
            'suggested_skills' => ['PHP', 'Laravel', 'Node.js', 'Python', 'Java', 'C#', 'MySQL', 'REST API', 'Git'],
        ],
        'fullstack_development' => [
            'label' => 'تطوير ويب متكامل (Full Stack)',
            // مطوّر متكامل مؤهل أيضًا لأدوار الواجهات أو الخلفية إذا تحقق من مهاراتها الإلزامية
            'roles' => ['fullstack_developer', 'frontend_developer', 'backend_developer', 'other'],
            'suggested_skills' => ['HTML', 'CSS', 'JavaScript', 'React', 'PHP', 'Laravel', 'Node.js', 'MySQL', 'REST API', 'Git'],
        ],
        'mobile_development' => [
            'label' => 'تطوير تطبيقات الجوال',
            'roles' => ['mobile_developer', 'other'],
            'suggested_skills' => ['Flutter', 'Dart', 'Java', 'REST API', 'Git'],
        ],
        'ui_ux_design' => [
            'label' => 'تصميم واجهات وتجربة المستخدم (UI/UX)',
            'roles' => ['ui_ux_designer', 'other'],
            'suggested_skills' => ['UI/UX Design', 'Figma', 'HTML', 'CSS'],
        ],
        'qa_testing' => [
            'label' => 'ضمان الجودة واختبار البرمجيات',
            'roles' => ['qa_tester', 'other'],
            'suggested_skills' => ['JavaScript', 'Python', 'REST API', 'Git'],
        ],
        'data_ai' => [
            'label' => 'علم البيانات والذكاء الاصطناعي',
            'roles' => ['data_specialist', 'other'],
            'suggested_skills' => ['Python', 'MySQL'],
        ],
    ],
];
