<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { useDataFormatting } from '@/composables/useDataFormatting';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Download, ExternalLink, FileText, ImageOff, Info, RotateCcw, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

// ─── Types ────────────────────────────────────────────────────────────────────

interface TransactionMeta {
    term_name?: string;
    proof_of_payment?: string;
    description?: string;
    selected_term_id?: number;
    assessment_id?: number;
}

interface Approval {
    id: number;
    status: 'pending' | 'approved' | 'rejected';
    step_name: string;
    approver_name: string | null;
    comments: string | null;
    created_at: string;
    updated_at: string;
    approved_at: string | null;
    workflow_instance?: {
        workflowable: {
            amount?: number;
            reference?: string;
            meta?: TransactionMeta;
            type?: string;
            payment_channel?: string;
            user?: { first_name: string; last_name: string; account_id: string };
        };
    };
}

interface Student {
    id: number;
    student_id: string | null;
    user?: {
        first_name: string;
        last_name: string;
        account_id: string;
    };
}

interface UnpaidTerm {
    id: number;
    term_name: string;
    amount: number;
    balance: number;
    due_date: string | null;
    status: string;
}

/**
 * UnpaidTerm enriched with waterfall allocation data.
 * Only meaningful when approval.status === 'pending'.
 */
interface AllocatedTerm extends UnpaidTerm {
    applied: number;
    projectedBalance: number;
    /**
     * derivedStatus — what this term's status will be AFTER approval.
     *   'paid'      → fully settled by this payment
     *   'processed' → partial payment applied; remaining balance carried forward
     *   'partial'   → balance remains (only for the final active term)
     *   'pending'   → not affected by this payment
     */
    derivedStatus: 'paid' | 'partial' | 'pending' | 'processed';
    isAffected: boolean;
    isStartingTerm: boolean;
    // Carry-forward fields (one-time term processing rule)
    carriedForward: number;         // peso amount carried to next term (0 if none)
    carriedToTerm:  string | null;  // name of the receiving term
}

interface Assessment {
    id: number;
    assessment_number: string;
    school_year: string;
    semester: string;
    total_assessment: number;
    status: string;
}

interface Props {
    approval: Approval;
    student?: Student | null;
    unpaidTerms?: UnpaidTerm[];
    assessment?: Assessment | null;
    proofUrl?: string | null;
    proofType?: 'image' | 'pdf' | null;
}

// ─── Props & Composables ─────────────────────────────────────────────────────

const props = defineProps<Props>();

const { formatCurrency } = useDataFormatting();

// ─── Breadcrumbs ─────────────────────────────────────────────────────────────

const breadcrumbs = [
    { title: 'Dashboard', href: route('accounting.dashboard') },
    { title: 'Approvals', href: route('approvals.index') },
    { title: 'Details' },
];

// ─── Helpers ──────────────────────────────────────────────────────────────────

