<?php

namespace Tests\Unit;

use App\Services\Projects\ProjectStaffing;
use App\Services\Projects\SpecializationRoles;
use Database\Seeders\SkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المسارات المهنية في config/matching.php: صريحة، كل فئة دور لها مسار متوافق، والمهارات المقترحة
 * من جدول skills الحقيقي.
 */
class SpecializationRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_category_is_reachable_from_an_explicit_specialization(): void
    {
        $covered = [];
        foreach (SpecializationRoles::catalog() as $key => $definition) {
            $this->assertNotSame([], $definition['roles'], $key);
            $this->assertSame([], array_diff($definition['roles'], array_keys(ProjectStaffing::ROLE_CATEGORIES)), $key);
            $covered = array_merge($covered, $definition['roles']);
        }
        $this->assertSame([], array_diff(array_keys(ProjectStaffing::ROLE_CATEGORIES), $covered));

        $this->assertTrue(SpecializationRoles::allows('mobile_development', 'mobile_developer'));
        $this->assertFalse(SpecializationRoles::allows('mobile_development', 'backend_developer'));
        $this->assertTrue(SpecializationRoles::allows('fullstack_development', 'backend_developer'));
        $this->assertFalse(SpecializationRoles::allows('ui_ux_design', 'frontend_developer'));
    }

    public function test_suggested_skills_exist_in_the_platform_catalog(): void
    {
        $this->seed(SkillSeeder::class);

        foreach (SpecializationRoles::publicCatalog() as $specialization) {
            $configured = SpecializationRoles::catalog()[$specialization['key']]['suggested_skills'];
            $this->assertSame($configured, array_column($specialization['suggested_skills'], 'name'), $specialization['key']);
        }
    }

    public function test_missing_and_unrecognized_values_are_explicit(): void
    {
        $this->assertSame(SpecializationRoles::MISSING, SpecializationRoles::for(null)['status']);
        $this->assertSame(SpecializationRoles::MISSING, SpecializationRoles::for('  ')['status']);
        // التخصص الجامعي ليس مسارًا مهنيًا
        $this->assertSame(SpecializationRoles::UNRECOGNIZED, SpecializationRoles::for('علوم الحاسوب')['status']);
        $this->assertSame([], SpecializationRoles::for('علوم الحاسوب')['categories']);

        $backend = SpecializationRoles::for('backend_development');
        $this->assertSame(SpecializationRoles::COMPATIBLE, $backend['status']);
        $this->assertSame('تطوير الخدمات الخلفية (Backend)', $backend['label']);

        config(['matching.professional_specializations.backend_development.roles' => ['backend_developer', 'astronaut']]);
        $this->assertSame(['backend_developer'], SpecializationRoles::for('backend_development')['categories']);
    }
}
