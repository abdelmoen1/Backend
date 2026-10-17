<?php

/*
 * قواعد تشغيل المنصة القابلة للضبط دون تعديل الكود.
 */
return [

    // أقصى عدد مشاريع سارية يقودها الشخص نفسه قبل أن يتطلب التعيين مبررًا صريحًا من مدير المنصة
    'max_active_leaderships' => (int) env('GOTRAINEE_MAX_ACTIVE_LEADERSHIPS', 2),

    // أقل عدد مهارات مُثبتة بتقييم ليُعد الطالب مؤهلًا لقيادة فريق دون استثناء
    'leader_min_verified_skills' => (int) env('GOTRAINEE_LEADER_MIN_VERIFIED_SKILLS', 1),

    // التسعير يُفصل إلى ثلاثة بنود: ميزانية التنفيذ (للفريق)، عمولة المنصة، ورسوم خدمة جمع المتطلبات
    'platform_commission_rate' => (float) env('GOTRAINEE_PLATFORM_COMMISSION_RATE', 0.10),
    'requirements_gathering_fee' => (float) env('GOTRAINEE_REQUIREMENTS_GATHERING_FEE', 0),
    'currencies' => ['USD', 'ILS', 'EUR', 'JOD'],
    'default_currency' => env('GOTRAINEE_DEFAULT_CURRENCY', 'USD'),

];
