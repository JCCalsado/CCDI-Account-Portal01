<?php

namespace App\Http\Controllers;

use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\WorkflowApproval;
use App\Services\WorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class WorkflowApprovalController extends Controller
{
    public function __construct(protected WorkflowService $workflowService)
    {
    }

    public function index(Request $request)
    {
        $user     = auth()->user();
        $userRole = $user->role->value ?? null;

        $query = WorkflowApproval::query()
            ->with([
                'workflowInstance.workflow',
                'workflowInstance.workflowable.user',
            ]);

        if ($userRole === 'accounting') {
            $query->whereHas('workflowInstance.workflow', function ($wq) {
                $wq->where('type', 'payment_approval');
            });
        } else {
            $query->where('approver_id', $user->id);
        }

        $approvals = $query
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Approvals/Index', [
            'approvals' => $approvals,
            'filters'   => $request->only(['status', 'year', 'semester']),
        ]);
    }

    public function show(WorkflowApproval $approval)
    {
        $this->authorize('view', $approval);

        $approval->load([
            'workflowInstance.workflow',
            'workflowInstance.workflowable.user',
            'workflowInstance.approvals',
        ]);

        $transaction = $approval->workflowInstance->workflowable;
        $student     = null;
        $unpaidTerms = collect();
        $assessment  = null;
        $proofUrl    = null;
        $proofType   = null;

        if ($transaction instanceof \App\Models\Transaction && $transaction->user && $transaction->user->student) {
            $student = $transaction->user->student->load('user');

            $assessmentId = $transaction->meta['assessment_id'] ?? null;
            if ($assessmentId) {
                $assessment = StudentAssessment::find($assessmentId);
            }

            $unpaidTerms = StudentPaymentTerm::whereHas('assessment', function ($q) use ($transaction) {
                    $q->where('user_id', $transaction->user_id);
                })
                ->whereIn('status', ['pending', 'partial'])
                ->orderBy('due_date', 'asc')
                ->get();

            // Use url('/storage/...') instead of Storage::disk('public')->url()
            // to guarantee the http:// scheme regardless of APP_URL formatting.
            $proofPath = $transaction->meta['proof_of_payment'] ?? null;

            if ($proofPath && Storage::disk('public')->exists($proofPath)) {
                $proofUrl  = url('/storage/' . $proofPath);
                $extension = strtolower(pathinfo($proofPath, PATHINFO_EXTENSION));
                $proofType = $extension === 'pdf' ? 'pdf' : 'image';
            }
        }

        return Inertia::render('Approvals/Show', [
            'approval'    => $approval,
            'student'     => $student,
            'unpaidTerms' => $unpaidTerms,
            'assessment'  => $assessment,
            'proofUrl'    => $proofUrl,
            'proofType'   => $proofType,
        ]);
    }

    public function approve(Request $request, WorkflowApproval $approval)
    {
        $this->authorize('approve', $approval);

        if ($approval->status !== 'pending') {
            return back()->with('flash.error', 'This approval has already been processed.');
        }

        $validated = $request->validate([
            'comments' => 'nullable|string|max:1000',
        ]);

        try {
            $this->workflowService->approveStep(
                $approval,
                auth()->id(),
                $validated['comments'] ?? null,
            );

            return redirect()->route('approvals.index')
                ->with('flash.success', 'Payment approved successfully.');
        } catch (\LogicException $e) {
            // Thrown by WorkflowService when a concurrent request already approved
            // this record (double-click race condition). Not a system error — return
            // a clean informational message without report()-ing to Sentry/logs.
            return back()->with('flash.info', 'This approval was already processed. No duplicate action taken.');
        } catch (\Throwable $e) {
            report($e);

            return back()->with('flash.error', 'Approval failed: ' . $e->getMessage());
        }
    }

    public function reject(Request $request, WorkflowApproval $approval)
    {
        $this->authorize('approve', $approval);

        if ($approval->status !== 'pending') {
            return back()->with('flash.error', 'This approval has already been processed.');
        }

        $validated = $request->validate([
            'comments' => 'required|string|max:1000',
        ]);

        try {
            $this->workflowService->rejectStep(
                $approval,
                auth()->id(),
                $validated['comments'],
            );

            return redirect()->route('approvals.index')
                ->with('flash.success', 'Payment declined.');
        } catch (\LogicException $e) {
            return back()->with('flash.info', 'This approval was already processed. No duplicate action taken.');
        } catch (\Throwable $e) {
            report($e);

            return back()->with('flash.error', 'Rejection failed: ' . $e->getMessage());
        }
    }
}