<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;

class SkillController extends Controller
{
    public function index(): JsonResponse
    {
        $skills = Skill::query()
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $skills,
        ]);
    }
}
