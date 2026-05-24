<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { useDataFormatting } from '@/composables/useDataFormatting';
import { useMoney } from '@/composables/useMoney';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { AlertCircle, AlertTriangle, CheckCircle, Clock, Info, UploadCloud, XCircle } from 'lucide-vue-next';

const { formatCurrency, formatDate } = useDataFormatting();
const { toCents, fromCents } = useMoney();

// ── Types ─────────────────────────────────────────────────────────────────────

type PaymentTerm = {
    id: number;
    term_name: string;
    term_order: number;
    percentage: number;
    amount: number;
    balance: number;
    due_date: string | null;
    status: string;
    remarks: string | null;
};

type Assessment = {
    id: number;
    assessment_number: string;
    year_level: string;
    semester: string;
    school_year: string;
    total_assessment: number;
    status: string;
};

type PendingPayment = {
    id: number;
    reference: string;
    amount: number;
    status: string;           // ← 'awaiting_proof' | 'awaiting_approval'
    selected_term_id: number | null;
    term_name: string;
    created_at: string;
};

// ── Props ─────────────────────────────────────────────────────────────────────

const props = withDefaults(
    defineProps<{
        assessment: Assessment | null;
        paymentTerms: PaymentTerm[];
        pendingApprovalPayments: PendingPayment[];
        preselectedTermId?: number | null;
        availablePaymentMethods?: string[];
        student: {
            id: number;
            name: string;
            account_id: string;
            course: string;
            year_level: string;
        };
    }>(),
    {
        paymentTerms: () => [],
        pendingApprovalPayments: () => [],
        preselectedTermId: null,
        availablePaymentMethods: () => ['bank_transfer'],
    },
);

// ── Breadcrumbs ───────────────────────────────────────────────────────────────

const breadcrumbs = [
    { title: 'My Account', href: route('student.account') },
    { title: 'Make Payment' },
];

// ── Payment methods ───────────────────────────────────────────────────────────

const allPaymentMethods = [
    { value: 'gcash',         label: 'GCash' },
    { value: 'bank_transfer', label: 'Bank Transfer' },
    { value: 'credit_card',   label: 'Credit Card' },
    { value: 'debit_card',    label: 'Debit Card' },
];

const availablePaymentMethods = computed(() =>
    allPaymentMethods.filter((m) => props.availablePaymentMethods.includes(m.value)),
);

const singleMethod = computed(() =>
    availablePaymentMethods.value.length === 1 ? availablePaymentMethods.value[0] : null,
);

// ── Split pending payments into two distinct groups ───────────────────────────
//
//  awaiting_proof    → transaction created, proof NOT uploaded yet.
//                      These are incomplete. Student must either resume or cancel.
//                      Accounting CANNOT see these — no WorkflowApproval exists yet.
//
//  awaiting_approval → proof was uploaded, accounting is reviewing.
//                      Student must wait. Nothing left for them to do.
//
// Treating both the same in the UI is the source of the original confusion.

const awaitingProofPayments = computed(() =>
    props.pendingApprovalPayments.filter((p) => p.status === 'awaiting_proof'),
);

const awaitingApprovalPayments = computed(() =>
    props.pendingApprovalPayments.filter((p) => p.status === 'awaiting_approval'),
);

// ── Cancel an abandoned awaiting_proof payment ────────────────────────────────

const cancellingId = ref<number | null>(null);
const cancelError  = ref<string | null>(null);

