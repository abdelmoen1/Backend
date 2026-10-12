<?php

namespace Tests\Unit;

use App\Models\Specialization;
use App\Services\Projects\ProjectStaffing;
use App\Services\Projects\SpecializationRoles;
use Database\Seeders\SkillSeeder;
use Database\Seeders\SpecializationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * التخصصات في جدول specializations (SpecializationSeeder): صريحة، كل فئة دور لها تخصص متوافق، ومهارات
 * كل تخصص من جدول skills الحقيقي.
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
        $this->assertSame(array_keys(SpecializationSeeder::SPECIALIZATIONS), SpecializationRoles::keys());

        $this->assertTrue(SpecializationRoles::allows('mobile_development', 'mobile_developer'));
        $this->assertFalse(SpecializationRoles::allows('mobile_development', 'backend_developer'));
        $this->assertTrue(SpecializationRoles::allows('fullstack_development', 'backend_developer'));
        $this->assertFalse(SpecializationRoles::allows('ui_ux_design', 'frontend_developer'));
    }

    public function test_every_specialization_skill_exists_in_the_platform_catalog(): void
    {
        $this->seed(SkillSeeder::class);
        $this->seed(SpecializationSeeder::class);

        foreach (SpecializationRoles::publicCatalog() as $specialization) {
            $configured = SpecializationSeeder::SPECIALIZATIONS[$specialization['key']]['skills'];
            $this->assertSame($configured, array_column($specialization['skills'], 'name'), $specialization['key']);
            $this->assertNotSame('', $specialization['title']);
            $this->assertSame(
                array_column($specialization['skills'], 'id'),
                SpecializationRoles::skillIds($specialization['key'])->all(),
            );
        }
    }

    public function test_the_seeder_can_run_again_without_duplicates(): void
    {
        $this->seed(SkillSeeder::class);
        $this->seed(SpecializationSeeder::class);
        $this->seed(SpecializationSeeder::class);

        $this->assertSame(count(SpecializationSeeder::SPECIALIZATIONS), Specialization::count());
        $this->assertSame(
            count(SpecializationSeeder::SPECIALIZATIONS['mobile_development']['skills']),
            Specialization::where('key', 'mobile_development')->first()->skills()->count(),
        );
    }

    public function test_missing_and_unrecognized_values_are_explicit(): void
    {
        $this->assertSame(SpecializationRoles::MISSING, SpecializationRoles::for(null)['status']);
        $this->assertSame(SpecializationRoles::MISSING, SpecializationRoles::for('  ')['status']);
        // التخصص الجامعي ليس تخصصًا تقنيًا من القائمة
        $this->assertSame(SpecializationRoles::UNRECOGNIZED, SpecializationRoles::for('علوم الحاسوب')['status']);
        $this->assertSame([], SpecializationRoles::for('علوم الحاسوب')['categories']);
        $this->assertSame([], SpecializationRoles::skillIds('علوم الحاسوب')->all());

        $backend = SpecializationRoles::for('backend_development');
        $this->assertSame(SpecializationRoles::COMPATIBLE, $backend['status']);
        $this->assertSame('تطوير الخدمات الخلفية (Backend)', $backend['label']);

        Specialization::where('key', 'backend_development')->update(['role_categories' => json_encode(['backend_developer', 'astronaut'])]);
        $this->assertSame(['backend_developer'], SpecializationRoles::for('backend_development')['categories']);
    }
}
