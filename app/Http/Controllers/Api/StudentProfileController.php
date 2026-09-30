<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStudentProfileRequest;
use App\Http\Resources\StudentProfileResource;
use Illuminate\Http\JsonResponse;

class StudentProfileController extends Controller
{
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
}
