<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { BookOpen, ChevronDown, Receipt } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { useDataFormatting } from '@/composables/useDataFormatting';

const { formatCurrency } = useDataFormatting();

// ─── Types ────────────────────────────────────────────────────────────────────

interface Transaction {
    id: number;
    reference: string;
    or_number?: string | null;
    user?: {
        id: number;
        name: string;
        account_id: string;
        email: string;
    };
    kind: 'charge' | 'payment';
    type: string;
    year: string | null;
    semester: string | null;
    amount: number;
    status: string;
    payment_channel?: string | null;
    paid_at?: string;
    created_at: string;
    meta?: Record<string, any>;
}

/**
 * Immutable per-subject billing snapshot from assessment_subjects.
 * Populated by the backend — never reconstructed on the frontend.
 */
interface EnrolledSubject {
    subject_id: number | null;
    code: string;
    name: string;
    lec_units: number;
    lab_units: number;
    total_units: number;
    is_nstp: boolean;
    is_pathfit: boolean;
    is_billable: boolean;
    nstp_billing_units: number;
    tuition_fee: number;
    lab_fee: number;
    total_fee: number;
}

interface Assessment {
    id: number;
    school_year: string;
    semester: string;
    year_level: string;
    course: string | null;
    total_assessment: number;
    lec_units: number;
    lab_units: number;
    tuition_fee: number;
    lab_fee: number;
    misc_fee: number;
    is_taking_nstp: boolean;
    status: string;
    // ── Real subject snapshot from assessment_subjects ─────────────────────
    enrolled_subjects: EnrolledSubject[];
}

interface TermSummary {
    total_assessment: number;
    total_paid: number;
    current_balance: number;
}

interface Props {
    auth: {
        user: {
            id: number;
            name: string;
            role: string;
        };
    };
    transactionsByTerm: Record<string, Transaction[]>;
    account: {
        balance: number;
    } | null;
    currentTerm: string;
    allAssessments: Assessment[];
    backUrl?: string;
}

// ─── Props ────────────────────────────────────────────────────────────────────

const props = defineProps<Props>();

// ─── State ────────────────────────────────────────────────────────────────────

const breadcrumbs = [
    { title: 'Dashboard', href: props.backUrl ?? route('dashboard') },
    { title: 'Transaction History' },
];

const search              = ref('');
const expanded            = ref<Record<string, boolean>>({});
const showPastSemesters   = ref(false);
const selectedTransaction = ref<Transaction | null>(null);
const showDetailsDialog   = ref(false);

// ─── Role helpers ─────────────────────────────────────────────────────────────

const isStaff = computed(() => ['admin', 'accounting', 'super_admin'].includes(props.auth.user.role));

// ─── Auto-expand current term ─────────────────────────────────────────────────

const hasCurrentTermTransactions = !!(
    props.currentTerm && props.transactionsByTerm?.[props.currentTerm]
);
const hasCurrentTermAssessment = !!(
    props.currentTerm &&
    props.allAssessments?.some((a) => `${a.school_year} ${a.semester}` === props.currentTerm)
);

if (hasCurrentTermTransactions || hasCurrentTermAssessment) {
    expanded.value[props.currentTerm] = true;
}

// ─── Counts ───────────────────────────────────────────────────────────────────

const totalTermsCount = computed(() => Object.keys(props.transactionsByTerm ?? {}).length);

const toggle = (key: string) => {
    expanded.value[key] = !expanded.value[key];
};

// ─── Summary per term ─────────────────────────────────────────────────────────

const assessmentByTermKey = computed(() => {
    const map: Record<string, number> = {};
    for (const a of props.allAssessments) {
        const key = `${a.school_year} ${a.semester}`;
        map[key] = a.total_assessment ?? 0;
    }
    return map;
});

const calculateTermSummary = (termKey: string, transactions: Transaction[]): TermSummary => {
    const totalAssessment = assessmentByTermKey.value[termKey] ?? 0;
    const payments = transactions
        .filter((t) => t.kind === 'payment' && t.status === 'paid')
        .reduce((s, t) => s + parseFloat(String(t.amount || 0)), 0);
    return {
        total_assessment: totalAssessment,
        total_paid: payments,
        current_balance: totalAssessment - payments,
    };
};