const formatDate = (date: string | null) => {
    if (!date) return '—';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const capitalize = (str: string) => str.charAt(0).toUpperCase() + str.slice(1);

const paymentMethodLabel: Record<string, string> = {
    cash: 'Cash',
    gcash: 'GCash',
    bank_transfer: 'Bank Transfer',
    credit_card: 'Credit Card',
    debit_card: 'Debit Card',
    paymongo_checkout: 'PayMongo',
};

// ─── UI State ─────────────────────────────────────────────────────────────────

const showRejectDialog = ref(false);
const proofLoadError   = ref(false);

const approveForm = useForm({});

const approve = () => {
    approveForm.post(route('approvals.approve', props.approval.id));
};

const rejectForm = useForm({ comments: '' });

const openRejectDialog = () => {
    rejectForm.reset();
    showRejectDialog.value = true;
};

const reject = () => {
    rejectForm.post(route('approvals.reject', props.approval.id), {
        onSuccess: () => {
            showRejectDialog.value = false;
        },
    });
};

const refreshApproval = () => router.reload();
const onProofLoadError = () => { proofLoadError.value = true; };

// ─── Payment Allocation Computeds ─────────────────────────────────────────────

/** The gross payment amount submitted by the student. */
const paymentAmount = computed((): number =>
    Number(props.approval.workflow_instance?.workflowable?.amount ?? 0),
);

/** The term the student selected when submitting the payment. */
const selectedTermId = computed((): number | null =>
    props.approval.workflow_instance?.workflowable?.meta?.selected_term_id ?? null,
);

/**
 * Waterfall allocation preview.
 *
 * Rules:
 * 1. Locate the starting term from selectedTermId.
 *    If the selected term is not found in unpaidTerms (already paid / data race),
 *    fall back to starting from index 0.
 * 2. Terms that appear BEFORE the starting term in the server-ordered list
 *    are untouched by this payment.
 * 3. From the starting term onward, allocate the payment amount sequentially:
 *    each term gets min(remaining, balance).
 * 4. A term is 'paid' when applied >= balance, 'partial' when 0 < applied < balance.
 */
// ─────────────────────────────────────────────────────────────────────────────
// ALLOCATION PREVIEW — mirrors backend allocatePaymentAcrossTerms() exactly.
//
// Integer-cents arithmetic throughout. No floating-point operations.
// Mirrors the same two-step algorithm used in StudentPaymentService.php and
// the student-facing Payment/Create.vue preview.
//
// STEP 1: Apply payment sequentially from the selected starting term.
// STEP 2: Close-and-carry — any term that received a partial payment is
//         CLOSED (derivedStatus = 'processed', projectedBalance = 0) and
//         the remaining balance is carried forward to the next term.
// ─────────────────────────────────────────────────────────────────────────────

/** Convert a peso value to integer cents. Avoids float precision drift. */
const _toCents = (v: number | string): number => Math.round(parseFloat(String(v)) * 100);
/** Convert integer cents back to peso float. */
const _fromCents = (c: number): number => c / 100;

const allocationPreview = computed((): AllocatedTerm[] => {
    const terms = props.unpaidTerms ?? [];
    if (!terms.length) return [];

    const amountCents = _toCents(paymentAmount.value);
    const startId     = selectedTermId.value;

    let startIdx = startId !== null ? terms.findIndex((t) => t.id === startId) : -1;
    if (startIdx === -1) startIdx = 0; // graceful fallback: start from first term

    // ── STEP 1: Sequential allocation in integer cents ────────────────────────
    let remainingCents = amountCents;

    const result: AllocatedTerm[] = terms.map((term, idx): AllocatedTerm => {
        const isStartingTerm = term.id === startId;

        // Terms before the starting term are untouched.
        if (idx < startIdx || remainingCents <= 0) {
            return {
                ...term,
                applied:          0,
                projectedBalance: term.balance,
                derivedStatus:    term.status as AllocatedTerm['derivedStatus'],
                isAffected:       false,
                isStartingTerm,
                carriedForward:   0,
                carriedToTerm:    null,
            };
        }

        const balBeforeCents   = _toCents(term.balance);
        const appliedCents     = Math.min(remainingCents, balBeforeCents);
        const balAfterCents    = balBeforeCents - appliedCents;
        remainingCents        -= appliedCents;

        let derivedStatus: AllocatedTerm['derivedStatus'] = 'pending';
        if (appliedCents >= balBeforeCents) derivedStatus = 'paid';
        else if (appliedCents > 0)          derivedStatus = 'partial';

        return {
            ...term,
            applied:          _fromCents(appliedCents),
            projectedBalance: _fromCents(balAfterCents),
            derivedStatus,
            isAffected:       appliedCents > 0,
            isStartingTerm,
            carriedForward:   0,      // populated in Step 2
            carriedToTerm:    null,
        };
    });

    // ── STEP 2: Close-and-carry (one-time term processing rule) ───────────────
    // For any term that ended Step 1 with derivedStatus = 'partial' (balance > 0):
    //   - Find the next term in the result array (by position, since terms are
    //     already sorted by term_order from props.unpaidTerms).
    //   - Annotate the carry details on the current term's result entry.
    //   - Close the current term: projectedBalance = 0, derivedStatus = 'processed'.
    //
    // Note: we do NOT add the carry amount to the next term's projectedBalance
    // in the preview because that would require cascading recalculation. The
    // accounting reviewer sees the carry annotation and understands the effect.
    // The actual balance transfer happens server-side on approval.
    for (let i = 0; i < result.length; i++) {
        const entry = result[i];
        if (entry.derivedStatus !== 'partial' || entry.projectedBalance <= 0) continue;

        const carryoverCents = _toCents(entry.projectedBalance);

        // Find the next unpaid term in the list (may not be adjacent if terms
        // before the start were already paid/processed).
        const nextEntry = result.slice(i + 1).find(
            (t) => t.isAffected || (_toCents(t.balance) > 0 && !t.isAffected)
        ) ?? result[i + 1] ?? null;

        entry.derivedStatus    = 'processed';
        entry.carriedForward   = _fromCents(carryoverCents);
        entry.carriedToTerm    = nextEntry?.term_name ?? null;
        entry.projectedBalance = 0;  // backend will zero this term
    }

    return result;
});

/** Total amount that will be distributed across terms. */
const totalApplied = computed((): number =>
    allocationPreview.value.reduce((sum, t) => sum + t.applied, 0),
);

/**
 * Excess amount after all unpaid balances are satisfied.
 * > 0 means this payment creates a credit on the student's account.
 */
const excessAmount = computed((): number =>
    Math.max(0, paymentAmount.value - totalApplied.value),
);

/** Number of terms that receive at least partial coverage. */
const affectedTermCount = computed((): number =>
    allocationPreview.value.filter((t) => t.isAffected).length,
);

/** Number of terms fully paid off by this payment. */
const fullyPaidTermCount = computed((): number =>
    allocationPreview.value.filter((t) => t.derivedStatus === 'paid').length,
);

/** Number of terms that will be closed via carry-forward (one-time processing rule). */
const processedTermCount = computed((): number =>
    allocationPreview.value.filter((t) => t.derivedStatus === 'processed').length,
);
</script>

<template>
    <Head title="Approval Details" />

    <AppLayout>
        <div class="w-full space-y-6 p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- ── Page Header ── -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold">Approval Details</h1>
                    <p class="text-gray-500">Review and action this workflow approval</p>
                </div>
                <button
                    @click="refreshApproval"
                    title="Refresh approval details"
                    class="rounded-lg border border-gray-300 bg-white p-2 text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900"
                >
                    <RotateCcw :size="20" />
                </button>
            </div>

            <!-- ── Main Card ── -->
            <div class="space-y-6 rounded-xl border bg-white p-6 shadow-sm">

                <!-- Status Badge + Amount -->
                <div class="flex items-center justify-between">
                    <span
                        class="rounded-full px-4 py-2 text-sm font-semibold"
                        :class="{
                            'bg-yellow-100 text-yellow-800': approval.status === 'pending',
                            'bg-green-100 text-green-800':  approval.status === 'approved',
                            'bg-red-100 text-red-800':      approval.status === 'rejected',
                        }"
                    >
                        {{ capitalize(approval.status) }}
                    </span>
                    <p
                        v-if="approval.workflow_instance?.workflowable?.amount"
                        class="text-2xl font-bold text-blue-700"
                    >
                        {{ formatCurrency(approval.workflow_instance.workflowable.amount) }}
                    </p>
                </div>

                <!-- Student + Reference -->
                <div class="grid grid-cols-2 gap-4 border-b pb-4">
                    <div>
                        <p class="text-sm text-gray-500">Student</p>
                        <p class="font-semibold">
                            {{
                                student?.user
                                    ? `${student.user.last_name}, ${student.user.first_name}`
                                    : approval.workflow_instance?.workflowable?.user
                                        ? `${approval.workflow_instance.workflowable.user.last_name}, ${approval.workflow_instance.workflowable.user.first_name}`
                                        : '—'
                            }}
                        </p>
                        <p class="text-xs text-gray-400">
                            {{ student?.user?.account_id ?? approval.workflow_instance?.workflowable?.user?.account_id ?? '' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Reference</p>
                        <p class="font-mono font-semibold">
                            {{ approval.workflow_instance?.workflowable?.reference ?? '—' }}
                        </p>
                    </div>
                </div>

                <!-- Payment Details -->
                <div class="grid grid-cols-2 gap-4 border-b pb-4">
                    <div>
                        <p class="text-sm text-gray-500">Term</p>
                        <p class="font-semibold">
                            {{
                                approval.workflow_instance?.workflowable?.meta?.term_name
                                ?? approval.workflow_instance?.workflowable?.type
                                ?? '—'
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Payment Method</p>
                        <p class="font-semibold">
                            {{
                                paymentMethodLabel[approval.workflow_instance?.workflowable?.payment_channel ?? '']
                                ?? approval.workflow_instance?.workflowable?.payment_channel
                                ?? '—'
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Assessment No.</p>
                        <p class="font-mono font-semibold">{{ assessment?.assessment_number ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">School Year / Semester</p>
                        <p class="font-semibold">
                            {{ assessment ? `${assessment.school_year} · ${assessment.semester}` : '—' }}
                        </p>
                    </div>
                </div>

                <!-- Approver & Dates -->
                <div class="grid grid-cols-2 gap-4 border-b pb-4">
                    <div>
                        <p class="text-sm text-gray-500">Payment Approved</p>
                        <p class="font-semibold">{{ approval.approved_at ? formatDate(approval.approved_at) : '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Submitted</p>
                        <p class="font-semibold">{{ formatDate(approval.created_at) }}</p>
                    </div>
                </div>

                <!-- ── PROOF OF PAYMENT ────────────────────────────────────── -->
                <div class="border-t pt-6">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="font-semibold text-gray-900">Proof of Payment</h3>
                        <a
                            v-if="proofUrl"
                            :href="proofUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
                        >
                            <Download :size="14" />
                            Download
                        </a>
                    </div>

                    <!-- No proof -->
                    <div
                        v-if="!proofUrl"
                        class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-200 bg-gray-50 py-12 text-center"
                    >
                        <ImageOff :size="40" class="mb-3 text-gray-300" />
                        <p class="text-sm font-medium text-gray-500">No proof of payment submitted</p>
                        <p class="mt-1 text-xs text-gray-400">
                            This may be a PayMongo (online) payment — no manual receipt is required.
                        </p>
                    </div>

                    <!-- Image proof -->
                    <div v-else-if="proofType === 'image'" class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50">
                        <div
                            v-if="proofLoadError"
                            class="flex flex-col items-center justify-center py-12 text-center"
                        >
                            <ImageOff :size="36" class="mb-2 text-gray-300" />
                            <p class="text-sm text-gray-500">Image could not be loaded.</p>
                            <a
                                :href="proofUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:underline"
                            >
                                <ExternalLink :size="14" />
                                Open in new tab
                            </a>
                        </div>
                        <img
                            v-else
                            :src="proofUrl"
                            alt="Proof of payment"
                            class="max-h-[600px] w-full object-contain"
                            @error="onProofLoadError"
                        />
                    </div>

                    <!-- PDF proof -->
                    <div v-else-if="proofType === 'pdf'" class="overflow-hidden rounded-xl border border-gray-200">
                        <div class="flex items-center gap-3 border-b bg-gray-50 px-4 py-3">
                            <FileText :size="20" class="text-red-500" />
                            <span class="text-sm font-medium text-gray-700">PDF Receipt</span>
                            <a
                                :href="proofUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="ml-auto inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:underline"
                            >
                                <ExternalLink :size="14" />
                                Open in new tab
                            </a>
                        </div>
                        <iframe
                            :src="proofUrl"
                            class="h-[600px] w-full border-0"
                            title="Proof of payment PDF"
                        />
                    </div>
                </div>
                <!-- ── END PROOF OF PAYMENT ────────────────────────────────── -->

                <!-- Comments (if any) -->
                <div v-if="approval.comments" class="border-t pt-4">
                    <p class="text-sm text-gray-500">Comment</p>
                    <p class="mt-2 rounded-lg bg-gray-50 p-4 text-sm">{{ approval.comments }}</p>
                </div>

                <!-- ── PAYMENT ALLOCATION PREVIEW ────────────────────────── -->
                <div v-if="unpaidTerms && unpaidTerms.length > 0" class="border-t pt-6">

                    <!-- Section header + summary badges -->
                    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-gray-900">
                                <template v-if="approval.status === 'pending'">
                                    Payment Allocation Preview
                                </template>
                                <template v-else>
                                    Other Unpaid Payment Terms
                                </template>
                            </h3>
                            <p v-if="approval.status === 'pending'" class="mt-0.5 text-sm text-gray-500">
                                How {{ formatCurrency(paymentAmount) }} will be distributed if approved
                            </p>
                        </div>

                        <!-- Allocation summary badges (pending only) -->
                        <div
                            v-if="approval.status === 'pending'"
                            class="flex flex-wrap items-center gap-2 text-xs"
                        >
                            <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-1 font-semibold text-blue-800">
                                {{ formatCurrency(totalApplied) }} applied
                            </span>
                            <span
                                v-if="fullyPaidTermCount > 0"
                                class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-1 font-semibold text-green-800"
                            >
                                {{ fullyPaidTermCount }} term{{ fullyPaidTermCount !== 1 ? 's' : '' }} fully paid
                            </span>
                            <!--
                                Processed badge: shown when the one-time term processing rule fires.
                                A 'processed' term received a partial payment and its remaining
                                balance was carried forward to the next term. It is now closed.
                            -->
                            <span
                                v-if="processedTermCount > 0"
                                class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-1 font-semibold text-blue-800"
                            >
                                {{ processedTermCount }} term{{ processedTermCount !== 1 ? 's' : '' }} carried forward
                            </span>
                            <span
                                v-if="excessAmount > 0"
                                class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 font-semibold text-amber-800"
                            >
                                {{ formatCurrency(excessAmount) }} excess
                            </span>
                        </div>
                    </div>

                    <!-- Allocation table -->
                    <div class="overflow-x-auto rounded-lg border">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="border-b px-4 py-3 text-left text-sm font-semibold text-gray-700">
                                        Term
                                    </th>
                                    <th class="border-b px-4 py-3 text-left text-sm font-semibold text-gray-700">
                                        Balance Due
                                    </th>
                                    <!-- "Applied" column only shown for pending approvals -->
                                    <th
                                        v-if="approval.status === 'pending'"
                                        class="border-b px-4 py-3 text-left text-sm font-semibold text-blue-700"
                                    >
                                        Applied
                                    </th>
                                    <th class="border-b px-4 py-3 text-left text-sm font-semibold text-gray-700">
                                        {{ approval.status === 'pending' ? 'After Payment' : 'Balance' }}
                                    </th>
                                    <th class="border-b px-4 py-3 text-left text-sm font-semibold text-gray-700">
                                        Due Date
                                    </th>
                                    <th class="border-b px-4 py-3 text-left text-sm font-semibold text-gray-700">
                                        Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="term in allocationPreview"
                                    :key="term.id"
                                    class="border-b transition-colors"
                                    :class="{
                                        'bg-green-50 hover:bg-green-100/70':  term.isAffected && term.derivedStatus === 'paid',
                                        'bg-blue-50  hover:bg-blue-100/70':   term.isAffected && term.derivedStatus === 'processed',
                                        'bg-amber-50 hover:bg-amber-100/70':  term.isAffected && term.derivedStatus === 'partial',
                                        'hover:bg-gray-50':                   !term.isAffected,
                                    }"
                                >
                                    <!-- Term name + badges -->
                                    <td class="px-4 py-3 text-sm">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <span>{{ term.term_name }}</span>
                                            <span
                                                v-if="term.isStartingTerm"
                                                class="rounded bg-blue-100 px-1.5 py-0.5 text-xs font-semibold text-blue-700"
                                            >
                                                Selected
                                            </span>
                                        </div>
                                        <!-- Carry-forward annotation -->
                                        <div
                                            v-if="approval.status === 'pending' && term.derivedStatus === 'processed' && term.carriedForward > 0"
                                            class="mt-1 flex items-center gap-1 text-xs text-blue-600"
                                        >
                                            <span>↪</span>
                                            <span>
                                                {{ formatCurrency(term.carriedForward) }} carried to
                                                <strong>{{ term.carriedToTerm ?? 'next term' }}</strong>
                                            </span>
                                        </div>
                                    </td>

                                    <!-- Original balance — strikethrough when fully covered or processed -->
                                    <td class="px-4 py-3 text-sm">
                                        <span
                                            :class="
                                                term.isAffected && (term.derivedStatus === 'paid' || term.derivedStatus === 'processed')
                                                    ? 'text-gray-400 line-through'
                                                    : 'font-semibold text-orange-600'
                                            "
                                        >
                                            {{ formatCurrency(term.balance) }}
                                        </span>
                                    </td>

                                    <!-- Applied amount (pending approval only) -->
                                    <td v-if="approval.status === 'pending'" class="px-4 py-3 text-sm">
                                        <span v-if="term.applied > 0" class="font-semibold text-green-700">
                                            + {{ formatCurrency(term.applied) }}
                                        </span>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>

                                    <!-- Projected balance / current balance -->
                                    <td class="px-4 py-3 text-sm font-semibold">
                                        <span
                                            :class="{
                                                'text-green-600':  (approval.status === 'pending' ? term.projectedBalance : term.balance) === 0,
                                                'text-blue-600':   approval.status === 'pending' && term.derivedStatus === 'processed',
                                                'text-amber-600':  approval.status === 'pending' && term.projectedBalance > 0 && term.projectedBalance < term.balance && term.derivedStatus !== 'processed',
                                                'text-orange-600': (approval.status === 'pending' ? term.projectedBalance : term.balance) > 0 && term.derivedStatus === 'pending',
                                            }"
                                        >
                                            <!--
                                                For 'processed' terms: show ₱0.00 (balance was carried out).
                                                For others: show projected (pending approval) or live balance.
                                            -->
                                            {{ formatCurrency(
                                                approval.status === 'pending'
                                                    ? term.projectedBalance
                                                    : term.balance
                                            ) }}
                                        </span>
                                    </td>

                                    <!-- Due date -->
                                    <td class="px-4 py-3 text-sm">{{ formatDate(term.due_date) }}</td>

                                    <!-- Status badge -->
                                    <td class="px-4 py-3 text-sm">
                                        @php
                                        <!--
                                            statusDisplay maps internal status values to human labels.
                                            'processed' = balance carried forward → shown as "Carried Forward"
                                            so reviewers immediately understand the term is closed and
                                            the unpaid amount has moved to the next term.
                                        -->
                                        @endphp
                                        <span
                                            class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="{
                                                'bg-green-100  text-green-800':  (approval.status === 'pending' ? term.derivedStatus : term.status) === 'paid',
                                                'bg-blue-100   text-blue-800':   (approval.status === 'pending' ? term.derivedStatus : term.status) === 'processed',
                                                'bg-amber-100  text-amber-800':  (approval.status === 'pending' ? term.derivedStatus : term.status) === 'partial',
                                                'bg-yellow-100 text-yellow-800': ['pending', 'unpaid'].includes(approval.status === 'pending' ? term.derivedStatus : term.status),
                                                'bg-orange-100 text-orange-800': (approval.status === 'pending' ? term.derivedStatus : term.status) === 'overdue',
                                            }"
                                        >
                                            {{
                                                ({
                                                    paid:      'Paid',
                                                    processed: 'Carried Forward',
                                                    partial:   'Partial',
                                                    pending:   'Unpaid',
                                                    unpaid:    'Unpaid',
                                                    overdue:   'Overdue',
                                                } as Record<string, string>)[approval.status === 'pending' ? term.derivedStatus : term.status]
                                                ?? capitalize(approval.status === 'pending' ? term.derivedStatus : term.status)
                                            }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>

                            <!-- Excess / credit notice -->
                            <tfoot v-if="approval.status === 'pending' && excessAmount > 0">
                                <tr class="bg-amber-50">
                                    <td
                                        colspan="6"
                                        class="px-4 py-3 text-sm text-amber-800"
                                    >
                                        <div class="flex items-start gap-2">
                                            <Info :size="16" class="mt-0.5 flex-shrink-0 text-amber-600" />
                                            <span>
                                                Payment exceeds all outstanding balances by
                                                <strong>{{ formatCurrency(excessAmount) }}</strong>.
                                                The excess will be recorded as a credit on the student's account.
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- "Allocation flows from selected term" note for pending -->
                    <p v-if="approval.status === 'pending' && affectedTermCount > 1" class="mt-2 text-xs text-gray-400">
                        Payment covers the selected term first, then flows to subsequent unpaid terms in order.
                    </p>
                </div>
                <!-- ── END PAYMENT ALLOCATION PREVIEW ────────────────────── -->

                <!-- ── ACTION BUTTONS ─────────────────────────────────────── -->
                <div v-if="approval.status === 'pending'" class="border-t pt-6">
                    <p class="mb-4 text-sm font-semibold text-gray-700">Take Action</p>
                    <div class="grid grid-cols-2 gap-4">
                        <button
                            @click="approve"
                            :disabled="approveForm.processing"
                            class="group relative inline-flex items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-green-500 to-green-600 px-6 py-3 font-semibold text-white shadow-lg transition-all duration-200 hover:scale-105 hover:from-green-600 hover:to-green-700 hover:shadow-xl disabled:scale-100 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <CheckCircle2
                                v-if="!approveForm.processing"
                                :size="20"
                                class="transition-transform group-hover:scale-110"
                            />
                            <span>{{ approveForm.processing ? 'Approving…' : 'Approve' }}</span>
                        </button>
                        <button
                            @click="openRejectDialog"
                            :disabled="approveForm.processing"
                            class="group relative inline-flex items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-red-500 to-red-600 px-6 py-3 font-semibold text-white shadow-lg transition-all duration-200 hover:scale-105 hover:from-red-600 hover:to-red-700 hover:shadow-xl disabled:scale-100 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <XCircle
                                v-if="!approveForm.processing"
                                :size="20"
                                class="transition-transform group-hover:scale-110"
                            />
                            <span>Decline</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Decline Dialog ── -->
        <div
            v-if="showRejectDialog"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
            @click.self="showRejectDialog = false"
        >
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h2 class="mb-1 text-lg font-bold text-gray-900">Decline Payment</h2>
                <p class="mb-4 text-sm text-gray-500">Provide a reason. The student will be notified.</p>

                <textarea
                    v-model="rejectForm.comments"
                    class="w-full rounded-lg border border-gray-300 p-3 text-sm outline-none focus:border-transparent focus:ring-2 focus:ring-red-400"
                    placeholder="Enter rejection reason (required)..."
                    rows="4"
                />
                <p v-if="rejectForm.errors.comments" class="mt-1 text-sm text-red-500">
                    {{ rejectForm.errors.comments }}
                </p>

                <div class="mt-5 flex gap-3">
                    <button
                        @click="showRejectDialog = false"
                        class="flex-1 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        @click="reject"
                        :disabled="rejectForm.processing || !rejectForm.comments.trim()"
                        class="flex-1 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span v-if="rejectForm.processing">Declining…</span>
                        <span v-else>Confirm Decline</span>
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>