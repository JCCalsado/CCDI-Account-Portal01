<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import EnrolledSubjectsSkeleton from '@/components/EnrolledSubjectsSkeleton.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useDataFormatting } from '@/composables/useDataFormatting';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, usePage, useForm, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    BookOpen,
    CalendarClock,
    CheckCircle,
    Clock,
    XCircle,
} from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const { formatCurrency, formatDate, getPaymentTermStatusConfig, getTransactionStatusConfig, getAssessmentStatusConfig } =
    useDataFormatting();

// ── Types ─────────────────────────────────────────────────────────────────────

type Fee = { name: string; amount: number; category?: string };

type Transaction = {
    id: number;
    reference: string;
    or_number?: string | null;
    payment_channel?: string | null;
    type: string;
    kind: string;
    amount: number;
    status: string;
    created_at: string;
    fee?: { name: string; category: string };
    meta?: {
        fee_name?: string;
        description?: string;
        assessment_id?: number;
        term_name?: string;
        selected_term_id?: number;
    };
};

type Account = { id: number; balance: number; user_id: number };

/**
 * Immutable per-subject billing snapshot from assessment_subjects.
 * All monetary fields are locked at assessment creation time — never
 * recalculated from live fee_settings.
 */
type EnrolledSubject = {
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
};

type SubjectTotals = {
    lec_units: number;
    lab_units: number;
    total_units: number;
    subject_count: number;
    total_subject_fee: number;
};

type FeeBreakdownItem = {
    category: string;
    name: string;
    code?: string;
    units?: number | null;
    amount: number;
};

type Assessment = {
    id: number;
    assessment_number: string;
    year_level: string;
    semester: string;
    school_year: string;
    tuition_fee: number;
    lab_fee: number;
    misc_fee: number;
    other_fees: number;
    total_assessment: number;
    status: string;
    created_at: string;
    is_irregular?: boolean;
    middle_initial?: string | null;
    student_name?: string;
    is_taking_nstp?: boolean;
    discount_type?: string | null;
    discount_percentage?: number;
    discount_name?: string | null;
};

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
    paid_date: string | null;
};

type Notification = {
    id: number;
    title: string;
    message: string;
    type?: string;
    target_role: string;
    user_id?: number | null;
    is_active: boolean;
    start_date?: string;
    end_date?: string;
    due_date?: string | null;
    payment_term_id?: number | null;
    dismissed_at?: string | null;
    created_at: string;
};

type AssessmentShape = {
    id: number;
    assessment_number: string;
    year_level: string;
    semester: string;
    school_year: string;
    course: string | null;
    total_assessment: number;
    tuition_fee: number;
    lab_fee: number;
    misc_fee: number;
    other_fees: number;
    lec_units: number;
    nstp_lec_units: number;
    lab_units: number;
    is_taking_nstp: boolean;
    discount_type: string | null;
    discount_percentage: number;
    discount_name: string | null;
    fee_breakdown: FeeBreakdownItem[];
    // ── NEW: real subject snapshot from assessment_subjects ───────────────────
    enrolled_subjects: EnrolledSubject[];
    subject_totals: SubjectTotals;
    status: string;
    created_at: string;
};

// ── Props ─────────────────────────────────────────────────────────────────────

const page = usePage();
const user = computed(() => page.props.auth?.user);

const props = withDefaults(
    defineProps<{
        account: Account;
        transactions: Transaction[];
        totalPaid: number;
        fees: Fee[];
        tab?: string;
        latestAssessment?: Assessment;
        paymentTerms?: PaymentTerm[];
        notifications?: Notification[];
        pendingApprovalPayments?: Array<{
            id: number;
            reference: string;
            amount: number;
            selected_term_id: number | null;
            term_name: string;
            created_at: string;
        }>;
        allAssessments?: AssessmentShape[];
    }>(),
    {
        tab: 'fees',
        paymentTerms: () => [],
        notifications: () => [],
        pendingApprovalPayments: () => [],
        allAssessments: () => [],
    },
);

// ── State ─────────────────────────────────────────────────────────────────────

const breadcrumbs = [{ title: 'My Account' }];

const getTabFromUrl = (): 'fees' | 'subjects' | 'history' => {
    const urlParams = new URLSearchParams(window.location.search);
    const tab = urlParams.get('tab');
    if (tab === 'history') return 'history';
    if (tab === 'subjects') return 'subjects';
    return 'fees';
};

