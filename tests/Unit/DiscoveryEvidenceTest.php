<?php

namespace Tests\Unit;

use App\Services\AI\DiscoveryRequirements;
use PHPUnit\Framework\TestCase;

/**
 * التغطية تُحسب من الأدلة المحفوظة وليس من تقييم Gemini: هذا ما سمح سابقًا بإظهار 11 محورًا
 * مغطى بعد إجابة واحدة.
 */
class DiscoveryEvidenceTest extends TestCase
{
    public function test_model_coverage_claims_without_evidence_are_not_counted(): void
    {
        $coverage = [];
        foreach (array_keys(DiscoveryRequirements::AREAS) as $index => $area) {
            $coverage[] = ['area' => $area, 'status' => ['sufficient', 'unknown', 'not_applicable'][$index % 3]];
        }
        $requirements = $this->apply([$this->fact('problem', 'الركاب لا يعرفون المسارات.')], $coverage);

        $view = DiscoveryRequirements::publicView(['requirements' => $requirements]);

        $this->assertSame(1, $view['covered_count']);
        $this->assertFalse($view['ready_for_proposal']);
        $this->assertSame(
            ['current_process', 'desired_outcome', 'users', 'business_needs', 'important_features'],
            $view['missing_core_areas']
        );
        // ادعاء "لا ينطبق" لمحور أساسي يُتجاهل
        $this->assertSame('missing', DiscoveryRequirements::areaStatus(
            $this->apply([], [['area' => 'users', 'status' => 'not_applicable']]),
            'users'
        ));
    }

    public function test_one_rich_answer_can_cover_several_areas(): void
    {
        $requirements = $this->apply([
            $this->fact('problem', 'الركاب لا يعرفون المسارات والأجرة.'),
            $this->fact('current_process', 'يسأل الركاب السائقين مباشرة.'),
            $this->fact('desired_outcome', 'معرفة المسار والأجرة قبل الركوب.'),
            $this->fact('users', 'الركاب والسائقون.'),
            $this->fact('business_needs', 'تحديث الأسعار مركزيًا.'),
            $this->fact('important_features', 'تطبيق جوال يعرض المسارات والأجرة.'),
        ], []);

        $this->assertTrue(DiscoveryRequirements::isReady(['requirements' => $requirements]));
        $this->assertSame(6, DiscoveryRequirements::publicView(['requirements' => $requirements])['covered_count']);
    }

    public function test_unknowns_and_contradictions_are_handled_explicitly(): void
    {
        $base = [
            $this->fact('problem', 'المشكلة.'),
            $this->item('current_process', 'unknown', 'لا توجد عملية واضحة حاليًا.'),
            $this->fact('desired_outcome', 'النتيجة.'),
            $this->fact('business_needs', 'الاحتياج.'),
            $this->fact('important_features', 'الميزة.'),
            $this->item('budget', 'unknown', 'الميزانية غير محددة بعد.'),
        ];

        // المستخدمون "غير معروفين" لا يكفي لإعداد مقترح؛ العملية الحالية يمكن أن تبقى غير معروفة
        $unknownUsers = $this->apply([...$base, $this->item('users', 'unknown', 'لم نحدد المستخدمين.')], []);
        $this->assertSame(['users'], DiscoveryRequirements::missingCoreAreas($unknownUsers));
        $this->assertSame('unknown', DiscoveryRequirements::areaStatus($unknownUsers, 'budget'));

        // تعارض غير محسوم في محور أساسي يمنع الإنهاء
        $conflict = $this->apply([
            ...$base,
            $this->fact('users', 'الركاب فقط.'),
            $this->item('users', 'contradiction', 'ذكرت المؤسسة لاحقًا أن السائقين يستخدمونه أيضًا.'),
        ], [['area' => 'users', 'status' => 'sufficient']]);
        $this->assertSame('partial', DiscoveryRequirements::areaStatus($conflict, 'users'));
        $this->assertSame(['users'], DiscoveryRequirements::missingCoreAreas($conflict));
    }

    private function apply(array $updates, array $coverage): array
    {
        $normalizedCoverage = [];
        foreach ($coverage as $entry) {
            $normalizedCoverage[$entry['area']] = $entry['status'];
        }

        return DiscoveryRequirements::apply(
            DiscoveryRequirements::from([]),
            $updates,
            $normalizedCoverage,
            ['type' => 'answer', 'sequence' => 1]
        );
    }

    private function fact(string $area, string $text): array
    {
        return $this->item($area, 'fact', $text);
    }

    private function item(string $area, string $kind, string $text): array
    {
        return ['area' => $area, 'kind' => $kind, 'text' => $text, 'priority' => 'unspecified', 'replaces' => []];
    }
}
