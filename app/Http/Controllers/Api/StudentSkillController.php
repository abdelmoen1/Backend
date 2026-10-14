<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddStudentSkillRequest;
use App\Http\Requests\UpdateStudentSkillRequest;
use App\Http\Resources\StudentSkillResource;
use App\Models\Skill;
use App\Models\SkillAssessment;
use App\Models\StudentSkill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentSkillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;

        $skills = $studentProfile->studentSkills()
            ->with('skill')
            ->get();

        // المهارات ذات التقييم المكتمل لا تُحذف من الملف؛ الواجهة تعطل زر حذفها
        $assessed = $studentProfile->skillAssessments()
            ->whereIn('status', SkillAssessment::COMPLETED_STATUSES)
            ->pluck('skill_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $skills->each(fn (StudentSkill $studentSkill) => $studentSkill->setAttribute('has_assessment', in_array((int) $studentSkill->skill_id, $assessed, true)));

        return response()->json([
            'success' => true,
            'data' => StudentSkillResource::collection($skills)->resolve(),
        ]);
    }

    public function store(AddStudentSkillRequest $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;

        $skillId = $request->validated('skill_id');

        $exists = $studentProfile->studentSkills()
            ->where('skill_id', $skillId)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'This skill has already been added.',
            ], 422);
        }

        $studentSkill = $studentProfile->studentSkills()->create([
            'skill_id' => $skillId,
            'proficiency' => $request->validated('proficiency') ?? 'intermediate',
        ]);

        $studentSkill->load('skill');

        return response()->json([
            'success' => true,
            'message' => 'Skill added successfully.',
            'data' => (new StudentSkillResource($studentSkill))->resolve(),
        ], 201);
    }

    public function update(
        UpdateStudentSkillRequest $request,
        Skill $skill
    ): JsonResponse {
        $studentProfile = $request->user()->studentProfile()->first();
        $studentSkill = $studentProfile
            ? StudentSkill::query()
                ->where('student_profile_id', $studentProfile->id)
                ->where('skill_id', $skill->id)
                ->first()
            : null;

        if (!$studentSkill) {
            return response()->json([
                'success' => false,
                'message' => 'Skill not found in student profile.',
            ], 404);
        }

        $studentProfile->studentSkills()
            ->where('skill_id', $skill->id)
            ->update(['proficiency' => $request->validated('proficiency')]);

        $studentSkill = $studentProfile->studentSkills()
            ->with('skill')
            ->where('skill_id', $skill->id)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'message' => 'Skill proficiency updated successfully.',
            'data' => (new StudentSkillResource($studentSkill))->resolve(),
        ]);
    }

    public function destroy(Request $request, Skill $skill): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;

        $studentSkill = $studentProfile->studentSkills()
            ->where('skill_id', $skill->id)
            ->first();

        if (!$studentSkill) {
            return response()->json([
                'success' => false,
                'message' => 'Skill not found in student profile.',
            ], 404);
        }

        // مهارة لها تقييم مكتمل محفوظ تبقى في الملف حتى يبقى سجلها ونتيجتها مرتبطين بها
        $assessed = $studentProfile->skillAssessments()
            ->where('skill_id', $skill->id)
            ->whereIn('status', SkillAssessment::COMPLETED_STATUSES)
            ->exists();
        if ($assessed) {
            return response()->json([
                'success' => false,
                'code' => 'skill_has_assessment',
                'message' => "لا يمكن حذف مهارة {$skill->name} من ملفك لأن لها سجل تقييم محفوظ.",
                'skill' => ['id' => $skill->id, 'name' => $skill->name],
            ], 409);
        }

        $studentProfile->studentSkills()
            ->where('skill_id', $skill->id)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Skill removed successfully.',
        ]);
    }
}