const getInitialTab = (): 'fees' | 'subjects' | 'history' => {
    if (props.tab === 'history') return 'history';
    if (props.tab === 'subjects') return 'subjects';
    return getTabFromUrl();
};

const activeTab = ref<'fees' | 'subjects' | 'history'>(getInitialTab());

watch(() => props.tab, (newTab) => {
    if (newTab === 'history') activeTab.value = 'history';
    if (newTab === 'subjects') activeTab.value = 'subjects';
});

const autoRefreshInterval = ref<ReturnType<typeof setInterval> | null>(null);

const hasAwaitingApprovals = computed(() =>
    props.transactions.some((t) => t.status === 'awaiting_approval'),
);

// ── Notifications ─────────────────────────────────────────────────────────────

const hiddenNotifications = ref<Set<number>>(new Set());

const activeNotifications = computed(() =>
    props.notifications
        .filter((n) => !n.dismissed_at && !hiddenNotifications.value.has(n.id))
        .sort((a, b) => {
            if (a.type === 'payment_due' && b.type !== 'payment_due') return -1;
            if (a.type !== 'payment_due' && b.type === 'payment_due') return 1;
            if (a.due_date && b.due_date) {
                return new Date(a.due_date).getTime() - new Date(b.due_date).getTime();
            }
            return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
        }),
);

const getDueDateColor = (dueDateStr: string | null | undefined): 'red' | 'amber' | 'green' => {
    if (!dueDateStr) return 'amber';
    const diffDays = Math.ceil((new Date(dueDateStr).getTime() - Date.now()) / 86_400_000);
    if (diffDays <= 7) return 'red';
    if (diffDays <= 14) return 'amber';
    return 'green';
};

const dueDateLabel = (dueDateStr: string | null | undefined): string => {
    if (!dueDateStr) return '';
    const diffDays = Math.ceil((new Date(dueDateStr).getTime() - Date.now()) / 86_400_000);
    if (diffDays < 0) return `Overdue by ${Math.abs(diffDays)} day${Math.abs(diffDays) !== 1 ? 's' : ''}`;
    if (diffDays === 0) return 'Due today';
    if (diffDays === 1) return 'Due tomorrow';
    if (diffDays <= 14) return `Due in ${diffDays} days`;
    return `Due ${formatDate(dueDateStr)}`;
};

const dismissNotification = (notificationId: number) => {
    hiddenNotifications.value.add(notificationId);
    const form = useForm({});
    form.post(route('notifications.dismiss', notificationId), {
        preserveScroll: true,
        preserveState: true,
    });
};

// ── Financial computations ────────────────────────────────────────────────────

const totalAssessmentFee = computed(() => {
    if (props.latestAssessment) return Number(props.latestAssessment.total_assessment);
    return props.fees.reduce((sum, fee) => sum + Number(fee.amount), 0);
});

const remainingBalance = computed(() => {
    if (props.paymentTerms && props.paymentTerms.length > 0) {
        return Math.max(
            0,
            Math.round(props.paymentTerms.reduce((sum, t) => sum + Number(t.balance || 0), 0) * 100) / 100,
        );
    }
    return 0;
});

const totalPaid = computed(() => props.totalPaid);

// ── Current assessment shape ──────────────────────────────────────────────────

const currentAssessmentShape = computed<AssessmentShape | null>(() => {
    if (!props.latestAssessment) return null;
    return props.allAssessments.find((a) => a.id === props.latestAssessment!.id) ?? null;
});

// ── Fee breakdown (aggregate 3-row table) ────────────────────────────────────

const currentFeeBreakdown = computed<FeeBreakdownItem[]>(() =>
    currentAssessmentShape.value?.fee_breakdown ?? [],
);

const totalBreakdownUnits = computed<number>(() =>
    currentFeeBreakdown.value.reduce(
        (sum, item) => (item.units !== null && item.units !== undefined ? sum + item.units : sum),
        0,
    ),
);

// ── Enrolled subjects (real snapshot from assessment_subjects) ────────────────

const enrolledSubjects = computed<EnrolledSubject[]>(() =>
    currentAssessmentShape.value?.enrolled_subjects ?? [],
);