// ─── Assessment for the current term ─────────────────────────────────────────

const currentTermAssessment = computed((): Assessment | null => {
    if (!props.allAssessments?.length || !props.currentTerm) return null;
    return (
        props.allAssessments.find(
            (a) => `${a.school_year} ${a.semester}` === props.currentTerm,
        ) ?? null
    );
});

// ─── Subject panel — real data from enrolled_subjects snapshot ────────────────
//
// Old implementation (removed):
//   buildSubjectPanel() reconstructed subjects from fee_breakdown rows filtered
//   by category === 'Tuition'. That produced broken output because:
//     1. fee_breakdown had only 3 rows (aggregate), not per-subject rows
//     2. subject_id, code, lec/lab units were unavailable in the aggregate
//     3. NSTP appeared as a tuition lump, not as a named subject
//     4. Enrollment cross-reference via student_enrollments was unreliable
//
// New implementation:
//   enrolled_subjects comes directly from assessment_subjects (backend snapshot).
//   All fields are real, historical, and require zero frontend reconstruction.

interface SubjectPanel {
    assessmentId: number;
    termKey: string;
    subjects: EnrolledSubject[];
    totalLecUnits: number;
    totalLabUnits: number;
    // Financial and billing fields below are computed but intentionally not
    // rendered in the student-facing template. They remain available here in
    // case admin/accounting views ever need them without a controller change.
    totalBillableUnits: number;
    totalTuition: number;
    totalLab: number;
    totalFee: number;
    subjectCount: number;
    hasLab: boolean;
    hasNstp: boolean;
}

function buildSubjectPanel(assessment: Assessment, termKey: string): SubjectPanel | null {
    const subjects = assessment.enrolled_subjects ?? [];
    if (subjects.length === 0) return null;

    const totalLecUnits = subjects.reduce((s, sub) => s + sub.lec_units, 0);
    const totalLabUnits = subjects.reduce((s, sub) => s + sub.lab_units, 0);
    const totalBillableUnits = subjects.reduce((s, sub) => {
        if (sub.is_nstp) return s + sub.nstp_billing_units;
        if (!sub.is_billable) return s;
        return s + sub.lec_units + sub.lab_units;
    }, 0);
    const totalTuition = subjects.reduce((s, sub) => s + sub.tuition_fee, 0);
    const totalLab     = subjects.reduce((s, sub) => s + sub.lab_fee, 0);
    const totalFee     = subjects.reduce((s, sub) => s + sub.total_fee, 0);

    return {
        assessmentId:       assessment.id,
        termKey,
        subjects,
        totalLecUnits,
        totalLabUnits,
        totalBillableUnits,
        totalTuition,
        totalLab,
        totalFee,
        subjectCount:       subjects.length,
        hasLab:             subjects.some((s) => s.lab_units > 0),
        hasNstp:            subjects.some((s) => s.is_nstp),
    };
}

const expandedSubjectTerms = ref<Set<number>>(new Set());

const toggleSubjectTerm = (assessmentId: number) => {
    if (expandedSubjectTerms.value.has(assessmentId)) {
        expandedSubjectTerms.value.delete(assessmentId);
    } else {
        expandedSubjectTerms.value.add(assessmentId);
    }
};

const subjectPanelsByTerm = computed(() => {
    const result: Record<string, SubjectPanel | null> = {};

    for (const termKey of Object.keys(filteredTransactionsByTermWithAssessments.value)) {
        const parts      = termKey.split(' ');
        const schoolYear = parts[0];
        const semester   = parts.slice(1).join(' ');

        const matchingAssessment = props.allAssessments.find(
            (a) => a.school_year === schoolYear && a.semester === semester,
        );

        if (!matchingAssessment) {
            result[termKey] = null;
            continue;
        }

        result[termKey] = buildSubjectPanel(matchingAssessment, termKey);
    }

    return result;
});

// ─── Filtering ────────────────────────────────────────────────────────────────

const semesterSortOrder: Record<string, number> = {
    '1st': 1,
    '2nd': 2,
    'Summer': 3,
};

