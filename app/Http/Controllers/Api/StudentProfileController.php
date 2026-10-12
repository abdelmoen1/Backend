<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateStudentProfileRequest;
use App\Http\Resources\StudentProfileResource;
use App\Services\Projects\SpecializationRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

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
            'data' => new StudentProfileResource($studentProfile->load([
                'user',
                'profileDetails',
                'studentSkills.skill',
            ])),
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
        $image = $validated['image'] ?? null;
        unset($validated['image']);
        $detailFields = [
            'portfolio',
            'external_links',
            'certifications',
            'testimonials',
        ];
        $details = collect($detailFields)
            ->filter(fn (string $field) => array_key_exists($field, $validated))
            ->mapWithKeys(fn (string $field) => [$field => $validated[$field]])
            ->all();

        foreach ($detailFields as $field) {
            unset($validated[$field]);
        }

        if (array_key_exists('name', $validated)) {
            $userName = $validated['name'];
        } else {
            $userName = null;
        }
        unset($validated['name']);

        $previousImagePath = $studentProfile->image_path;

        if ($image) {
            $imagePath = $image->storePublicly('student-profiles', 'public');

            if (!$imagePath) {
                throw new RuntimeException('Unable to store the student profile image.');
            }

            $validated['image_path'] = $imagePath;
        }

        DB::transaction(function () use (
            $studentProfile,
            $validated,
            $details,
            $request,
            $userName
        ): void {
            $studentProfile->update($validated);

            if ($details !== []) {
                $studentProfile->profileDetails()->updateOrCreate([], $details);
            }

            if ($userName !== null) {
                $request->user()->update(['name' => $userName]);
            }
        });

        if ($image && $previousImagePath) {
            Storage::disk('public')->delete($previousImagePath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Student profile updated successfully.',
            'data' => new StudentProfileResource(
                $studentProfile->fresh()->load([
                    'user',
                    'profileDetails',
                    'studentSkills.skill',
                ])
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

        // المسار المهني إلزامي: عليه تُبنى التقييمات ومطابقة أدوار المشاريع
        if (SpecializationRoles::for($studentProfile->professional_specialization)['status'] !== SpecializationRoles::COMPATIBLE) {
            return response()->json([
                'success' => false,
                'message' => 'اختر مسارك المهني لإكمال ملفك.',
                'errors' => ['professional_specialization' => ['اختر مسارك المهني لإكمال ملفك.']],
            ], 422);
        }

        $studentProfile->update([
            'onboarding_completed' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Onboarding completed successfully.',
            'data' => new StudentProfileResource($studentProfile->fresh()->load([
                'user',
                'profileDetails',
                'studentSkills.skill',
            ])),
        ]);
    }
}