const hasEnrolledSubjects = computed(() => enrolledSubjects.value.length > 0);

const subjectTotals = computed(() => currentAssessmentShape.value?.subject_totals ?? null);

// ── Subject unit totals for the simplified header display ─────────────────────
// Only total academic units is shown to students — billing units are internal.
const totalAcademicUnits = computed(() =>
    enrolledSubjects.value.reduce((sum, s) => sum + s.lec_units + s.lab_units, 0),
);

// ── Subject accordion state ───────────────────────────────────────────────────
const subjectsExpanded = ref(true);

// ── Payment terms ─────────────────────────────────────────────────────────────

const firstUnpaidTermId = computed(() => {
    const unpaid = props.paymentTerms
        ?.filter((t) => t.balance > 0)
        .sort((a, b) => a.term_order - b.term_order);
    return unpaid?.[0]?.id ?? null;
});

const isOverdue = (dueDate: string | null | undefined): boolean => {
    if (!dueDate) return false;
    const due   = new Date(dueDate);
    const today = new Date();
    due.setHours(0, 0, 0, 0);
    today.setHours(0, 0, 0, 0);
    return due < today;
};

// ── Pending approvals ─────────────────────────────────────────────────────────

const hasPendingPayments = computed(
    () => props.pendingApprovalPayments && props.pendingApprovalPayments.length > 0,
);

// ── Payment history ───────────────────────────────────────────────────────────

const paymentHistory = computed(() =>
    props.transactions
        .filter((t) => t.kind === 'payment')
        .sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime()),
);

// ── Transaction dialog ────────────────────────────────────────────────────────

const selectedTransaction = ref<Transaction | null>(null);
const showDetailsDialog = ref(false);

const viewTransaction = (transaction: Transaction) => {
    selectedTransaction.value = transaction;
    showDetailsDialog.value = true;
};

const closeDetailsDialog = () => {
    showDetailsDialog.value = false;
    selectedTransaction.value = null;
};

const downloadReceipt = (transactionId: number) => {
    window.open(route('transactions.receipt', { transaction: transactionId }), '_blank');
};

const accountBalance = computed(() => remainingBalance.value);

// ── Reference display helpers ─────────────────────────────────────────────────

const CASH_CHANNELS = new Set(['cash', 'cash_payment', 'over_the_counter']);

function getTransactionDisplayRef(txn: Transaction): { label: string; value: string } {
    const channel = (txn.payment_channel ?? '').toLowerCase();
    if (CASH_CHANNELS.has(channel)) {
        return { label: 'OR No.', value: txn.or_number ?? txn.reference ?? 'N/A' };
    }
    return { label: 'Ref No.', value: txn.reference ?? 'N/A' };
}

// ── Pay Now navigation ────────────────────────────────────────────────────────

const goToPayment = (termId?: number) => {
    const params: Record<string, any> = {};
    if (termId) params.term_id = termId;
    if (props.latestAssessment?.id) params.assessment_id = props.latestAssessment.id;
    router.get(route('payment.create'), params);
};

// ── Discount label helper ─────────────────────────────────────────────────────

const discountLabel = computed(() => {
    const a = currentAssessmentShape.value;
    if (!a?.discount_type || a.discount_type === 'none') return null;
    if (a.discount_name) return a.discount_name;
    if (a.discount_type === 'percentage') return `${a.discount_percentage}% Discount`;
    return 'Discount Applied';
});

// ── Lifecycle ─────────────────────────────────────────────────────────────────

onMounted(() => {
    if (hasAwaitingApprovals.value) {
        autoRefreshInterval.value = setInterval(() => router.reload(), 10000);
    }
});

watch(hasAwaitingApprovals, (newVal) => {
    if (newVal && !autoRefreshInterval.value) {
        autoRefreshInterval.value = setInterval(() => router.reload(), 10000);
    } else if (!newVal && autoRefreshInterval.value) {
        clearInterval(autoRefreshInterval.value);
        autoRefreshInterval.value = null;
    }
});

onUnmounted(() => {
    if (autoRefreshInterval.value) clearInterval(autoRefreshInterval.value);
});
</script>

