<?php

namespace Database\Seeders;

use App\Models\OrganizationProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class ProjectRequestDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'Project request demo data can only be seeded locally or in tests.'
            );
        }

        $user = User::firstOrCreate(
            ['email' => 'demo-clinic@example.test'],
            [
                'name' => 'عيادة الشفاء',
                'password' => Str::random(48),
                'role' => 'organization',
            ]
        );

        if ($user->role !== 'organization') {
            throw new RuntimeException(
                'The demo clinic account must have the organization role.'
            );
        }

        $profile = OrganizationProfile::firstOrCreate(
            ['user_id' => $user->id],
            [
                'organization_name' => 'عيادة الشفاء',
                'organization_type' => 'عيادة',
                'industry' => 'الرعاية الصحية',
                'description' => 'عيادة تقدم خدمات الرعاية الصحية للمرضى.',
                'location' => 'غزة',
                'onboarding_completed' => true,
            ]
        );

        $profile->projectRequests()->firstOrCreate(
            ['title' => 'نظام إدارة مواعيد العيادة'],
            [
                'problem_description' => 'إدارة المواعيد تتم حاليًا عبر واتساب ودفتر ورقي، مما يسبب تداخلًا في المواعيد وصعوبة في المتابعة.',
                'current_process' => ['WhatsApp', 'الأوراق والدفاتر'],
                'target_users' => ['المرضى', 'الموظفون'],
                'desired_outcomes' => ['تقليل الأخطاء', 'تنظيم البيانات'],
                'existing_tools' => ['WhatsApp'],
                'status' => 'draft',
            ]
        );
    }
}