const latestTermKey = computed((): string | null => {
    const keys = Object.keys(props.transactionsByTerm ?? {});
    if (keys.length === 0) return null;

    return keys
        .slice()
        .sort((a, b) => {
            const [syA, semA = ''] = a.split(' ');
            const [syB, semB = ''] = b.split(' ');
            const yearA = parseInt(syA?.split('-')[0] ?? '0', 10);
            const yearB = parseInt(syB?.split('-')[0] ?? '0', 10);
            if (yearA !== yearB) return yearA - yearB;
            return (semesterSortOrder[semA] ?? 0) - (semesterSortOrder[semB] ?? 0);
        })
        .at(-1) ?? null;
});

const filteredTransactionsByTerm = computed(() => {
    if (!props.transactionsByTerm) return {};

    let terms = props.transactionsByTerm;

    if (!showPastSemesters.value && latestTermKey.value && terms[latestTermKey.value]) {
        terms = { [latestTermKey.value]: terms[latestTermKey.value] };
    }

    if (!search.value) return terms;

    const q = search.value.toLowerCase();
    const result: Record<string, Transaction[]> = {};

    Object.entries(terms).forEach(([term, txns]) => {
        const matched = txns.filter(
            (t) =>
                t.reference?.toLowerCase().includes(q) ||
                t.or_number?.toLowerCase().includes(q) ||
                t.type?.toLowerCase().includes(q) ||
                t.user?.name?.toLowerCase().includes(q) ||
                t.user?.account_id?.toLowerCase().includes(q),
        );
        if (matched.length) result[term] = matched;
    });

    return result;
});

const filteredTransactionsByTermWithAssessments = computed((): Record<string, Transaction[]> => {
    const terms = filteredTransactionsByTerm.value;

    if (
        !isStaff.value &&
        currentTermAssessment.value &&
        !(props.currentTerm in terms)
    ) {
        return {
            [props.currentTerm]: [] as Transaction[],
            ...terms,
        };
    }

    return terms;
});

// ─── Balance ──────────────────────────────────────────────────────────────────

const accountBalance = computed(() => parseFloat(String(props.account?.balance ?? 0)));
const hasCredit      = computed(() => accountBalance.value < 0);
const displayBalance = computed(() => Math.abs(accountBalance.value));
const canMakePayment = computed(() => accountBalance.value > 0);

// ─── Helpers ──────────────────────────────────────────────────────────────────

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });

const toYearRange = (year: string | number | null | undefined): string => {
    if (!year) return '—';
    const y = parseInt(String(year), 10);
    return isNaN(y) ? String(year) : `${y}-${y + 1}`;
};

// ─── Receipt helpers ──────────────────────────────────────────────────────────

const canDownloadTermSummary = (transactions: Transaction[]): boolean =>
    transactions.some((t) => t.kind === 'payment' && t.status === 'paid');

const downloadReceipt = (transactionId: number) => {
    const url = route('transactions.receipt', { transaction: transactionId });
    window.open(url, '_blank');
};

const downloadTermSummary = (termKey: string) => {
    const url = route('transactions.download') + '?term=' + encodeURIComponent(termKey);
    window.open(url, '_blank');
};

const viewTransaction = (transaction: Transaction) => {
    selectedTransaction.value = transaction;
    showDetailsDialog.value   = true;
};

const closeDetailsDialog = () => {
    showDetailsDialog.value   = false;
    selectedTransaction.value = null;
};

const payNow = () => {
    if (!canMakePayment.value) return;
    router.visit(route('payment.create'));
};

const formatPaymentMethod = (m: string): string => {
    const labels: Record<string, string> = {
        cash:          'Cash',
        gcash:         'GCash',
        bank_transfer: 'Bank Transfer',
        credit_card:   'Credit Card',
        debit_card:    'Debit Card',
        paymaya:       'Maya',
        maya:          'Maya',
        paymongo:      'Online Payment',
    };
    return labels[m?.toLowerCase()] ?? m ?? '—';
};

const displayRefNumber = (t: Transaction): string => {
    const channel = (t.payment_channel ?? '').toLowerCase();
    return channel === 'cash' ? (t.or_number ?? '—') : (t.reference ?? '—');
};

