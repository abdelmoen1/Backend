<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Seeder;

class SkillSeeder extends Seeder
{
    public function run(): void
    {
        $skills = [
            // عامة
            'Git',
            'GitHub',
            'REST API',

            // Frontend
            'HTML',
            'CSS',
            'JavaScript',
            'TypeScript',
            'React',
            'Vue.js',
            'Angular',
            'Next.js',
            'Redux',
            'Tailwind CSS',
            'Bootstrap',
            'Sass',
            'Responsive Design',

            // Backend
            'PHP',
            'Laravel',
            'Node.js',
            'Express.js',
            'Python',
            'Django',
            'Flask',
            'Java',
            'Spring Boot',
            'C#',
            'ASP.NET Core',
            'C++',
            'MySQL',
            'PostgreSQL',
            'MongoDB',
            'Redis',
            'GraphQL',
            'Docker',

            // Mobile
            'Flutter',
            'Dart',
            'Kotlin',
            'Swift',
            'React Native',
            'Firebase',

            // UI/UX
            'Figma',
            'UI/UX Design',
            'Adobe XD',
            'Wireframing',
            'Prototyping',
            'User Research',
            'Usability Testing',
            'Design Systems',

            // QA
            'Manual Testing',
            'Test Automation',
            'API Testing',
            'Selenium',
            'Cypress',
            'Jest',
            'Postman',

            // Data & AI
            'SQL',
            'Pandas',
            'NumPy',
            'Data Visualization',
            'Power BI',
            'Machine Learning',
            'Deep Learning',
            'Scikit-learn',
            'TensorFlow',
            'PyTorch',
        ];

        foreach ($skills as $skill) {
            Skill::firstOrCreate([
                'name' => $skill,
            ]);
        }
    }
}