const cancelAbandonedPayment = async (payment: PendingPayment) => {
    if (!confirm(
        `Cancel this payment?\n\n` +
        `Reference: ${payment.reference}\n` +
        `Amount: ₱${payment.amount.toFixed(2)}\n\n` +
        `This will allow you to submit a new payment for ${payment.term_name}.`
    )) return;

    cancellingId.value = payment.id;
    cancelError.value  = null;

    try {
        const page      = usePage();
        const csrfToken = (page.props.csrf_token as string) ?? '';

        const response = await fetch(route('payment.proof.cancel', payment.id), {
            method:      'DELETE',
            credentials: 'same-origin',
            headers: {
                'Content-Type':    'application/json',
                'Accept':          'application/json',
                'X-CSRF-TOKEN':    csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || `Server error: ${response.status}`);
        }

        // Reload the page so the cancelled payment disappears from the banner
        // and the duplicate guard is lifted.
        router.reload({ only: ['pendingApprovalPayments'] });

    } catch (err) {
        cancelError.value = err instanceof Error
            ? err.message
            : 'Failed to cancel payment. Please try again.';
    } finally {
        cancellingId.value = null;
    }
};

// ── Pending payments indexed by term ─────────────────────────────────────────

const pendingByTerm = computed<Record<number, number>>(() => {
    const map: Record<number, number> = {};
    props.pendingApprovalPayments.forEach((p) => {
        if (p.selected_term_id !== null) {
            map[p.selected_term_id] = (map[p.selected_term_id] || 0) + p.amount;
        }
    });
    return map;
});

// ── Available terms ───────────────────────────────────────────────────────────

const availableTerms = computed(() => {
    const unpaid = props.paymentTerms
        .filter((t) => t.balance > 0)
        .sort((a, b) => a.term_order - b.term_order);

    return unpaid.map((term, index) => {
        const pendingAmount = pendingByTerm.value[term.id] ?? 0;
        return {
            ...term,
            isSelectable: index === 0 && pendingAmount === 0,
            hasPending:   pendingAmount > 0,
            pendingAmount,
        };
    });
});

// ── Total outstanding balance ─────────────────────────────────────────────────

const totalOutstandingBalance = computed(() => {
    const cents = props.paymentTerms.reduce((sum, t) => sum + toCents(t.balance), 0);
    return fromCents(cents);
});

const effectiveBalance = computed(() => {
    const pendingCents = props.pendingApprovalPayments.reduce(
        (sum, p) => sum + toCents(p.amount),
        0,
    );
    const outstandingCents = toCents(totalOutstandingBalance.value);
    return fromCents(Math.max(0, outstandingCents - pendingCents));
});

// ── Form ──────────────────────────────────────────────────────────────────────

const form = useForm({
    amount:           0 as number,
    payment_method:   availablePaymentMethods.value[0]?.value ?? 'bank_transfer',
    paid_at:          new Date().toISOString().split('T')[0],
    selected_term_id: props.preselectedTermId ?? (null as number | null),
    description:      '' as string,
});

watch(() => form.selected_term_id, (termId) => {
    if (!termId) {
        form.amount = 0;
        return;
    }
    const term = availableTerms.value.find((t) => t.id === termId);
    if (term) {
        form.amount = parseFloat(term.balance.toFixed(2));
    }
});

if (props.preselectedTermId) {
    form.selected_term_id = props.preselectedTermId;
}

const selectedTerm = computed(() =>
    availableTerms.value.find((t) => t.id === form.selected_term_id) ?? null,
);

// ── Payment allocation preview ────────────────────────────────────────────────

type AllocationLine = {
    term_name:       string;
    balance_before:  number;
    applied:         number;
    balance_after:   number;
    fully_paid:      boolean;
    // ── Carry-forward fields (Scenario 1: partial payment on a term) ──────────
    // When a payment is less than the current term balance:
    //   processed       = true   → this term is closed; remaining balance carried forward
    //   carried_forward = amount → the peso amount moved to the next term
    //   carried_to_term = name   → which term receives the carry
    processed:       boolean;
    carried_forward: number;
    carried_to_term: string | null;
};

// ─────────────────────────────────────────────────────────────────────────────
// ALLOCATION PREVIEW — mirrors backend allocatePaymentAcrossTerms() exactly.
//
// STEP 1: Sequential allocation loop.
//   Apply payment to each term starting from selectedTerm, in term_order order.
//   Each term receives min(remaining, term.balance).
//
// STEP 2: Close-and-carry (ONE-TIME TERM PROCESSING RULE).
//   For each term that ended Step 1 with balance > 0 (partial):
//     - Close the term (set balance_after = 0, processed = true)
//     - Record what was carried and to where
//   This simulates what the PHP backend will do on the server.
//
// All arithmetic uses integer cents (via toCents / fromCents from useMoney).
// ─────────────────────────────────────────────────────────────────────────────
const allocationPreview = computed<AllocationLine[]>(() => {
    if (!selectedTerm.value || !form.amount || form.amount <= 0) return [];

    const amountCents = toCents(form.amount);
    if (fromCents(amountCents) > effectiveBalance.value) return [];

    const lines: AllocationLine[] = [];
    let remainingCents = amountCents;

    // All terms eligible for this payment: balance > 0 AND term_order >= selected.
    const terms = props.paymentTerms
        .filter((t) => t.balance > 0 && t.term_order >= selectedTerm.value!.term_order)
        .sort((a, b) => a.term_order - b.term_order);

    // ── STEP 1: Apply payment sequentially ───────────────────────────────────
    for (const term of terms) {
        if (remainingCents <= 0) break;
        const balanceBeforeCents = toCents(term.balance);
        const appliedCents       = Math.min(remainingCents, balanceBeforeCents);
        const balanceAfterCents  = balanceBeforeCents - appliedCents; // exact integer
        lines.push({
            term_name:       term.term_name,
            balance_before:  fromCents(balanceBeforeCents),
            applied:         fromCents(appliedCents),
            balance_after:   fromCents(balanceAfterCents),
            fully_paid:      balanceAfterCents === 0,
            processed:       false,   // populated in Step 2
            carried_forward: 0,
            carried_to_term: null,
        });
        remainingCents -= appliedCents;
    }

    // ── STEP 2: Close-and-carry ───────────────────────────────────────────────
    // For every line that ended Step 1 with balance remaining, simulate
    // the backend's close-and-carry behaviour:
    //   - Find the next term in the full paymentTerms list (beyond this one).
    //   - Record the carry-forward details on the allocation line.
    //   - Zero the current line's balance_after (it will be 0 on the server).
    //
    // NOTE: We do NOT mutate nextTerm's balance in this preview — that would
    // require a cascade that re-triggers the loop. The receipt and server will
    // handle the actual next-term balance update. The preview just annotates
    // the processed line to explain what will happen.
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        if (line.fully_paid || line.balance_after <= 0) continue;

        // This line has remaining balance — it will be processed (closed + carried).
        const carryoverCents = toCents(line.balance_after);

        // Find the next term in the full list (may be outside the current loop's scope).
        const currentTermObj = terms.find((t) => t.term_name === line.term_name);
        const nextTermObj = currentTermObj
            ? props.paymentTerms
                .filter((t) => t.balance > 0 && t.term_order > (currentTermObj.term_order ?? 0))
                .sort((a, b) => a.term_order - b.term_order)[0] ?? null
            : null;

        // Update the allocation line with Step 2 metadata.
        line.processed       = true;
        line.carried_forward = fromCents(carryoverCents);
        line.carried_to_term = nextTermObj?.term_name ?? null;
        line.balance_after   = 0;   // the server will zero this term
        line.fully_paid      = false; // processed ≠ paid
    }

    return lines;
});

