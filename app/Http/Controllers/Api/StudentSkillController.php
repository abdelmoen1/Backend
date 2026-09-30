<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddStudentSkillRequest;
use App\Models\Skill;
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

        return response()->json([
            'success' => true,
            'data' => $skills,
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
        ]);

        $studentSkill->load('skill');

        return response()->json([
            'success' => true,
            'message' => 'Skill added successfully.',
            'data' => $studentSkill,
        ], 201);
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

        $studentSkill->delete();

        return response()->json([
            'success' => true,
            'message' => 'Skill removed successfully.',
        ]);
    }
}