const displayRefLabel = (t: Transaction): string => {
    const channel = (t.payment_channel ?? '').toLowerCase();
    return channel === 'cash' ? 'OR No.' : 'Ref No.';
};
</script>

<template>
    <Head title="Transaction History" />

    <AppLayout>
        <div class="w-full space-y-6 p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- ── Header ── -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold">Transaction History</h1>
                    <p class="text-gray-500">View all your financial transactions by term</p>
                </div>
                <Button v-if="totalTermsCount > 1" variant="outline" @click="showPastSemesters = !showPastSemesters">
                    {{ showPastSemesters ? 'Hide Past Semesters' : 'Show Past Semesters' }}
                </Button>
            </div>

            <!-- ── Balance Card (students only) ── -->
            <div v-if="!isStaff && account" class="rounded-xl border p-6 shadow-sm" :class="hasCredit ? 'bg-green-50' : 'bg-blue-50'">
                <h2 class="text-lg font-semibold">Current Balance</h2>
                <p class="text-gray-500">{{ hasCredit ? 'You have a credit balance' : 'Your outstanding balance' }}</p>
                <p class="mt-2 text-4xl font-bold" :class="hasCredit ? 'text-green-600' : accountBalance > 0 ? 'text-red-600' : 'text-green-600'">
                    {{ hasCredit ? '−' : '' }}{{ formatCurrency(displayBalance) }}
                </p>
                <p v-if="hasCredit" class="mt-1 text-sm text-green-600">Credit will be applied to your next assessment.</p>
            </div>

            <!-- ── Search Bar (staff only) ── -->
            <div v-if="isStaff" class="rounded-xl border bg-white p-4 shadow-sm">
                <input
                    v-model="search"
                    type="text"
                    class="w-full rounded-lg border p-3 outline-none focus:border-transparent focus:ring-2 focus:ring-blue-500"
                    placeholder="Search by reference, type, or student…"
                />
            </div>

            <!-- ── No Results ── -->
            <div
                v-if="Object.keys(filteredTransactionsByTermWithAssessments).length === 0"
                class="py-12 text-center"
            >
                <p class="text-lg text-gray-500">No transactions found</p>
                <p class="mt-2 text-sm text-gray-400">Try adjusting your search or show past semesters</p>
            </div>

            <!-- ── Term Groups ── -->
            <div
                v-for="(transactions, termKey) in filteredTransactionsByTermWithAssessments"
                :key="termKey"
                class="overflow-hidden rounded-xl border bg-white shadow-sm"
            >
                <!-- Collapsible header -->
                <div
                    class="flex cursor-pointer items-center justify-between p-5 transition-colors select-none hover:bg-gray-50"
                    @click="toggle(termKey)"
                >
                    <div class="flex-1">
                        <div class="flex items-center gap-3">
                            <h2 class="text-xl font-bold">{{ termKey }}</h2>
                            <span v-if="termKey === currentTerm" class="rounded-full bg-blue-100 px-2 py-1 text-xs font-semibold text-blue-800">
                                Current Term
                            </span>
                        </div>
                        <p class="mt-1 text-gray-500">
                            <template v-if="transactions.length > 0">
                                {{ transactions.length }} transaction{{ transactions.length !== 1 ? 's' : '' }}
                            </template>
                            <template v-else>
                                <span class="text-amber-600">No payments recorded yet</span>
                            </template>
                        </p>
                    </div>

                    <!-- Summary numbers -->
                    <div class="flex items-center gap-10 text-right">
                        <div>
                            <p class="text-xs text-gray-500">Total Assessed</p>
                            <p class="font-bold text-red-600">{{ formatCurrency(calculateTermSummary(String(termKey), transactions).total_assessment) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Total Paid</p>
                            <p class="font-bold text-green-600">{{ formatCurrency(calculateTermSummary(String(termKey), transactions).total_paid) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Balance</p>
                            <p class="font-bold" :class="calculateTermSummary(String(termKey), transactions).current_balance > 0 ? 'text-red-600' : 'text-green-600'">
                                {{ formatCurrency(Math.abs(calculateTermSummary(String(termKey), transactions).current_balance)) }}
                            </p>
                        </div>

                        <button
                            :disabled="!canDownloadTermSummary(transactions)"
                            :class="[
                                'rounded-lg px-4 py-2 text-sm font-medium transition-colors',
                                canDownloadTermSummary(transactions)
                                    ? 'bg-blue-600 text-white hover:bg-blue-700'
                                    : 'cursor-not-allowed bg-gray-300 text-gray-500',
                            ]"
                            @click.stop="canDownloadTermSummary(transactions) && downloadTermSummary(termKey)"
                            :title="canDownloadTermSummary(transactions)
                                ? 'Download full term summary'
                                : 'Not available — payments are still awaiting verification'"
                        >
                            📄 Term Summary
                        </button>

                        <svg
                            :class="expanded[termKey] ? 'rotate-180' : ''"
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-6 w-6 transition-transform"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </div>
                </div>

                <!-- Expanded content -->
                <div v-if="expanded[termKey]" class="border-t p-5">

                    <!-- ══ EMPTY STATE — assessment exists, no payments yet ══ -->
                    <div v-if="transactions.length === 0" class="py-4">
                        <div class="flex flex-col items-center justify-center py-8 text-center">
                            <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-blue-50">
                                <Receipt class="h-7 w-7 text-blue-400" />
                            </div>
                            <h3 class="mb-1 text-base font-semibold text-gray-700">No payments recorded yet</h3>
                            <p class="mb-5 max-w-sm text-sm text-gray-500">
                                Your account has been assessed for this term.
                                Make your first payment to get started.
                            </p>
                            <button
                                v-if="!isStaff && canMakePayment"
                                @click="payNow"
                                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-700"
                            >
                                Make a Payment
                            </button>
                        </div>

                        <div
                            v-if="currentTermAssessment && termKey === currentTerm"
                            class="mt-4 rounded-lg border border-blue-100 bg-blue-50 p-5"
                        >
                            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-blue-600">Assessment Summary</p>
                            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                                <div>
                                    <p class="text-xs text-gray-500">Year Level</p>
                                    <p class="mt-0.5 text-sm font-semibold text-gray-800">{{ currentTermAssessment.year_level }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Term</p>
                                    <p class="mt-0.5 text-sm font-semibold text-gray-800">
                                        {{ currentTermAssessment.school_year }} · {{ currentTermAssessment.semester }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Total Assessment</p>
                                    <p class="mt-0.5 text-sm font-bold text-red-600">
                                        {{ formatCurrency(currentTermAssessment.total_assessment) }}
                                    </p>
                                </div>
                            </div>
                            <p class="mt-3 text-xs text-blue-500">
                                Your enrolled subjects are shown in the panel below.
                            </p>
                        </div>
                    </div>
                    <!-- ══ END EMPTY STATE ══ -->

                    <!-- ══ TRANSACTION TABLE — when payments exist ══ -->
                    <div v-else class="overflow-x-auto">
                        <table class="w-full border-collapse text-left">
                            <thead>
                                <tr class="bg-gray-100 text-xs text-gray-600 uppercase">
                                    <th class="p-3 font-semibold">OR / REF No.</th>
                                    <th v-if="isStaff" class="p-3 font-semibold">Student</th>
                                    <th class="p-3 font-semibold">Method</th>
                                    <th class="p-3 font-semibold">Category</th>
                                    <th class="p-3 font-semibold">Year &amp; Semester</th>
                                    <th class="p-3 font-semibold">Amount</th>
                                    <th class="p-3 font-semibold">Status</th>
                                    <th class="p-3 font-semibold">Date</th>
                                    <th class="p-3 font-semibold">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="t in transactions" :key="t.id" class="border-b transition-colors hover:bg-gray-50">
                                    <td class="p-3 font-mono text-xs">
                                        <p class="font-medium text-gray-800">{{ displayRefNumber(t) }}</p>
                                        <p class="mt-0.5 font-sans text-xs text-gray-400">{{ displayRefLabel(t) }}</p>
                                    </td>
                                    <td v-if="isStaff" class="p-3 text-sm">
                                        <div>
                                            <p class="font-medium">{{ t.user?.name }}</p>
                                            <p class="text-xs text-gray-500">{{ t.user?.account_id }}</p>
                                        </div>
                                    </td>
                                    <td class="p-3 text-sm">
                                        <span v-if="t.kind === 'charge'" class="text-xs italic text-gray-400">—</span>
                                        <span v-else>{{ formatPaymentMethod(t.payment_channel ?? '') }}</span>
                                    </td>
                                    <td class="p-3 text-sm">{{ t.meta?.term_name ?? t.type }}</td>
                                    <td class="p-3 text-sm">
                                        <span v-if="t.year || t.semester" class="font-medium">
                                            {{ toYearRange(t.year) }} {{ t.semester }}
                                        </span>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                    <td class="p-3 font-semibold text-gray-800">{{ formatCurrency(t.amount) }}</td>
                                    <td class="p-3">
                                        <span
                                            class="rounded-full px-2 py-1 text-xs font-semibold"
                                            :class="{
                                                'bg-green-100 text-green-800':   t.status === 'paid',
                                                'bg-yellow-100 text-yellow-800': t.status === 'pending',
                                                'bg-blue-100 text-blue-800':     t.status === 'awaiting_approval',
                                                'bg-orange-100 text-orange-800': t.status === 'awaiting_proof',
                                                'bg-red-100 text-red-800':       t.status === 'failed',
                                                'bg-gray-100 text-gray-800':     t.status === 'cancelled',
                                            }"
                                        >
                                            {{
                                                t.status === 'awaiting_approval' ? 'Awaiting Verification'
                                                : t.status === 'awaiting_proof'  ? 'Upload Proof'
                                                : t.status
                                            }}
                                        </span>
                                    </td>
                                    <td class="p-3 text-xs text-gray-500">{{ formatDate(t.created_at) }}</td>
                                    <td class="p-3">
                                        <div class="flex gap-2">
                                            <button
                                                @click="viewTransaction(t)"
                                                class="rounded-lg bg-blue-600 px-3 py-1 text-xs text-white transition-colors hover:bg-blue-700"
                                            >
                                                View
                                            </button>
                                            <a
                                                v-if="!isStaff && t.status === 'awaiting_proof' && t.payment_channel === 'bank_transfer'"
                                                :href="route('payment.proof.show', { transaction: t.id })"
                                                class="rounded-lg bg-orange-500 px-3 py-1 text-xs text-white transition-colors hover:bg-orange-600"
                                            >
                                                Upload Proof
                                            </a>
                                            <button
                                                v-if="t.kind === 'payment' && t.status === 'paid'"
                                                @click="downloadReceipt(t.id)"
                                                class="rounded-lg bg-green-600 px-3 py-1 text-xs text-white transition-colors hover:bg-green-700"
                                                title="Download payment receipt"
                                            >
                                                📄 Receipt
                                            </button>
                                            <span
                                                v-if="t.kind === 'payment' && t.status === 'awaiting_approval'"
                                                class="cursor-not-allowed rounded-lg bg-gray-200 px-3 py-1 text-xs text-gray-500"
                                                title="Receipt not available — payment is awaiting accounting verification"
                                            >
                                                ⏳ Pending
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <!-- ══ END TRANSACTION TABLE ══ -->

                    <!-- ── Enrolled Subjects Panel ───────────────────────────── -->
                    <!-- Only shown for student-facing view (staff branch sends empty allAssessments) -->
                    <div v-if="!isStaff && subjectPanelsByTerm[termKey]" class="border-t border-gray-100">
                        <!-- Accordion header -->
                        <button
                            type="button"
                            class="flex w-full items-center justify-between bg-indigo-50 px-5 py-3 text-left transition-colors hover:bg-indigo-100 select-none"
                            @click="toggleSubjectTerm(subjectPanelsByTerm[termKey]!.assessmentId)"
                        >
                            <div class="flex items-center gap-2">
                                <BookOpen class="h-4 w-4 text-indigo-500" />
                                <span class="text-sm font-semibold text-indigo-800">
                                    Enrolled Subjects
                                </span>
                                <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700">
                                    {{ subjectPanelsByTerm[termKey]!.subjectCount }} subjects
                                </span>
                                <span class="text-xs text-indigo-500">
                                    · {{ subjectPanelsByTerm[termKey]!.totalLecUnits + subjectPanelsByTerm[termKey]!.totalLabUnits }} total units
                                </span>
                            </div>
                            <ChevronDown
                                class="h-4 w-4 text-indigo-600 transition-transform duration-200"
                                :class="{ 'rotate-180': expandedSubjectTerms.has(subjectPanelsByTerm[termKey]!.assessmentId) }"
                            />
                        </button>

                        <!-- Expanded subject table -->
                        <div
                            v-if="expandedSubjectTerms.has(subjectPanelsByTerm[termKey]!.assessmentId)"
                            class="border-t border-gray-100"
                        >
                            <table class="min-w-full text-sm">
                                <thead class="border-b border-gray-200 bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="px-5 py-3 text-left">Code</th>
                                        <th class="px-5 py-3 text-left">Subject Name</th>
                                        <th class="px-5 py-3 text-center">Units</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr
                                        v-for="subject in subjectPanelsByTerm[termKey]!.subjects"
                                        :key="subject.subject_id ?? subject.code"
                                        class="hover:bg-gray-50"
                                    >
                                        <td class="px-5 py-3">
                                            <span class="rounded bg-indigo-50 px-2 py-0.5 font-mono text-xs font-semibold text-indigo-700">
                                                {{ subject.code }}
                                            </span>
                                        </td>
                                        <td class="px-5 py-3 font-medium text-gray-900">
                                            {{ subject.name }}
                                        </td>
                                        <td class="px-5 py-3 text-center">
                                            <span class="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700">
                                                {{ subject.lec_units + subject.lab_units }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot class="border-t-2 border-gray-200 bg-gray-50 text-sm font-semibold">
                                    <tr>
                                        <td colspan="2" class="px-5 py-3 text-gray-700">
                                            Total — {{ subjectPanelsByTerm[termKey]!.subjectCount }} subject{{ subjectPanelsByTerm[termKey]!.subjectCount !== 1 ? 's' : '' }}
                                        </td>
                                        <td class="px-5 py-3 text-center text-indigo-700">
                                            {{ subjectPanelsByTerm[termKey]!.totalLecUnits + subjectPanelsByTerm[termKey]!.totalLabUnits }}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                    <!-- ── End Enrolled Subjects Panel ── -->

                </div>
            </div>

            <!-- ── Transaction Detail Dialog ── -->
            <Dialog v-model:open="showDetailsDialog">
                <DialogContent class="max-h-[80vh] max-w-2xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Transaction Details</DialogTitle>
                        <DialogDescription>Complete information about this transaction</DialogDescription>
                    </DialogHeader>

                    <div v-if="selectedTransaction" class="space-y-5">
                        <!-- Basic Info -->
                        <div>
                            <h3 class="mb-3 border-b pb-2 text-base font-semibold">Basic Information</h3>
                            <div class="grid grid-cols-2 gap-3">
                                <div v-if="selectedTransaction.kind === 'payment'">
                                    <p class="text-xs text-gray-500">{{ displayRefLabel(selectedTransaction) }}</p>
                                    <p class="font-mono text-sm font-medium">{{ displayRefNumber(selectedTransaction) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Date</p>
                                    <p class="text-sm font-medium">{{ formatDate(selectedTransaction.created_at) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Term</p>
                                    <p class="text-sm font-medium">
                                        {{ selectedTransaction.year ? toYearRange(selectedTransaction.year) + ' ' + (selectedTransaction.semester ?? '') : '—' }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Kind</p>
                                    <span
                                        class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :class="selectedTransaction.kind === 'charge' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'"
                                    >
                                        {{ selectedTransaction.kind === 'charge' ? 'Assessment' : 'Payment' }}
                                    </span>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Status</p>
                                    <span
                                        class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold"
                                        :class="{
                                            'bg-green-100 text-green-800':   selectedTransaction.status === 'paid',
                                            'bg-yellow-100 text-yellow-800': selectedTransaction.status === 'pending',
                                            'bg-blue-100 text-blue-800':     selectedTransaction.status === 'awaiting_approval',
                                            'bg-orange-100 text-orange-800': selectedTransaction.status === 'awaiting_proof',
                                            'bg-red-100 text-red-800':       selectedTransaction.status === 'failed',
                                            'bg-gray-100 text-gray-800':     selectedTransaction.status === 'cancelled',
                                        }"
                                    >
                                        {{
                                            selectedTransaction.status === 'awaiting_approval' ? 'Awaiting Verification'
                                            : selectedTransaction.status === 'awaiting_proof'  ? 'Upload Proof'
                                            : selectedTransaction.status
                                        }}
                                    </span>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Category</p>
                                    <p class="text-sm font-medium">
                                        {{ selectedTransaction.meta?.term_name ?? selectedTransaction.type }}
                                    </p>
                                </div>
                                <div class="col-span-2">
                                    <p class="text-xs text-gray-500">Amount</p>
                                    <p class="text-2xl font-bold" :class="selectedTransaction.kind === 'charge' ? 'text-red-600' : 'text-green-600'">
                                        {{ selectedTransaction.kind === 'charge' ? '−' : '+' }}{{ formatCurrency(selectedTransaction.amount) }}
                                    </p>
                                </div>
                                <div v-if="!isStaff" class="col-span-2">
                                    <p class="text-xs text-gray-500">Overall Remaining Balance</p>
                                    <p class="text-lg font-bold" :class="accountBalance > 0 ? 'text-red-600' : 'text-green-600'">
                                        {{ formatCurrency(displayBalance) }}
                                        <span v-if="hasCredit" class="text-sm font-normal text-green-600">(Credit)</span>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Student info (staff only) -->
                        <div v-if="isStaff && selectedTransaction.user">
                            <h3 class="mb-3 border-b pb-2 text-base font-semibold">Student Information</h3>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <p class="text-xs text-gray-500">Name</p>
                                    <p class="text-sm font-medium">{{ selectedTransaction.user.name }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Student No.</p>
                                    <p class="text-sm font-medium">{{ selectedTransaction.user.account_id }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Email</p>
                                    <p class="text-sm font-medium">{{ selectedTransaction.user.email }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Payment info -->
                        <div v-if="selectedTransaction.kind === 'payment'">
                            <h3 class="mb-3 border-b pb-2 text-base font-semibold">Payment Information</h3>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <p class="text-xs text-gray-500">Payment Method</p>
                                    <p class="text-sm font-medium">
                                        {{ formatPaymentMethod(selectedTransaction.payment_channel ?? selectedTransaction.meta?.payment_method ?? '') }}
                                    </p>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500">Payment Date</p>
                                    <p class="text-sm font-medium">
                                        {{ selectedTransaction.paid_at ? formatDate(selectedTransaction.paid_at) : 'N/A' }}
                                    </p>
                                </div>
                                <div v-if="selectedTransaction.meta?.term_name" class="col-span-2">
                                    <p class="text-xs text-gray-500">Payment For</p>
                                    <p class="text-sm font-semibold text-green-700">{{ selectedTransaction.meta.term_name }}</p>
                                </div>
                                <div v-if="selectedTransaction.meta?.description" class="col-span-2">
                                    <p class="text-xs text-gray-500">Description</p>
                                    <p class="text-sm font-medium">{{ selectedTransaction.meta.description }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex justify-end gap-3 border-t pt-4">
                            <Button variant="outline" @click="closeDetailsDialog">Close</Button>
                            <Button
                                v-if="selectedTransaction.kind === 'payment' && selectedTransaction.status === 'paid'"
                                @click="downloadReceipt(selectedTransaction.id)"
                            >
                                📄 Payment Receipt
                            </Button>
                            <a
                                v-if="!isStaff && selectedTransaction.status === 'awaiting_proof' && selectedTransaction.payment_channel === 'bank_transfer'"
                                :href="route('payment.proof.show', { transaction: selectedTransaction.id })"
                                class="inline-flex items-center rounded-lg bg-orange-600 px-4 py-2 text-sm font-medium text-white hover:bg-orange-700"
                                @click="closeDetailsDialog"
                            >
                                📎 Upload Proof of Payment
                            </a>
                            <span
                                v-if="selectedTransaction.kind === 'payment' && selectedTransaction.status === 'awaiting_approval'"
                                class="flex items-center rounded-lg bg-amber-100 px-4 py-2 text-sm font-medium text-amber-700"
                            >
                                ⏳ Awaiting Verification — Receipt Not Yet Available
                            </span>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>