// allocationCoversMultipleTerms: true when the payment touches more than one term
// OR when a single term is being processed with carry-forward.
const allocationCoversMultipleTerms = computed(() =>
    allocationPreview.value.length > 1 ||
    (allocationPreview.value.length === 1 && allocationPreview.value[0].processed)
);

// ── Bank transfer specific state ──────────────────────────────────────────────

const isBankTransfer = computed(() => form.payment_method === 'bank_transfer');
const bankReferenceNumber = ref('');
const bankDetails = ref<{ account_name: string; account_number: string; bank_name: string } | null>(null);
const bankDetailsLoading = ref(false);

watch(isBankTransfer, async (val) => {
    if (!val || bankDetails.value) return;
    bankDetailsLoading.value = true;
    try {
        const res = await fetch(route('payment.bank-details'), { credentials: 'same-origin' });
        if (res.ok) {
            const data = await res.json();
            bankDetails.value = data.bank_details;
        }
    } catch {
        // Non-fatal — bank details panel has a fallback message
    } finally {
        bankDetailsLoading.value = false;
    }
}, { immediate: true });

// ── Validation ────────────────────────────────────────────────────────────────

const safeAmount = computed(() =>
    form.amount ? parseFloat(Number(form.amount).toFixed(2)) : 0
);

const validationError = computed<string | null>(() => {
    if (!props.assessment)
        return 'No active assessment found. Please contact accounting.';
    if (totalOutstandingBalance.value <= 0)
        return 'Your account has no outstanding balance.';
    if (effectiveBalance.value <= 0)
        return 'Your full outstanding balance is awaiting approval.';
    if (!form.selected_term_id)
        return 'Please select a payment term.';
    if (selectedTerm.value?.hasPending)
        return `A payment for ${selectedTerm.value.term_name} is already awaiting approval.`;
    if (!form.amount || safeAmount.value <= 0)
        return 'Please enter a valid payment amount.';
    if (safeAmount.value > effectiveBalance.value)
        return `Amount (${formatCurrency(safeAmount.value)}) exceeds your total outstanding balance (${formatCurrency(effectiveBalance.value)}).`;
    if (isBankTransfer.value && !bankReferenceNumber.value.trim())
        return 'Please enter your bank transfer reference number.';
    return null;
});

// ── State ─────────────────────────────────────────────────────────────────────

const isCheckingOut = ref(false);
const checkoutError = ref<string | null>(null);
const submitSuccess = ref(false);

