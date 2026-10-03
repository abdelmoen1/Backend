<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStudentProfileRequest;
use App\Http\Resources\StudentProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'student') {
            return response()->json([
                'success' => false,
                'message' => 'Only students can access this profile.',
            ], 403);
        }

        $studentProfile = $request->user()->studentProfile;

        if (!$studentProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Student profile not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new StudentProfileResource($studentProfile->load('user')),
        ]);
    }

    public function update(
        UpdateStudentProfileRequest $request
    ): JsonResponse {
        $studentProfile = $request->user()->studentProfile;

        if (!$studentProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Student profile not found.',
            ], 404);
        }

        $validated = $request->validated();

        if (array_key_exists('name', $validated)) {
            $request->user()->update([
                'name' => $validated['name'],
            ]);

            unset($validated['name']);
        }

        $studentProfile->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Student profile updated successfully.',
            'data' => new StudentProfileResource(
                $studentProfile->fresh()->load('user')
            ),
        ]);
    }
    public function completeOnboarding(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'student') {
            return response()->json([
                'success' => false,
                'message' => 'Only students can complete onboarding.',
            ], 403);
        }

        $studentProfile = $request->user()->studentProfile;

        if (!$studentProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Student profile not found.',
            ], 404);
        }

        $studentProfile->update([
            'onboarding_completed' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Onboarding completed successfully.',
            'data' => new StudentProfileResource($studentProfile->fresh()->load('user')),
        ]);
    }
}
