<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteStudentOnboardingRequest;
use App\Http\Requests\UpdateStudentProfileRequest;
use App\Http\Resources\StudentProfileResource;
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
    /**
     * يحفظ بيانات الإكمال كلها في معاملة واحدة: إن فشل أي جزء لا يُحفظ شيء ويمكن إعادة الإرسال بأمان.
     * التخصص إلزامي (عليه تُبنى التقييمات ومطابقة أدوار المشاريع) والمهارات من مهاراته فقط.
     */
    public function completeOnboarding(CompleteStudentOnboardingRequest $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;

        if (!$studentProfile) {
            return response()->json([
                'success' => false,
                'message' => 'Student profile not found.',
            ], 404);
        }

        $validated = $request->validated();
        $skillIds = collect($validated['skill_ids'])->map(fn ($id): int => (int) $id)->unique();

        DB::transaction(function () use ($request, $studentProfile, $validated, $skillIds): void {
            $request->user()->update(['name' => $validated['name']]);

            // قبل الإكمال تمثل المهارات المحفوظة محاولات إكمال سابقة فتُضبط على الاختيار الحالي؛
            // بعده لا تُحذف مهارات أضافها الطالب من ملفه
            if (! $studentProfile->onboarding_completed) {
                $studentProfile->studentSkills()->whereNotIn('skill_id', $skillIds)->delete();
            }

            $existing = $studentProfile->studentSkills()->pluck('skill_id')->map(fn ($id): int => (int) $id);
            foreach ($skillIds->diff($existing) as $skillId) {
                $studentProfile->studentSkills()->create([
                    'skill_id' => $skillId,
                    'proficiency' => 'intermediate',
                ]);
            }

            $studentProfile->update([
                'university' => $validated['university'],
                'graduation_year' => $validated['graduation_year'],
                'phone' => $validated['phone'] ?? null,
                'professional_specialization' => $validated['professional_specialization'],
                'onboarding_completed' => true,
            ]);
        });

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
