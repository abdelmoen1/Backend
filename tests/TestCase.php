<?php

namespace Tests;

use Database\Seeders\SpecializationSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * التخصصات جزء من بيانات المنصة الأساسية؛ تُزرع مع كل RefreshDatabase. المهارات لا تُزرع هنا، لذا
     * الاختبار الذي ينشئ مهارات ويحتاج ربطها بالتخصصات يعيد تشغيل SpecializationSeeder بعد إنشائها.
     */
    protected bool $seed = true;

    protected string $seeder = SpecializationSeeder::class;
}