const canSubmit = computed(() =>
    !validationError.value && !form.processing && !isCheckingOut.value,
);

// ── Submission ────────────────────────────────────────────────────────────────

const submit = () => {
    if (!canSubmit.value) return;
    checkoutError.value = null;
    isBankTransfer.value ? submitBankTransfer() : submitCheckout();
};

const submitCheckout = async () => {
    isCheckingOut.value = true;
    checkoutError.value = null;

    try {
        const page      = usePage();
        const csrfToken = (page.props.csrf_token as string) ?? '';
        const normalizedAmount = parseFloat(safeAmount.value.toFixed(2));

        const response = await fetch(route('payment.checkout'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                amount:           normalizedAmount,
                description:      `${selectedTerm.value?.term_name || 'Payment'} - ${form.description || ''}`.trim(),
                selected_term_id: form.selected_term_id,
                payment_method:   form.payment_method,
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || data.message || `Server error: ${response.status}`);
        }

        if (!data.checkout_url) {
            throw new Error('No checkout URL returned. Please try again or contact support.');
        }

        window.location.href = data.checkout_url;

    } catch (error) {
        console.error('Checkout error:', error);
        checkoutError.value = error instanceof Error
            ? error.message
            : 'An unexpected error occurred. Please try again.';
    } finally {
        isCheckingOut.value = false;
    }
};

