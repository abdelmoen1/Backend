<?php

namespace App\Enums;

final class ProjectRequestOptions
{
    public const CURRENT_PROCESS = [
        'الأوراق والدفاتر',
        'Excel',
        'WhatsApp',
        'نظام موجود حاليًا',
        'إجراءات يدوية',
        'شيء آخر',
    ];

    public const TARGET_USERS = [
        'الموظفون',
        'العملاء',
        'الإدارة',
        'المرضى',
        'الطلاب',
        'أخرى',
    ];

    public const DESIRED_OUTCOMES = [
        'توفير الوقت',
        'تقليل الأخطاء',
        'تنظيم البيانات',
        'تحسين خدمة العملاء',
        'أتمتة المهام المتكررة',
    ];

    public const EXISTING_TOOLS = [
        'Excel',
        'WhatsApp',
        'نظام موجود حاليًا',
        'لا توجد أدوات حاليًا',
    ];

    public const BUDGET_RANGES = [
        'under_500',
        '500_1500',
        '1500_5000',
        'over_5000',
        'needs_estimate',
    ];
}
