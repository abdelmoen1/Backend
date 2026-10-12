<?php

namespace Database\Seeders;

use App\Models\Skill;
use App\Models\Specialization;
use Illuminate\Database\Seeder;

/**
 * التخصصات التقنية ومهارات كل تخصص.
 *
 * key يُحفظ في ملف الطالب ولا يُغيَّر بعد النشر. roles فئات أدوار المشاريع المتوافقة
 * (ProjectStaffing::ROLE_CATEGORIES)؛ "other" دور تقني لم يندرج تحت فئة محددة فيتوافق مع كل التخصصات،
 * ويبقى التحقق من مهاراته الإلزامية شرطًا. skills أسماء من جدول skills (تنشئها SkillSeeder)؛ الاسم
 * غير الموجود يُتجاهل. لا يوجد تخصص DevOps لأنه لا توجد له فئة دور في المقترحات.
 *
 * التشغيل آمن للتكرار: يحدّث التخصصات بالمفتاح ويضبط مهاراتها على هذه القائمة، ولا يحذف تخصصًا.
 */
class SpecializationSeeder extends Seeder
{
    public const SPECIALIZATIONS = [
        'frontend_development' => [
            'title' => 'Frontend',
            'name' => 'تطوير واجهات الويب (Frontend)',
            'description' => 'بناء واجهات مواقع وتطبيقات الويب التي يتفاعل معها المستخدم.',
            'roles' => ['frontend_developer', 'other'],
            'skills' => [
                'HTML', 'CSS', 'JavaScript', 'TypeScript', 'React', 'Vue.js', 'Angular', 'Next.js', 'Redux',
                'Tailwind CSS', 'Bootstrap', 'Sass', 'Responsive Design', 'REST API', 'Git', 'GitHub',
            ],
        ],
        'backend_development' => [
            'title' => 'Backend',
            'name' => 'تطوير الخدمات الخلفية (Backend)',
            'description' => 'بناء الخوادم وواجهات API وقواعد البيانات خلف التطبيقات.',
            'roles' => ['backend_developer', 'other'],
            'skills' => [
                'PHP', 'Laravel', 'Node.js', 'Express.js', 'Python', 'Django', 'Flask', 'Java', 'Spring Boot',
                'C#', 'ASP.NET Core', 'MySQL', 'PostgreSQL', 'MongoDB', 'Redis', 'REST API', 'GraphQL', 'Docker',
                'Git', 'GitHub',
            ],
        ],
        'fullstack_development' => [
            'title' => 'Full Stack',
            'name' => 'تطوير ويب متكامل (Full Stack)',
            'description' => 'العمل على الواجهة والخادم معًا لبناء تطبيق ويب كامل.',
            // مطوّر متكامل مؤهل أيضًا لأدوار الواجهات أو الخلفية إذا تحقق من مهاراتها الإلزامية
            'roles' => ['fullstack_developer', 'frontend_developer', 'backend_developer', 'other'],
            'skills' => [
                'HTML', 'CSS', 'JavaScript', 'TypeScript', 'React', 'Vue.js', 'Next.js', 'Tailwind CSS',
                'PHP', 'Laravel', 'Node.js', 'Express.js', 'MySQL', 'PostgreSQL', 'MongoDB', 'REST API',
                'GraphQL', 'Docker', 'Git', 'GitHub',
            ],
        ],
        'mobile_development' => [
            'title' => 'Mobile App',
            'name' => 'تطوير تطبيقات الجوال (Mobile App)',
            'description' => 'بناء تطبيقات Android و iOS الأصلية أو متعددة المنصات.',
            'roles' => ['mobile_developer', 'other'],
            'skills' => [
                'Flutter', 'Dart', 'React Native', 'Kotlin', 'Swift', 'Java', 'Firebase', 'REST API', 'Git', 'GitHub',
            ],
        ],
        'ui_ux_design' => [
            'title' => 'UI/UX Design',
            'name' => 'تصميم واجهات وتجربة المستخدم (UI/UX)',
            'description' => 'تصميم تجربة استخدام واضحة وواجهات جذابة قابلة للتنفيذ.',
            'roles' => ['ui_ux_designer', 'other'],
            'skills' => [
                'UI/UX Design', 'Figma', 'Adobe XD', 'Wireframing', 'Prototyping', 'User Research',
                'Usability Testing', 'Design Systems', 'Responsive Design', 'HTML', 'CSS',
            ],
        ],
        'qa_testing' => [
            'title' => 'QA & Testing',
            'name' => 'ضمان الجودة واختبار البرمجيات (QA)',
            'description' => 'اختبار البرمجيات يدويًا وآليًا لاكتشاف الأخطاء قبل وصولها للمستخدم.',
            'roles' => ['qa_tester', 'other'],
            'skills' => [
                'Manual Testing', 'Test Automation', 'API Testing', 'Selenium', 'Cypress', 'Jest', 'Postman',
                'JavaScript', 'Python', 'SQL', 'REST API', 'Git',
            ],
        ],
        'data_ai' => [
            'title' => 'Data & AI',
            'name' => 'علم البيانات والذكاء الاصطناعي (Data & AI)',
            'description' => 'تحليل البيانات وبناء نماذج التعلم الآلي.',
            'roles' => ['data_specialist', 'other'],
            'skills' => [
                'Python', 'SQL', 'MySQL', 'Pandas', 'NumPy', 'Data Visualization', 'Power BI', 'Machine Learning',
                'Deep Learning', 'Scikit-learn', 'TensorFlow', 'PyTorch', 'Git',
            ],
        ],
    ];

    public function run(): void
    {
        $skills = Skill::query()->get(['id', 'name'])->keyBy(fn (Skill $skill): string => mb_strtolower($skill->name));

        $order = 0;
        foreach (self::SPECIALIZATIONS as $key => $definition) {
            $specialization = Specialization::query()->updateOrCreate(['key' => $key], [
                'name' => $definition['name'],
                'title' => $definition['title'],
                'description' => $definition['description'],
                'role_categories' => $definition['roles'],
                'sort_order' => ++$order,
            ]);

            $links = [];
            foreach ($definition['skills'] as $position => $name) {
                $skill = $skills->get(mb_strtolower($name));
                if ($skill) {
                    $links[$skill->id] = ['sort_order' => $position + 1];
                }
            }
            $specialization->skills()->sync($links);
        }
    }
}
