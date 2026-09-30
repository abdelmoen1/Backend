<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Seeder;

class SkillSeeder extends Seeder
{
    public function run(): void
    {
        $skills = [
            'HTML',
            'CSS',
            'JavaScript',
            'PHP',
            'Laravel',
            'React',
            'Vue.js',
            'MySQL',
            'Git',
            'GitHub',
            'REST API',
            'Flutter',
            'Dart',
            'Figma',
            'UI/UX Design',
            'Python',
            'Java',
            'C++',
            'C#',
            'Node.js',
            'TypeScript',
        ];

        foreach ($skills as $skill) {
            Skill::firstOrCreate([
                'name' => $skill,
            ]);
        }
    }
}
