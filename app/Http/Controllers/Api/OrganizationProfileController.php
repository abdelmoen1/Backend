<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganizationProfileRequest;
use App\Http\Resources\OrganizationProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'organization') {
            return response()->json([
                'success' => false,
                'message' => 'Only organizations can access this profile.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => new OrganizationProfileResource(
                $request->user()->organizationProfile?->load('user')
            ),
        ]);
    }

    public function update(UpdateOrganizationProfileRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $organizationProfile = $user->organizationProfile()->updateOrCreate([], [
            'organization_name' => $validated['organization_name'],
            'organization_type' => $validated['organization_type'],
            'industry' => $validated['industry'],
            'description' => $validated['organization_description'],
            'location' => $validated['location'],
            'website' => $validated['website'] ?? null,
            'contact_person' => $validated['contact_person'] ?? null,
            'onboarding_completed' => true,
        ]);
        $user->update(['name' => $validated['organization_name']]);

        return response()->json([
            'success' => true,
            'message' => 'Organization onboarding completed successfully.',
            'data' => new OrganizationProfileResource(
                $organizationProfile->load('user')
            ),
        ]);
    }
}
