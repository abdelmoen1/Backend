<?php

namespace App\Http\Controllers\Api;

use App\Contracts\ProjectProposalGenerator;
use App\Enums\ProjectRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RequestProjectRevisionRequest;
use App\Http\Requests\StoreProjectRequestRequest;
use App\Http\Requests\UpdateProjectRequestRequest;
use App\Http\Resources\ProjectRequestResource;
use App\Models\IntakeSession;
use App\Models\ProjectRequest;
use App\Services\AI\DiscoveryRequirements;
use App\Services\AI\ProjectProposalException;
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
        $intakeData = $validated['intake_data'] ?? [];
        unset($validated['intake_data']);

        if ($record->proposal) {
            $record->proposal = null;
            $record->proposal_generated_at = null;
        }
        $record->fill($validated);
        $record->intake_data = array_merge(
            $record->intake_data ?? [],
            $intakeData
        );
        $record->status = ProjectRequestStatus::INTAKE_IN_PROGRESS;
        $formChanged = $record->isDirty(['title', 'problem_description'])
            || ($record->getOriginal('intake_data')['short_title'] ?? null) !== ($record->intake_data['short_title'] ?? null);
        $record->save();

        // تعديل نموذج المشكلة بعد بدء المحادثة يُعاد استخراجه في الدور التالي حتى تتحدث المتطلبات
        $session = $record->intakeSession;
        if ($formChanged && $session && DiscoveryRequirements::isTracked($session->current_state)) {
            $state = $session->current_state;
            $state['requirements']['form_processed'] = false;
            $session->current_state = $state;
            $session->save();
        }

        return response()->json([
            'success' => true,
            'data' => new ProjectRequestResource($record->fresh()),
        ]);
    }

    public function destroy(Request $request, int $projectRequest): JsonResponse
    {
        if ($response = $this->organizationOnly($request)) {
            return $response;
        }

        $record = $this->ownedRequest($request, $projectRequest);
        if ($record->status === ProjectRequestStatus::CONVERTED_TO_OPPORTUNITY) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف طلب تحول إلى فرصة.',
            ], 422);
        }

        // جلسة الاكتشاف وإجاباتها تُحذف تلقائيًا عبر cascadeOnDelete
        $record->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف المشروع بنجاح.',
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

        $session = $record->intakeSession;
        if (! $this->readyForProposal($record, $session)) {
            return response()->json([
                'success' => false,
                'message' => 'يرجى إكمال اكتشاف احتياج المشروع قبل إعداد المقترح.',
            ], 422);
        }

        try {
            $proposal = $proposalGenerator->generate($record);
        } catch (ProjectProposalException $exception) {
            // فشل التوليد لا يغيّر الجلسة ولا المقترح السابق
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 503);
        }

        $record = DB::transaction(function () use ($record, $session, $proposal): ProjectRequest {
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

            // المقترح الجاهز يعني أن الاكتشاف مكتمل؛ السؤال المفتوح (عند الإنهاء المبكر) يُعلَّم كمتجاوز ولا يُحذف
            $lockedSession = IntakeSession::query()->whereKey($session->id)->lockForUpdate()->first();
            if ($lockedSession && $lockedSession->status !== IntakeSession::STATUS_COMPLETED) {
                $state = $lockedSession->current_state ?? [];
                if ($lockedSession->current_question_id) {
                    $state['skipped_questions'] = array_values(array_unique(array_merge(
                        is_array($state['skipped_questions'] ?? null) ? $state['skipped_questions'] : [],
                        [$lockedSession->current_question_id]
                    )));
                }
                $lockedSession->status = IntakeSession::STATUS_COMPLETED;
                $lockedSession->current_question_id = null;
                $lockedSession->current_state = $state;
                $lockedSession->save();
            }

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

        $session = $record->intakeSession;
        if ($session && $this->hasPendingQuestion($session)) {
            return response()->json([
                'success' => false,
                'message' => 'لدى المساعد سؤال مفتوح في المحادثة. أجب عنه أولًا ثم أرسل التعديل.',
            ], 409);
        }

        $note = trim((string) $request->validated('note'));

        DB::transaction(function () use ($record, $session, $note): void {
            $record->revision_note = $note;
            $record->status = ProjectRequestStatus::REVISION_REQUESTED;
            $record->save();

            if (! $session) {
                return;
            }

            // طلب التعديل يُضاف إلى نفس المحادثة كرسالة من المؤسسة، فيكمل المساعد من حيث توقف
            $lockedSession = IntakeSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $sequence = (int) $lockedSession->responses()->max('sequence') + 1;
            $turn = $lockedSession->responses()->create([
                'sequence' => $sequence,
                'question_id' => ProjectDiscoveryController::REVISION_PREFIX.$sequence,
                'question_text' => 'ما الذي تريد تعديله أو توضيحه في المقترح؟',
                'question_type' => 'long_text',
                'options' => [],
                'selected_answer' => $note,
                'answered_at' => now(),
            ]);

            $state = $lockedSession->current_state ?? [];
            $state['confirmed_requirements'] = DiscoveryRequirements::appendConfirmedAnswer(
                $state['confirmed_requirements'] ?? [],
                $turn
            );
            $lockedSession->status = IntakeSession::STATUS_ACTIVE;
            $lockedSession->current_question_id = null;
            $lockedSession->current_state = $state;
            $lockedSession->save();
        });

        return response()->json([
            'success' => true,
            'data' => new ProjectRequestResource($record->fresh()),
        ]);
    }

    /**
     * يمكن إعداد المقترح عندما: اكتمل الاكتشاف، أو سُجل طلب تعديل دون سؤال مفتوح
     * (المسار المباشر الحالي)، أو غطت الحالة المنظمة كل المحاور الأساسية (إنهاء مبكر بطلب المؤسسة).
     */
    private function readyForProposal(ProjectRequest $record, ?IntakeSession $session): bool
    {
        if (! $session) {
            return false;
        }

        if ($session->status === IntakeSession::STATUS_COMPLETED) {
            return true;
        }

        if ($record->status === ProjectRequestStatus::REVISION_REQUESTED && ! $this->hasPendingQuestion($session)) {
            return true;
        }

        return DiscoveryRequirements::isReady($session->current_state);
    }

    private function hasPendingQuestion(IntakeSession $session): bool
    {
        return $session->status !== IntakeSession::STATUS_COMPLETED
            && $session->current_question_id !== null
            && $session->responses()
                ->where('question_id', $session->current_question_id)
                ->whereNull('answered_at')
                ->exists();
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
