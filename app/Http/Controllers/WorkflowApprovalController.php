<?php

namespace App\Http\Controllers;

use App\Models\WorkflowApproval;
use App\Models\WorkflowInstance;
use App\Models\StudentPaymentTerm;
use App\Models\StudentAssessment;
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
        $proofType   = null; // 'image' | 'pdf' | null

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

            // ── PROOF OF PAYMENT ─────────────────────────────────────────────
            // The path is stored as a relative path inside the 'public' disk,
            // e.g. "payment_proofs/proof_5_1746000000.jpg".
            // Storage::disk('public')->url() converts it to an accessible URL.
            $proofPath = $transaction->meta['proof_of_payment'] ?? null;

            if ($proofPath && Storage::disk('public')->exists($proofPath)) {
                $proofUrl  = Storage::disk('public')->url($proofPath);
                $extension = strtolower(pathinfo($proofPath, PATHINFO_EXTENSION));
                $proofType = $extension === 'pdf' ? 'pdf' : 'image';
            }
        }

        return Inertia::render('Approvals/Show', [
            'approval'    => $approval,
            'student'     => $student,
            'unpaidTerms' => $unpaidTerms,
            'assessment'  => $assessment,
            'proofUrl'    => $proofUrl,   // string|null — publicly accessible URL
            'proofType'   => $proofType,  // 'image'|'pdf'|null
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
                $validated['comments'] ?? null
            );
        } catch (\Exception $e) {
            return back()->with('flash.error', $e->getMessage());
        }

        return redirect()->route('approvals.index')
            ->with('flash.success', 'Payment approved successfully.');
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
                $validated['comments']
            );
        } catch (\Exception $e) {
            return back()->with('flash.error', $e->getMessage());
        }

        return redirect()->route('approvals.index')
            ->with('flash.success', 'Payment declined.');
    }
}