<template>
    <AppLayout>
        <Head title="My Account" />

        <div class="w-full p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Active Notifications -->
            <div
                v-for="notification in activeNotifications"
                :key="notification.id"
                class="mb-4 flex items-start gap-3 rounded-lg border p-4"
                :class="notification.type === 'payment_due'
                    ? 'border-amber-300 bg-amber-50'
                    : 'border-blue-200 bg-blue-50'"
            >
                <div
                    class="mt-0.5 flex-shrink-0 rounded-full p-1"
                    :class="notification.type === 'payment_due' ? 'bg-amber-100' : 'bg-blue-100'"
                >
                    <AlertCircle
                        :size="18"
                        :class="notification.type === 'payment_due' ? 'text-amber-600' : 'text-blue-600'"
                    />
                </div>

                <div class="min-w-0 flex-1">
                    <h3
                        class="mb-0.5 text-sm font-semibold"
                        :class="notification.type === 'payment_due' ? 'text-amber-900' : 'text-blue-900'"
                    >
                        {{ notification.title }}
                    </h3>

                    <div v-if="notification.type === 'payment_due' && notification.due_date" class="mb-2">
                        <span
                            :class="[
                                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                getDueDateColor(notification.due_date) === 'red'
                                    ? 'bg-red-100 text-red-700 ring-1 ring-red-200'
                                    : getDueDateColor(notification.due_date) === 'amber'
                                      ? 'bg-amber-100 text-amber-700 ring-1 ring-amber-200'
                                      : 'bg-green-100 text-green-700 ring-1 ring-green-200',
                            ]"
                        >
                            <CalendarClock :size="11" />
                            {{ dueDateLabel(notification.due_date) }}
                            <span class="font-normal opacity-75">· {{ formatDate(notification.due_date) }}</span>
                        </span>
                    </div>

                    <p
                        class="text-sm leading-relaxed"
                        :class="notification.type === 'payment_due' ? 'text-amber-800' : 'text-blue-800'"
                    >
                        {{ notification.message }}
                    </p>

                    <div v-if="notification.type === 'payment_due' && notification.payment_term_id" class="mt-2">
                        <button
                            @click="goToPayment(notification.payment_term_id!)"
                            class="inline-flex items-center gap-1 rounded-lg bg-green-600 px-3 py-1 text-xs font-semibold text-white transition hover:bg-green-700"
                        >
                            Pay Now
                        </button>
                    </div>
                </div>

                <button
                    @click="dismissNotification(notification.id)"
                    class="ml-2 flex-shrink-0 rounded p-1 text-gray-400 transition-colors hover:bg-gray-200 hover:text-gray-600"
                    title="Dismiss"
                >
                    ✕
                </button>
            </div>

            <!-- Auto-Refresh indicator -->
            <div
                v-if="hasAwaitingApprovals"
                class="mb-4 flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 p-3"
            >
                <div class="h-2 w-2 animate-pulse rounded-full bg-blue-500"></div>
                <p class="text-sm text-blue-700">
                    <strong>Checking for updates…</strong>
                    Your payment is awaiting verification. This page will refresh automatically.
                </p>
            </div>

            <!-- Header -->
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="ccdi-section-title">My Account Overview</h1>
                    <div class="mt-2 flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                        <div v-if="latestAssessment" class="flex items-center gap-1.5">
                            <span class="h-1.5 w-1.5 rounded-full bg-blue-400"></span>
                            <span>{{ latestAssessment.semester }} · {{ latestAssessment.school_year }}</span>
                        </div>
                        <div v-if="latestAssessment" class="font-mono text-xs">
                            {{ latestAssessment.assessment_number }}
                        </div>
                        <span
                            v-if="latestAssessment"
                            :class="[
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold',
                                latestAssessment.is_irregular
                                    ? 'bg-amber-100 text-amber-700'
                                    : 'bg-blue-100 text-blue-700',
                            ]"
                        >
                            {{ latestAssessment.is_irregular ? 'Irregular' : 'Regular' }}
                        </span>
                        <span
                            v-if="discountLabel"
                            class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700"
                        >
                            🎓 {{ discountLabel }}
                        </span>
                    </div>
                </div>

                <Link
                    v-if="remainingBalance > 0"
                    :href="route('payment.create', latestAssessment?.id ? { assessment_id: latestAssessment.id } : {})"
                    class="inline-flex items-center gap-2 rounded-xl bg-green-600 px-5 py-2.5 text-sm font-semibold text-white shadow transition hover:bg-green-700"
                >
                    💳 Make Payment
                </Link>
            </div>

            <!-- Balance Summary Cards -->
            <div class="mb-8 grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="ccdi-stat-card">
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Total Assessment</p>
                    <p class="text-2xl font-bold text-blue-600">{{ formatCurrency(totalAssessmentFee) }}</p>
                    <p v-if="latestAssessment" class="mt-0.5 text-xs text-muted-foreground">
                        {{ latestAssessment.semester }} · {{ latestAssessment.school_year }}
                    </p>
                </div>

                <div class="ccdi-stat-card">
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Total Paid</p>
                    <p class="text-2xl font-bold text-emerald-600">{{ formatCurrency(totalPaid) }}</p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ paymentHistory.filter((t) => t.status === 'paid').length }} payment(s)
                    </p>
                </div>

                <div class="ccdi-stat-card">
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Remaining Balance</p>
                    <p
                        class="text-2xl font-bold"
                        :class="remainingBalance > 0 ? 'text-red-600' : 'text-emerald-600'"
                    >
                        {{ formatCurrency(remainingBalance) }}
                    </p>
                    <p v-if="remainingBalance <= 0" class="mt-0.5 text-xs font-medium text-emerald-600">✓ Fully paid</p>
                </div>
            </div>

            <!-- Tabs -->
            <div class="mb-6 ccdi-card">
                <div class="border-b border-border">
                    <nav class="flex gap-1 px-4">
                        <button
                            @click="activeTab = 'fees'"
                            :class="[
                                'border-b-2 px-4 py-3.5 text-sm font-medium transition-colors',
                                activeTab === 'fees'
                                    ? 'border-blue-600 text-blue-600'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            ]"
                        >
                            Fees &amp; Assessment
                        </button>
                        <button
                            @click="activeTab = 'subjects'"
                            :class="[
                                'flex items-center gap-1.5 border-b-2 px-4 py-3.5 text-sm font-medium transition-colors',
                                activeTab === 'subjects'
                                    ? 'border-indigo-600 text-indigo-600'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            ]"
                        >
                            <BookOpen :size="14" />
                            Enrolled Subjects
                            <span
                                v-if="hasEnrolledSubjects"
                                class="rounded-full bg-indigo-100 px-1.5 py-0.5 text-xs font-semibold text-indigo-700"
                            >
                                {{ enrolledSubjects.length }}
                            </span>
                        </button>
                        <button
                            @click="activeTab = 'history'"
                            :class="[
                                'border-b-2 px-4 py-3.5 text-sm font-medium transition-colors',
                                activeTab === 'history'
                                    ? 'border-blue-600 text-blue-600'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            ]"
                        >
                            Payment History
                        </button>
                    </nav>
                </div>

                <div class="p-6">

                    <!-- ══ FEES TAB ════════════════════════════════════════════ -->
                    <div v-if="activeTab === 'fees'">
                        <h2 class="mb-4 text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                            Current Assessment
                        </h2>

                        <div v-if="latestAssessment" class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4">
                            <div class="grid grid-cols-2 gap-4 text-sm md:grid-cols-4">
                                <div>
                                    <span class="text-gray-600">Assessment No:</span>
                                    <p class="font-semibold">{{ latestAssessment.assessment_number }}</p>
                                </div>
                                <div>
                                    <span class="text-gray-600">School Year:</span>
                                    <p class="font-semibold">{{ latestAssessment.school_year }}</p>
                                </div>
                                <div>
                                    <span class="text-gray-600">Semester:</span>
                                    <p class="font-semibold">{{ latestAssessment.semester }}</p>
                                </div>
                                <div>
                                    <span class="text-gray-600">Status:</span>
                                    <span
                                        :class="[
                                            'ml-2 inline-block rounded-full px-2 py-1 text-xs font-semibold',
                                            getAssessmentStatusConfig(latestAssessment.status).bgClass,
                                            getAssessmentStatusConfig(latestAssessment.status).textClass,
                                        ]"
                                    >
                                        {{ getAssessmentStatusConfig(latestAssessment.status).label }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Terms Table -->
                        <div v-if="paymentTerms && paymentTerms.length" class="mt-6 border-t pt-6">
                            <h3 class="mb-4 flex items-center gap-2 text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                                <Clock :size="15" /> Payment Terms
                            </h3>
                            <div class="overflow-x-auto rounded-2xl border border-border">
                                <table class="w-full border-collapse">
                                    <thead>
                                        <tr class="border-b border-border bg-muted/40">
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground">Term</th>
                                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Amount</th>
                                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Balance</th>
                                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-muted-foreground">Due Date</th>
                                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-muted-foreground">Status</th>
                                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-muted-foreground">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="term in paymentTerms"
                                            :key="term.id"
                                            class="border-b border-border transition-colors hover:bg-muted/30"
                                        >
                                            <td class="px-4 py-3 text-gray-900">{{ term.term_name }}</td>
                                            <td class="px-4 py-3 text-right text-gray-700">{{ formatCurrency(term.amount) }}</td>
                                            <td
                                                class="px-4 py-3 text-right font-medium"
                                                :class="term.balance > 0 ? 'text-red-600' : 'text-green-600'"
                                            >
                                                {{ formatCurrency(Math.max(0, term.balance)) }}
                                            </td>
                                            <td class="px-4 py-3 text-right">
                                                <p class="text-sm text-gray-700">
                                                    {{ term.due_date ? formatDate(term.due_date) : '—' }}
                                                </p>
                                                <p
                                                    v-if="term.due_date && isOverdue(term.due_date) && term.status !== 'paid'"
                                                    class="mt-1 text-xs text-red-600"
                                                >
                                                    ⚠️ Overdue
                                                </p>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <span
                                                    :class="[
                                                        'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                                                        getPaymentTermStatusConfig(term.status).bgClass,
                                                        getPaymentTermStatusConfig(term.status).textClass,
                                                    ]"
                                                >
                                                    {{ getPaymentTermStatusConfig(term.status).label }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-center">
                                                <button
                                                    v-if="term.balance > 0 && term.id === firstUnpaidTermId"
                                                    @click="goToPayment(term.id)"
                                                    class="rounded bg-indigo-600 px-2 py-1 text-xs text-white transition-colors hover:bg-indigo-700"
                                                >
                                                    Pay Now
                                                </button>
                                                <span
                                                    v-else-if="term.balance > 0"
                                                    class="cursor-not-allowed rounded bg-gray-200 px-2 py-1 text-xs text-gray-500"
                                                    title="Pay earlier terms first"
                                                >
                                                    Locked
                                                </span>
                                                <span v-else class="rounded bg-green-100 px-2 py-1 text-xs text-green-700">
                                                    Paid ✓
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Fee Breakdown -->
                        <div class="mt-8 border-t pt-6">
                            <h3 class="mb-4 text-sm font-semibold uppercase tracking-widest text-muted-foreground">
                                Fee Breakdown
                            </h3>
                            <div v-if="!latestAssessment" class="rounded-lg border border-dashed border-gray-200 py-8 text-center">
                                <p class="text-sm text-gray-400">No assessment available.</p>
                            </div>
                            <template v-else>
                                <div class="overflow-hidden rounded-lg border border-gray-200">
                                    <table class="w-full text-sm">
                                        <thead class="border-b border-gray-200 bg-gray-50">
                                            <tr>
                                                <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Fee Item</th>
                                                <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">Units</th>
                                                <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <tr
                                                v-for="item in currentFeeBreakdown"
                                                :key="item.name"
                                                class="hover:bg-gray-50"
                                            >
                                                <td class="px-4 py-3 text-gray-700">{{ item.name }}</td>
                                                <td class="px-4 py-3 text-center text-gray-400">—</td>
                                                <td class="px-4 py-3 text-right font-medium text-gray-900">
                                                    {{ formatCurrency(item.amount) }}
                                                </td>
                                            </tr>
                                        </tbody>
                                        <tfoot class="border-t-2 border-gray-300 bg-gray-50">
                                            <tr>
                                                <td class="px-4 py-3 font-bold text-gray-900">Total Assessment Fee</td>
                                                <td class="px-4 py-3 text-center font-bold text-gray-700">
                                                    <span v-if="totalBreakdownUnits > 0">{{ totalBreakdownUnits }}</span>
                                                    <span v-else class="text-gray-400">—</span>
                                                </td>
                                                <td class="px-4 py-3 text-right text-base font-bold text-gray-900">
                                                    {{ formatCurrency(latestAssessment.total_assessment) }}
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>

                            </template>
                        </div>

                        <!-- Pending Approvals Banner -->
                        <div v-if="hasPendingPayments" class="mt-6 rounded-lg border border-amber-300 bg-amber-50 p-4">
                            <div class="mb-3 flex items-center gap-2">
                                <Clock :size="18" class="text-amber-600" />
                                <h3 class="font-semibold text-amber-900">
                                    Pending Approval ({{ pendingApprovalPayments.length }})
                                </h3>
                            </div>
                            <div class="space-y-2">
                                <div
                                    v-for="payment in pendingApprovalPayments"
                                    :key="payment.id"
                                    class="flex items-center justify-between rounded border border-amber-200 bg-white p-3"
                                >
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">{{ payment.term_name }}</p>
                                        <p class="text-xs text-gray-600">
                                            OR: {{ payment.reference }} · {{ formatDate(payment.created_at) }}
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm font-semibold text-amber-700">
                                            {{ formatCurrency(payment.amount) }}
                                        </p>
                                        <p class="text-xs text-amber-600">⏳ Awaiting Approval</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ══ ENROLLED SUBJECTS TAB ══════════════════════════════ -->
                    <div v-if="activeTab === 'subjects'">
                        <!-- Header row -->
                        <div class="mb-5 flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h2 class="text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                                    Enrolled Subjects
                                </h2>
                                <p v-if="latestAssessment" class="mt-0.5 text-sm text-gray-500">
                                    {{ latestAssessment.semester }} · {{ latestAssessment.school_year }}
                                </p>
                            </div>
                            <div v-if="hasEnrolledSubjects" class="flex items-center gap-3 text-sm text-gray-600">
                                <span class="font-medium">{{ enrolledSubjects.length }} subject{{ enrolledSubjects.length !== 1 ? 's' : '' }}</span>
                                <span class="text-gray-300">·</span>
                                <span>{{ totalAcademicUnits }} total units</span>
                            </div>
                        </div>

                        <!-- No assessment -->
                        <div
                            v-if="!latestAssessment"
                            class="rounded-lg border border-dashed border-gray-200 py-12 text-center"
                        >
                            <BookOpen :size="40" class="mx-auto mb-3 text-gray-300" />
                            <p class="text-sm text-gray-400">No assessment found for this account.</p>
                        </div>

                        <!-- Assessment exists but no subject records (e.g. irregular student) -->
                        <div
                            v-else-if="!hasEnrolledSubjects"
                            class="rounded-lg border border-dashed border-gray-200 py-10 text-center"
                        >
                            <BookOpen :size="40" class="mx-auto mb-3 text-gray-300" />
                            <p class="text-sm font-semibold text-gray-600">No subject records available.</p>
                            <p class="mt-1 text-xs text-gray-400">
                                Contact the Accounting Office for your enrollment details.
                            </p>
                        </div>

                        <!-- Subject list -->
                        <template v-else>
                            <div class="overflow-hidden rounded-xl border border-gray-200">
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
                                            v-for="subject in enrolledSubjects"
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
                                                Total — {{ enrolledSubjects.length }} subject{{ enrolledSubjects.length !== 1 ? 's' : '' }}
                                            </td>
                                            <td class="px-5 py-3 text-center text-indigo-700">{{ totalAcademicUnits }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </template>
                    </div>

                    <!-- ══ HISTORY TAB ════════════════════════════════════════ -->
                    <div v-if="activeTab === 'history'">
                        <h2 class="mb-4 text-xs font-semibold uppercase tracking-widest text-muted-foreground">
                            Payment History
                        </h2>

                        <div v-if="!paymentHistory.length" class="py-12 text-center">
                            <XCircle :size="48" class="mx-auto mb-3 text-gray-400" />
                            <p class="text-gray-500">No payment history yet</p>
                            <p class="mt-1 text-sm text-gray-400">Your payments will appear here</p>
                        </div>

                        <div v-else class="space-y-3">
                            <div
                                v-for="payment in paymentHistory"
                                :key="payment.id"
                                class="flex items-center justify-between rounded-lg border p-4 transition-colors hover:bg-gray-50"
                            >
                                <div class="flex items-center gap-3">
                                    <div class="rounded bg-green-100 p-2">
                                        <CheckCircle :size="20" class="text-green-600" />
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900">
                                            {{ payment.meta?.description || payment.meta?.term_name || payment.type || 'Payment' }}
                                        </p>
                                        <p class="text-sm text-gray-600">
                                            {{ payment.created_at ? formatDate(payment.created_at) : '—' }}
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            {{ getTransactionDisplayRef(payment).label }}:
                                            {{ getTransactionDisplayRef(payment).value }}
                                        </p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-lg font-semibold text-green-600">
                                        {{ formatCurrency(payment.amount) }}
                                    </p>
                                    <span
                                        :class="[
                                            'inline-block rounded px-2 py-1 text-xs font-medium',
                                            getTransactionStatusConfig(payment.status).bgClass,
                                            getTransactionStatusConfig(payment.status).textClass,
                                        ]"
                                    >
                                        {{ getTransactionStatusConfig(payment.status).label }}
                                    </span>
                                    <div class="mt-1">
                                        <button
                                            @click="viewTransaction(payment)"
                                            class="rounded bg-blue-600 px-2 py-0.5 text-xs text-white transition-colors hover:bg-blue-700"
                                        >
                                            View
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Transaction Detail Dialog -->
        <Dialog v-model:open="showDetailsDialog">
            <DialogContent class="max-h-[80vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Transaction Details</DialogTitle>
                    <DialogDescription>Complete information about this transaction</DialogDescription>
                </DialogHeader>

                <div v-if="selectedTransaction" class="space-y-5">
                    <div>
                        <h3 class="mb-3 border-b pb-2 text-base font-semibold">Basic Information</h3>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <p class="text-xs text-gray-500">
                                    {{ getTransactionDisplayRef(selectedTransaction).label }}
                                </p>
                                <p class="font-mono text-sm font-medium">
                                    {{ getTransactionDisplayRef(selectedTransaction).value }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Date</p>
                                <p class="text-sm font-medium">{{ formatDate(selectedTransaction.created_at) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Status</p>
                                <span
                                    class="inline-block rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="[
                                        getTransactionStatusConfig(selectedTransaction.status).bgClass,
                                        getTransactionStatusConfig(selectedTransaction.status).textClass,
                                    ]"
                                >
                                    {{ getTransactionStatusConfig(selectedTransaction.status).label }}
                                </span>
                            </div>
                            <div>
                                <p class="text-xs text-gray-500">Amount</p>
                                <p class="text-xl font-bold text-green-600">
                                    {{ formatCurrency(selectedTransaction.amount) }}
                                </p>
                            </div>
                            <div class="col-span-2">
                                <p class="text-xs text-gray-500">Outstanding Balance</p>
                                <p
                                    class="text-lg font-bold"
                                    :class="accountBalance > 0 ? 'text-red-600' : 'text-green-600'"
                                >
                                    {{ formatCurrency(Math.max(0, accountBalance)) }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div v-if="selectedTransaction.meta?.term_name">
                        <h3 class="mb-3 border-b pb-2 text-base font-semibold">Payment Information</h3>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="col-span-2">
                                <p class="text-xs text-gray-500">Payment For</p>
                                <p class="text-sm font-semibold text-green-700">
                                    {{ selectedTransaction.meta.term_name }}
                                </p>
                            </div>
                            <div v-if="selectedTransaction.meta?.description" class="col-span-2">
                                <p class="text-xs text-gray-500">Description</p>
                                <p class="text-sm">{{ selectedTransaction.meta.description }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 border-t pt-4">
                        <Button variant="outline" @click="closeDetailsDialog">Close</Button>
                        <Button
                            v-if="selectedTransaction.kind === 'payment' && selectedTransaction.status === 'paid'"
                            @click="downloadReceipt(selectedTransaction.id)"
                        >
                            📄 Receipt
                        </Button>
                        <span
                            v-if="selectedTransaction.status === 'awaiting_approval'"
                            class="rounded-lg bg-amber-100 px-4 py-2 text-sm font-medium text-amber-700"
                        >
                            ⏳ Awaiting Verification
                        </span>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>