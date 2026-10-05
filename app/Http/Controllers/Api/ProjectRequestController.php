<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ProjectProposalGenerator;
use App\Enums\ProjectRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RequestProjectRevisionRequest;
use App\Http\Requests\SaveProjectIntakeRequest;
use App\Http\Requests\StoreProjectRequestRequest;
use App\Http\Requests\UpdateProjectRequestRequest;
use App\Http\Resources\ProjectRequestResource;
use App\Models\IntakeSession;
use App\Models\ProjectRequest;
use App\Services\AI\ProjectProposalException;
use App\Services\IntakeQuestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($response = $this->organizationOnly($request)) {
            return $response;
        }

        $requests = $request->user()->organizationProfile
            ->projectRequests()
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => ProjectRequestResource::collection($requests),
        ]);
    }

    public function store(StoreProjectRequestRequest $request): JsonResponse
    {
        $projectRequest = $request->user()->organizationProfile
            ->projectRequests()
            ->create($request->validated());

        return response()->json([
            'success' => true,
            'data' => new ProjectRequestResource($projectRequest->fresh()),
        ], 201);
    }

    public function show(Request $request, int $projectRequest): JsonResponse
    {
        if ($response = $this->organizationOnly($request)) {
            return $response;
        }

        return $this->projectRequestResponse($request, $projectRequest);
    }

    public function update(
        UpdateProjectRequestRequest $request,
        int $projectRequest
    ): JsonResponse {
        $record = $this->ownedRequest($request, $projectRequest);
        $record->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => new ProjectRequestResource($record->fresh()),
        ]);
    }

    public function intake(
        Request $request,
        int $projectRequest,
        IntakeQuestionService $questions
    ): JsonResponse {
        if ($response = $this->organizationOnly($request)) {
            return $response;
        }

        $record = $this->ownedRequest($request, $projectRequest);

        return response()->json([
            'success' => true,
            'data' => [
                'project_request' => new ProjectRequestResource($record),
                'questions' => $questions->questions($record),
            ],
        ]);
    }

    public function saveIntake(
        SaveProjectIntakeRequest $request,
        int $projectRequest
    ): JsonResponse {
        $record = $this->ownedRequest($request, $projectRequest);
        if ($record->status === ProjectRequestStatus::APPROVED) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تعديل طلب تم اعتماد مقترحه.',
            ], 422);
        }
        if (in_array($record->status, [
            ProjectRequestStatus::REJECTED,
            ProjectRequestStatus::CONVERTED_TO_OPPORTUNITY,
        ], true)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إعداد مقترح لطلب مشروع مغلق.',
            ], 422);
        }

        $validated = $request->validated();
        $responses = $validated['responses'] ?? [];
        unset($validated['responses']);

        if ($record->proposal) {
            $record->proposal = null;
            $record->proposal_generated_at = null;
        }
        $record->fill($validated);
        $record->intake_data = array_merge(
            $record->intake_data ?? [],
            $responses
        );
        $record->status = ProjectRequestStatus::INTAKE_IN_PROGRESS;
        $record->save();

        return response()->json([
            'success' => true,
            'data' => new ProjectRequestResource($record->fresh()),
        ]);
    }

    public function analyze(
        Request $request,
        int $projectRequest,
        ProjectProposalGenerator $proposalGenerator
    ): JsonResponse {
        if ($response = $this->organizationOnly($request)) {
            return $response;
        }

        $record = $this->ownedRequest($request, $projectRequest);
        if ($record->status === ProjectRequestStatus::APPROVED) {
            return response()->json([
                'success' => false,
                'message' => 'تم اعتماد هذا المقترح بالفعل.',
            ], 422);
        }

        if (
            $record->proposal
            && $record->status === ProjectRequestStatus::PROPOSAL_READY
        ) {
            return response()->json([
                'success' => true,
                'data' => new ProjectRequestResource($record),
            ]);
        }

        if (! $record->problem_description) {
            return response()->json([
                'success' => false,
                'message' => 'يرجى حفظ وصف المشكلة قبل إعداد المقترح.',
            ], 422);
        }

        if ($record->intakeSession?->status !== IntakeSession::STATUS_COMPLETED) {
            return response()->json([
                'success' => false,
                'message' => 'يرجى إكمال اكتشاف احتياج المشروع قبل إعداد المقترح.',
            ], 422);
        }

        try {
            $proposal = $proposalGenerator->generate($record);
        } catch (ProjectProposalException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 503);
        }

        $record = DB::transaction(function () use ($record, $proposal): ProjectRequest {
            $locked = ProjectRequest::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === ProjectRequestStatus::APPROVED) {
                abort(422, 'تم اعتماد هذا المقترح بالفعل.');
            }

            $locked->proposal = $proposal;
            $locked->proposal_generated_at = now();
            $locked->status = ProjectRequestStatus::PROPOSAL_READY;
            $locked->revision_note = null;
            $locked->save();

            return $locked->fresh();
        });

        return response()->json([
            'success' => true,
            'data' => new ProjectRequestResource($record),
        ]);
    }

    public function approve(Request $request, int $projectRequest): JsonResponse
    {
        if ($response = $this->organizationOnly($request)) {
            return $response;
        }

        $record = $this->ownedRequest($request, $projectRequest);
        if (! $record->proposal || ! in_array($record->status, [
            ProjectRequestStatus::PROPOSAL_READY,
            ProjectRequestStatus::REVISION_REQUESTED,
        ], true)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد مقترح جاهز للاعتماد.',
            ], 422);
        }

        $record = DB::transaction(function () use ($record): ProjectRequest {
            $locked = ProjectRequest::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === ProjectRequestStatus::APPROVED) {
                abort(422, 'تم اعتماد هذا المقترح بالفعل.');
            }
            if (! $locked->proposal || ! in_array($locked->status, [
                ProjectRequestStatus::PROPOSAL_READY,
                ProjectRequestStatus::REVISION_REQUESTED,
            ], true)) {
                abort(422, 'لا يوجد مقترح جاهز للاعتماد.');
            }

            $locked->status = ProjectRequestStatus::APPROVED;
            $locked->approved_at = now();
            $locked->save();

            return $locked->fresh();
        });

        return response()->json([
            'success' => true,
            'data' => new ProjectRequestResource($record),
        ]);
    }

    public function revision(
        RequestProjectRevisionRequest $request,
        int $projectRequest
    ): JsonResponse {
        $record = $this->ownedRequest($request, $projectRequest);
        if (! $record->proposal) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد مقترح لمراجعته بعد.',
            ], 422);
        }
        if (in_array($record->status, [
            ProjectRequestStatus::APPROVED,
            ProjectRequestStatus::CONVERTED_TO_OPPORTUNITY,
            ProjectRequestStatus::REJECTED,
        ], true)) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن طلب تعديل لمقترح تم إغلاقه.',
            ], 422);
        }

        $record->revision_note = $request->validated('note');
        $record->status = ProjectRequestStatus::REVISION_REQUESTED;
        $record->save();

        return response()->json([
            'success' => true,
            'data' => new ProjectRequestResource($record->fresh()),
        ]);
    }

    private function projectRequestResponse(
        Request $request,
        int $projectRequest
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => new ProjectRequestResource(
                $this->ownedRequest($request, $projectRequest)
            ),
        ]);
    }

    private function ownedRequest(Request $request, int $projectRequest): ProjectRequest
    {
        $profile = $request->user()->organizationProfile;

        abort_unless($profile, 403, 'Organization profile not found.');

        return $profile->projectRequests()->findOrFail($projectRequest);
    }

    private function organizationOnly(Request $request): ?JsonResponse
    {
        if (
            $request->user()->role === 'organization'
            && $request->user()->organizationProfile
        ) {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Only organizations can access project requests.',
        ], 403);
    }
}