const submitBankTransfer = async () => {
    isCheckingOut.value = true;
    checkoutError.value = null;

    try {
        const page      = usePage();
        const csrfToken = (page.props.csrf_token as string) ?? '';
        const normalizedAmount = parseFloat(safeAmount.value.toFixed(2));

        const response = await fetch(route('payment.bank-transfer'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                amount:           normalizedAmount,
                reference_number: bankReferenceNumber.value.trim(),
                selected_term_id: form.selected_term_id,
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            throw new Error(data.error || data.message || `Server error: ${response.status}`);
        }

        if (data.transaction_id) {
            router.get(route('payment.proof.show', data.transaction_id));
        } else {
            submitSuccess.value = true;
            setTimeout(() => {
                router.get(route('student.account'), { tab: 'history' });
            }, 2000);
        }
    } catch (error) {
        console.error('Bank transfer error:', error);
        checkoutError.value = error instanceof Error
            ? error.message
            : 'An unexpected error occurred. Please try again.';
    } finally {
        isCheckingOut.value = false;
    }
};

// ── Helpers ───────────────────────────────────────────────────────────────────

const isOverdue = (dueDate: string | null): boolean => {
    if (!dueDate) return false;
    const due   = new Date(dueDate);
    const today = new Date();
    due.setHours(0, 0, 0, 0);
    today.setHours(0, 0, 0, 0);
    return due < today;
};

const dueDateUrgency = (dueDate: string | null): 'red' | 'amber' | 'green' | null => {
    if (!dueDate) return null;
    const diffDays = Math.ceil((new Date(dueDate).getTime() - Date.now()) / 86_400_000);
    if (diffDays < 0)   return 'red';
    if (diffDays <= 7)  return 'red';
    if (diffDays <= 14) return 'amber';
    return 'green';
};
</script>

<template>
    <AppLayout>
        <Head title="Make Payment" />

        <div class="w-full p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Make a Payment</h1>
                <p class="text-sm text-muted-foreground mt-1">
                    Submit your payment for accounting verification.
                </p>
            </div>

            <!-- Success State -->
            <div
                v-if="submitSuccess"
                class="mb-6 rounded-xl border border-green-300 bg-green-50 p-5 flex items-center gap-3"
            >
                <CheckCircle :size="22" class="text-green-600 flex-shrink-0" />
                <div>
                    <p class="font-semibold text-green-900">Payment submitted successfully!</p>
                    <p class="text-sm text-green-700">
                        Your payment is awaiting accounting verification. Redirecting…
                    </p>
                </div>
            </div>

            <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-3">

                <!-- ── Left: Payment Form ─────────────────────────────────── -->
                <div class="lg:col-span-2 space-y-6">

                    <!-- No assessment guard -->
                    <div
                        v-if="!assessment"
                        class="rounded-xl border border-amber-300 bg-amber-50 p-5"
                    >
                        <p class="font-semibold text-amber-900">No active assessment found</p>
                        <p class="text-sm text-amber-700 mt-1">
                            Please contact the accounting office to create your assessment first.
                        </p>
                    </div>

                    <!-- Fully paid guard -->
                    <div
                        v-else-if="totalOutstandingBalance <= 0"
                        class="rounded-xl border border-green-300 bg-green-50 p-5 flex items-center gap-3"
                    >
                        <CheckCircle :size="22" class="text-green-600" />
                        <div>
                            <p class="font-semibold text-green-900">Account fully paid!</p>
                            <p class="text-sm text-green-700">You have no outstanding balance.</p>
                        </div>
                    </div>

                    <!-- ── ACTION REQUIRED: Proof Not Uploaded Yet ───────── -->
                    <!--
                        These transactions exist in the DB but have NO WorkflowApproval record.
                        Accounting literally cannot see them. The student must either:
                          (a) Resume → go to the proof upload page, OR
                          (b) Cancel → free the term so they can resubmit.
                    -->
                    <div
                        v-if="awaitingProofPayments.length > 0"
                        class="rounded-xl border border-orange-400 bg-orange-50 p-4"
                    >
                        <div class="flex items-start gap-3">
                            <AlertTriangle :size="20" class="mt-0.5 flex-shrink-0 text-orange-500" />
                            <div class="flex-1 space-y-3">
                                <div>
                                    <p class="font-semibold text-orange-900">
                                        ⚠️ Action Required — Proof Not Uploaded
                                    </p>
                                    <p class="text-sm text-orange-700 mt-0.5">
                                        You started a payment but never uploaded your receipt.
                                        Accounting <strong>cannot see this payment</strong> until you upload proof.
                                        Please complete or cancel each submission below.
                                    </p>
                                </div>

                                <div class="space-y-2">
                                    <div
                                        v-for="payment in awaitingProofPayments"
                                        :key="payment.id"
                                        class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-orange-100 px-3 py-2 text-sm"
                                    >
                                        <div>
                                            <span class="font-semibold text-orange-900">
                                                {{ payment.term_name }}
                                            </span>
                                            <span class="ml-2 font-mono text-xs text-orange-600">
                                                {{ payment.reference }}
                                            </span>
                                            <span class="ml-2 font-semibold text-orange-800">
                                                ₱{{ payment.amount.toFixed(2) }}
                                            </span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <!-- Resume: go to proof upload -->
                                            <a
                                                :href="route('payment.proof.show', payment.id)"
                                                class="inline-flex items-center gap-1 rounded-md bg-indigo-600 px-3 py-1 text-xs font-semibold text-white hover:bg-indigo-700 transition-colors"
                                            >
                                                <UploadCloud :size="13" />
                                                Upload Proof
                                            </a>
                                            <!-- Cancel: free the term -->
                                            <button
                                                type="button"
                                                @click="cancelAbandonedPayment(payment)"
                                                :disabled="cancellingId === payment.id"
                                                class="inline-flex items-center gap-1 rounded-md border border-red-300 bg-white px-3 py-1 text-xs font-semibold text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50 transition-colors"
                                            >
                                                <XCircle :size="13" />
                                                {{ cancellingId === payment.id ? 'Cancelling…' : 'Cancel' }}
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Cancel error -->
                                <p v-if="cancelError" class="text-xs text-red-600 font-medium">
                                    {{ cancelError }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- ── AWAITING ACCOUNTING REVIEW ─────────────────────── -->
                    <!--
                        Proof was already uploaded. WorkflowApproval exists.
                        Accounting CAN see these. Student just has to wait.
                    -->
                    <div
                        v-if="awaitingApprovalPayments.length > 0"
                        class="rounded-xl border border-amber-300 bg-amber-50 p-4"
                    >
                        <div class="flex items-start gap-3">
                            <AlertCircle :size="20" class="mt-0.5 flex-shrink-0 text-amber-600" />
                            <div class="flex-1">
                                <p class="mb-2 font-semibold text-amber-900">
                                    ⏳ Pending Payment(s) Awaiting Accounting Review
                                </p>
                                <div class="space-y-1 text-sm text-amber-800">
                                    <div
                                        v-for="payment in awaitingApprovalPayments"
                                        :key="payment.id"
                                        class="flex justify-between"
                                    >
                                        <span>{{ payment.term_name }} ({{ payment.reference }})</span>
                                        <span class="font-semibold">{{ formatCurrency(payment.amount) }}</span>
                                    </div>
                                </div>
                                <p class="mt-2 text-xs italic text-amber-700">
                                    Proof has been submitted. Wait for accounting to verify before
                                    submitting another payment for the same term.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Form -->
                    <div
                        v-if="assessment && totalOutstandingBalance > 0"
                        class="ccdi-card p-6 space-y-5"
                    >
                        <h2 class="text-base font-semibold text-gray-900 border-b pb-3">
                            Payment Details
                        </h2>

                        <!-- Term selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Starting Payment Term <span class="text-red-500">*</span>
                            </label>
                            <select
                                v-model.number="form.selected_term_id"
                                class="w-full rounded-lg border px-4 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-100"
                                :disabled="effectiveBalance <= 0"
                            >
                                <option :value="null">— Select a term —</option>
                                <option
                                    v-for="term in availableTerms"
                                    :key="term.id"
                                    :value="term.id"
                                    :disabled="!term.isSelectable"
                                >
                                    {{ term.term_name }}
                                    {{ term.hasPending
                                        ? ` (⏳ Pending ₱${formatCurrency(term.pendingAmount)})`
                                        : ` — Balance: ₱${formatCurrency(term.balance)}`
                                    }}
                                    {{ !term.isSelectable && !term.hasPending ? ' (Pay previous term first)' : '' }}
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">
                                Terms must be paid sequentially. Only the first unpaid term is available.
                            </p>
                            <p v-if="form.errors.selected_term_id" class="mt-1 text-sm text-red-500">
                                {{ form.errors.selected_term_id }}
                            </p>
                        </div>

                        <!-- Amount -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Amount (₱) <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model.number="form.amount"
                                type="number"
                                step="0.01"
                                min="1"
                                placeholder="0.00"
                                :disabled="effectiveBalance <= 0 || !form.selected_term_id"
                                class="w-full rounded-lg border px-4 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:bg-gray-100"
                            />

                            <!-- Amount guidance row -->
                            <div class="mt-1 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-xs">
                                <span class="text-gray-500">
                                    Term balance:
                                    <span class="font-semibold text-gray-700">
                                        {{ selectedTerm ? formatCurrency(selectedTerm.balance) : '—' }}
                                    </span>
                                </span>
                                <span class="text-gray-500">
                                    Max (total outstanding):
                                    <span class="font-semibold text-indigo-700">
                                        {{ formatCurrency(effectiveBalance) }}
                                    </span>
                                    <span
                                        v-if="pendingApprovalPayments.length > 0"
                                        class="text-amber-600 ml-1"
                                    >
                                        (excludes {{ formatCurrency(totalOutstandingBalance - effectiveBalance) }} awaiting approval)
                                    </span>
                                </span>
                            </div>
                            <p v-if="form.errors.amount" class="mt-1 text-sm text-red-500">
                                {{ form.errors.amount }}
                            </p>
                        </div>

                        <!-- ── Payment Allocation Preview ─────────────────────────────── -->
                        <!--
                            Shows whenever the payment touches one or more terms.
                            The 'processed' flag on a line means the one-time term
                            processing rule fired: the term is closed and its remaining
                            balance is carried forward to the next term.
                        -->
                        <Transition name="fade">
                            <div
                                v-if="allocationPreview.length > 0"
                                class="rounded-lg border border-indigo-200 bg-indigo-50 p-4"
                            >
                                <div class="flex items-center gap-2 mb-3">
                                    <Info :size="15" class="text-indigo-600 flex-shrink-0" />
                                    <p class="text-sm font-semibold text-indigo-900">
                                        How your payment will be applied
                                    </p>
                                </div>

                                <div class="space-y-2">
                                    <div
                                        v-for="line in allocationPreview"
                                        :key="line.term_name"
                                        class="rounded-md px-3 py-2 text-sm"
                                        :class="{
                                            'bg-green-100':  line.fully_paid,
                                            'bg-blue-100':   line.processed,
                                            'bg-amber-50 border border-amber-100': !line.fully_paid && !line.processed,
                                        }"
                                    >
                                        <!-- Term row: name + applied amount -->
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-2">
                                                <!-- Status dot -->
                                                <span
                                                    :class="[
                                                        'inline-block h-2 w-2 rounded-full flex-shrink-0',
                                                        line.fully_paid  ? 'bg-green-500' :
                                                        line.processed   ? 'bg-blue-500'  : 'bg-amber-400',
                                                    ]"
                                                />
                                                <span class="font-medium text-gray-800">{{ line.term_name }}</span>
                                                <!-- Status badge -->
                                                <span
                                                    v-if="line.fully_paid"
                                                    class="rounded-full bg-green-200 px-1.5 py-0.5 text-xs font-semibold text-green-800"
                                                >
                                                    Paid
                                                </span>
                                                <span
                                                    v-else-if="line.processed"
                                                    class="rounded-full bg-blue-200 px-1.5 py-0.5 text-xs font-semibold text-blue-800"
                                                >
                                                    Carried Forward
                                                </span>
                                            </div>
                                            <span class="font-semibold text-indigo-700">
                                                −{{ formatCurrency(line.applied) }}
                                            </span>
                                        </div>

                                        <!-- Carry-forward note (one-time processing rule) -->
                                        <div
                                            v-if="line.processed && line.carried_forward > 0"
                                            class="mt-1 flex items-start gap-1.5 text-xs text-blue-700"
                                        >
                                            <span class="flex-shrink-0 mt-0.5">↪</span>
                                            <span>
                                                {{ formatCurrency(line.carried_forward) }} remaining balance
                                                will carry forward to
                                                <strong>{{ line.carried_to_term ?? 'the next term' }}</strong>.
                                                This term is now closed.
                                            </span>
                                        </div>

                                        <!-- Simple remaining note for non-processed partial (last active term) -->
                                        <div
                                            v-else-if="!line.fully_paid && !line.processed && line.balance_after > 0"
                                            class="mt-1 text-xs text-amber-700"
                                        >
                                            {{ formatCurrency(line.balance_after) }} remaining after this payment
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </Transition>

                        <!-- Payment Method -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Payment Method
                            </label>

                            <!-- Single method: static badge, no dropdown -->
                            <div
                                v-if="singleMethod"
                                class="flex items-center gap-2 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm font-medium text-indigo-800"
                            >
                                <CheckCircle :size="15" class="text-indigo-500 flex-shrink-0" />
                                {{ singleMethod.label }}
                            </div>

                            <!-- Multiple methods: dropdown -->
                            <select
                                v-else
                                v-model="form.payment_method"
                                class="w-full rounded-lg border px-4 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            >
                                <option
                                    v-for="method in availablePaymentMethods"
                                    :key="method.value"
                                    :value="method.value"
                                >
                                    {{ method.label }}
                                </option>
                            </select>

                            <p v-if="form.errors.payment_method" class="mt-1 text-sm text-red-500">
                                {{ form.errors.payment_method }}
                            </p>
                        </div>

                        <!-- Bank Transfer: Bank Details + Reference Number -->
                        <div v-if="isBankTransfer" class="space-y-4">
                            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4">
                                <p class="text-sm font-semibold text-blue-900 mb-2">
                                    Transfer to this account:
                                </p>
                                <div v-if="bankDetailsLoading" class="text-sm text-blue-600">
                                    Loading bank details…
                                </div>
                                <div v-else-if="bankDetails" class="space-y-1 text-sm text-blue-800">
                                    <div class="flex justify-between">
                                        <span class="text-blue-600">Bank</span>
                                        <span class="font-semibold">{{ bankDetails.bank_name }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-blue-600">Account Name</span>
                                        <span class="font-semibold">{{ bankDetails.account_name }}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-blue-600">Account Number</span>
                                        <span class="font-mono font-semibold">{{ bankDetails.account_number }}</span>
                                    </div>
                                </div>
                                <div v-else class="space-y-1 text-sm text-blue-800">
                                    <p>Transfer your payment to the school's official bank account.</p>
                                    <p>Contact the accounting office for bank details.</p>
                                </div>
                                <p class="mt-3 text-xs text-blue-600">
                                    After transferring, enter your reference number below and upload proof of payment.
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Bank Transfer Reference Number <span class="text-red-500">*</span>
                                </label>
                                <input
                                    v-model="bankReferenceNumber"
                                    type="text"
                                    placeholder="e.g. 202504191234567"
                                    maxlength="100"
                                    class="w-full rounded-lg border px-4 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                />
                                <p class="mt-1 text-xs text-gray-500">
                                    Found on your bank receipt or transfer confirmation.
                                </p>
                            </div>
                        </div>

                        <!-- Payment Date -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Payment Date <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.paid_at"
                                type="date"
                                :max="new Date().toISOString().split('T')[0]"
                                class="w-full rounded-lg border px-4 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                            <p v-if="form.errors.paid_at" class="mt-1 text-sm text-red-500">
                                {{ form.errors.paid_at }}
                            </p>
                        </div>

                        <!-- Notes -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Notes <span class="text-gray-400 text-xs">(optional)</span>
                            </label>
                            <input
                                v-model="form.description"
                                type="text"
                                placeholder="e.g. Transferred via BDO online banking"
                                maxlength="255"
                                class="w-full rounded-lg border px-4 py-2 shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            />
                        </div>

                        <!-- Validation error -->
                        <div
                            v-if="validationError"
                            class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700"
                        >
                            {{ validationError }}
                        </div>

                        <!-- Checkout error -->
                        <div
                            v-if="checkoutError"
                            class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700 flex items-start gap-2"
                        >
                            <AlertCircle :size="16" class="mt-0.5 flex-shrink-0" />
                            <span>{{ checkoutError }}</span>
                        </div>

                        <!-- Submit -->
                        <button
                            type="button"
                            @click="submit"
                            :disabled="!canSubmit"
                            class="w-full rounded-xl bg-indigo-600 px-5 py-3 font-semibold text-white shadow transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <span v-if="form.processing || isCheckingOut">
                                <span v-if="isBankTransfer && isCheckingOut">Submitting bank transfer…</span>
                                <span v-else-if="isCheckingOut">Redirecting to payment…</span>
                                <span v-else>Submitting…</span>
                            </span>
                            <span v-else>
                                <span v-if="isBankTransfer">Submit Bank Transfer & Upload Proof</span>
                                <span v-else>Pay {{ formatCurrency(safeAmount) }} via {{ allPaymentMethods.find(m => m.value === form.payment_method)?.label }}</span>
                            </span>
                        </button>

                        <p class="text-center text-xs text-gray-400">
                            You will be asked to upload proof of payment after submitting.
                        </p>
                    </div>
                </div>

                <!-- ── Right: Summary Panel ──────────────────────────────── -->
                <div class="space-y-4">

                    <!-- Assessment Info -->
                    <div v-if="assessment" class="ccdi-card p-5">
                        <h3 class="text-xs font-semibold uppercase tracking-widest text-muted-foreground mb-3">
                            Assessment
                        </h3>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Number</span>
                                <span class="font-mono font-medium">{{ assessment.assessment_number }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Term</span>
                                <span class="font-medium">{{ assessment.semester }} · {{ assessment.school_year }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Year Level</span>
                                <span class="font-medium">{{ assessment.year_level }}</span>
                            </div>
                            <div class="border-t pt-2 flex justify-between">
                                <span class="text-gray-500">Total Assessment</span>
                                <span class="font-bold text-gray-900">{{ formatCurrency(assessment.total_assessment) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Outstanding</span>
                                <span class="font-bold text-red-600">{{ formatCurrency(totalOutstandingBalance) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Terms Summary -->
                    <div v-if="paymentTerms.length" class="ccdi-card p-5">
                        <h3 class="text-xs font-semibold uppercase tracking-widest text-muted-foreground mb-3">
                            Payment Schedule
                        </h3>
                        <div class="space-y-2">
                            <div
                                v-for="term in paymentTerms"
                                :key="term.id"
                                class="flex items-center justify-between rounded-lg px-3 py-2 text-sm"
                                :class="term.balance <= 0
                                    ? 'bg-green-50'
                                    : term.id === availableTerms[0]?.id
                                        ? 'bg-indigo-50 ring-1 ring-indigo-200'
                                        : 'bg-gray-50'"
                            >
                                <div class="flex items-center gap-2">
                                    <CheckCircle
                                        v-if="term.balance <= 0"
                                        :size="14"
                                        class="text-green-500 flex-shrink-0"
                                    />
                                    <Clock
                                        v-else
                                        :size="14"
                                        class="text-gray-400 flex-shrink-0"
                                    />
                                    <span :class="term.balance <= 0 ? 'text-green-700' : 'text-gray-700'">
                                        {{ term.term_name }}
                                    </span>
                                </div>
                                <div class="text-right">
                                    <p
                                        class="font-semibold text-xs"
                                        :class="term.balance <= 0 ? 'text-green-600' : 'text-gray-800'"
                                    >
                                        {{ term.balance <= 0 ? '✓ Paid' : formatCurrency(term.balance) }}
                                    </p>
                                    <p
                                        v-if="term.due_date && term.balance > 0"
                                        class="text-xs"
                                        :class="isOverdue(term.due_date) ? 'text-red-500' : 'text-gray-400'"
                                    >
                                        {{ formatDate(term.due_date) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Student Info -->
                    <div class="ccdi-card p-5">
                        <h3 class="text-xs font-semibold uppercase tracking-widest text-muted-foreground mb-3">
                            Student
                        </h3>
                        <div class="space-y-1 text-sm">
                            <p class="font-semibold text-gray-900">{{ student.name }}</p>
                            <p class="text-gray-500 font-mono">{{ student.account_id }}</p>
                            <p class="text-gray-500">{{ student.course }}</p>
                            <p class="text-gray-500">{{ student.year_level }}</p>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}
</style>