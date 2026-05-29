<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useDataFormatting } from '@/composables/useDataFormatting';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowLeft,
    BookOpen,
    CheckCircle2,
    ChevronDown,
    CreditCard,
    Download,
    FlaskConical,
    GraduationCap,
    History,
    Plus,
    ReceiptText,
} from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

// ─── Types ─────────────────────────────────────────────────────────────────────

interface AssessmentSubjectRow {
    subject_id: number | null;
    code: string;
    name: string;
    lec_units: number;
    lab_units: number;
    is_nstp: boolean;
    is_pathfit: boolean;
    is_billable: boolean;
    tuition_fee: number;
    lab_fee: number;
    total_fee: number;
    nstp_billing_units: number;
}

interface PaymentTerm {
    id: number;
    term_name: string;
    term_order: number;
    percentage: number;
    amount: number;
    balance: number;
    due_date: string | null;
    status: string;
    remarks?: string;
}

interface FeeBreakdownItem {
    category: string;
    name: string;
    code?: string;
    units?: number;
    amount: number;
    subject_id?: number;
}

interface Assessment {
    id: number;
    course: string | null;
    semester: string;
    school_year: string;
    year_level: string;
    total_assessment: number;
    tuition_fee: number;
    lab_fee: number;
    misc_fee: number;
    tuition_per_unit?: number;
    lec_units?: number;
    nstp_lec_units?: number;
    lab_units?: number;
    other_fees: number;
    status?: string;
    discount_name?: string | null;
    discount_percentage?: number;
    is_taking_nstp?: boolean;
    fee_breakdown: FeeBreakdownItem[];
    paymentTerms?: PaymentTerm[];
    enrolled_subjects?: AssessmentSubjectRow[];
}

interface Props {
    student: any;
    assessment: any;
    allAssessments?: Assessment[];
    transactions?: any[];
    payments?: any[];
    feeBreakdown?: Array<{ category: string; total: number; items: number }>;
    backUrl?: string;
    enrolledSubjectsByAssessment?: Record<number, AssessmentSubjectRow[] | number[]>;
    // ✅ FIX: was missing from Props interface, causing TS error
    miscItems?: Array<{ label?: string; name?: string; amount: number }>;
}

const props = withDefaults(defineProps<Props>(), {
    allAssessments: () => [],
    transactions: () => [],
    payments: () => [],
    feeBreakdown: () => [],
    enrolledSubjectsByAssessment: () => ({}),
    miscItems: () => [],
});

// ─── Assessment selector ────────────────────────────────────────────────────

const { formatCurrency } = useDataFormatting();
const page = usePage();
const isAdmin = computed(() => (page.props.auth as any).user?.role === 'admin');
const isAccounting = computed(() => (page.props.auth as any).user?.role === 'accounting');

const selectedAssessmentId = ref<number | null>(props.assessment?.id ?? null);

const selectedAssessment = computed(() => {
    if (!selectedAssessmentId.value) return props.assessment;
    return (
        (props.allAssessments ?? []).find((a) => a.id === selectedAssessmentId.value) ??
        props.assessment
    );
});

const exportUrl = computed(() => {
    const base = route('student-fees.export-pdf', props.student.id);
    return selectedAssessmentId.value ? `${base}?assessment_id=${selectedAssessmentId.value}` : base;
});

// ─── Balance ────────────────────────────────────────────────────────────────

const remainingBalance = computed(() => {
    // Always use the selected assessment's payment terms for balance calculation
    const terms: PaymentTerm[] =
        (selectedAssessment.value as any)?.paymentTerms ??
        props.assessment?.paymentTerms ??
        [];
    
    // Sum balance from payment terms (most accurate source)
    if (terms.length > 0) {
        const termsTotal = terms.reduce((sum, t) => sum + parseFloat(String(t.balance)), 0);
        if (termsTotal > 0) return Math.round(termsTotal * 100) / 100;
    }

    // If NO payment terms exist for this assessment, balance is zero
    // DO NOT fall back to account.balance—that's the total across all assessments,
    // not the balance for THIS specific assessment
    return 0;
});

const totalAssessment = computed(() =>
    parseFloat(
        String(
            selectedAssessment.value?.total_assessment ??
                props.assessment?.total_assessment ??
                0,
        ),
    ),
);

const totalPaid = computed(() => Math.max(0, totalAssessment.value - remainingBalance.value));

const paymentTimingStatus = computed((): 'behind' | 'on_track' | 'paid' | 'no_due_date' => {
    const terms: PaymentTerm[] =
        (selectedAssessment.value as any)?.paymentTerms ??
        props.assessment?.paymentTerms ??
        [];

    if (remainingBalance.value === 0) return 'paid';

    // No terms at all — brand-new assessment, no due dates configured yet.
    if (terms.length === 0) return 'no_due_date';

    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const sorted = [...terms].sort((a, b) => a.term_order - b.term_order);

    // "Behind" only when a configured due_date has passed with an unpaid balance.
    const hasPastDue = sorted.some((term) => {
        if (!term.due_date) return false;
        if (parseFloat(String(term.balance)) <= 0) return false;
        const due = new Date(term.due_date);
        due.setHours(0, 0, 0, 0);
        return due < today;
    });

    if (hasPastDue) return 'behind';

    // Outstanding balance, but no due dates configured at all.
    const hasAnyDueDate = sorted.some((t) => !!t.due_date);
    if (!hasAnyDueDate) return 'no_due_date';

    return 'on_track';
});

const balanceCardConfig = computed(() => {
    switch (paymentTimingStatus.value) {
        case 'paid':
            return {
                bg: 'bg-gradient-to-r from-green-50 to-emerald-50 border-green-200',
                labelColor: 'text-green-700',
                amountColor: 'text-green-700',
                badge: { label: 'Fully Paid', cls: 'bg-green-500 text-white' },
            };
        case 'on_track':
            return {
                bg: 'bg-gradient-to-r from-blue-50 to-indigo-50 border-blue-200',
                labelColor: 'text-blue-700',
                amountColor: 'text-blue-700',
                badge: { label: 'On Track', cls: 'bg-blue-500 text-white' },
            };
        case 'no_due_date':
            return {
                bg: 'bg-gradient-to-r from-gray-50 to-slate-50 border-gray-200',
                labelColor: 'text-gray-600',
                amountColor: 'text-gray-800',
                badge: { label: 'Awaiting Due Date', cls: 'bg-gray-400 text-white' },
            };
        default: // 'behind'
            return {
                bg: 'bg-gradient-to-r from-red-50 to-rose-50 border-red-200',
                labelColor: 'text-red-700',
                amountColor: 'text-red-700',
                badge: { label: 'Behind Schedule', cls: 'bg-red-500 text-white' },
            };
    }
});

// ─── Payment Terms ────────────────────────────────────────────────────────────

const allTermsSorted = computed((): PaymentTerm[] => {
    const terms: PaymentTerm[] =
        (selectedAssessment.value as any)?.paymentTerms ??
        props.assessment?.paymentTerms ??
        [];
    return [...terms].sort((a, b) => a.term_order - b.term_order);
});

const paidTermsCount = computed(() =>
    allTermsSorted.value.filter((t) => t.status === 'paid').length,
);

// ─── Fee Breakdown ─────────────────────────────────────────────────────────────

const tuitionItems = computed(() => {
    const selectedAssess = selectedAssessment.value as any;
    if (!selectedAssess) return [];
    return (selectedAssess.fee_breakdown ?? [])
        .filter((item: any) => item.category === 'Tuition')
        .map((item: any) => ({
            ...item,
            units: parseFloat(String(item.units ?? 0)),
            displayName: item.name || item.code || 'Subject',
            amount: parseFloat(String(item.amount)),
        }));
});

const totalTuition = computed(() =>
    Math.round(
        tuitionItems.value.reduce((sum: number, item: any) => sum + item.amount, 0) * 100,
    ) / 100,
);

const labItems = computed(() => {
    const selectedAssess = selectedAssessment.value as any;
    if (!selectedAssess) return [];
    return (selectedAssess.fee_breakdown ?? [])
        .filter((item: any) => item.category === 'Laboratory')
        .map((item: any) => ({
            ...item,
            units: item.units ?? selectedAssess.lab_units ?? 0,
            displayName: item.name?.replace('Laboratory Fee — ', '') || 'Laboratory',
            amount: parseFloat(String(item.amount)),
        }));
});

const totalLab = computed(() =>
    Math.round(
        labItems.value.reduce((sum: number, item: any) => sum + item.amount, 0) * 100,
    ) / 100,
);

interface MiscItemGroup {
    category: string;
    subcategory: string;
    label: string;
    items: Array<{ name: string; amount: number }>;
    total: number;
}

const miscellaneousItemsByGroup = computed((): MiscItemGroup[] => {
    const selectedAssess = selectedAssessment.value as any;
    if (!selectedAssess) return [];

    const miscEntry = (selectedAssess.fee_breakdown ?? []).find(
        (item: any) => item.category === 'Miscellaneous',
    );
    if (!miscEntry) return [];

    const items: Array<{ name: string; amount: number }> = (props.miscItems ?? [])
        .filter((i: any) => parseFloat(String(i.amount)) > 0)
        .map((i: any) => ({
            name: i.label ?? i.name,
            amount: parseFloat(String(i.amount)),
        }));

    if (items.length === 0) {
        return [
            {
                category: 'Miscellaneous',
                subcategory: 'Miscellaneous',
                label: 'Miscellaneous Fees',
                items: [
                    {
                        name: 'Miscellaneous Fee (Fixed)',
                        amount: parseFloat(String(miscEntry.amount)),
                    },
                ],
                total: parseFloat(String(miscEntry.amount)),
            },
        ];
    }

    const academicPatterns = ['registration', 'lms', 'library', 'entrepreneurship'];
    const studentPatterns = [
        'athletic',
        'prisaa',
        'publication',
        'id',
        'biccs',
        'pccl',
        'league',
        'audio-visual',
        'faculty development',
        'guidance',
        'entrep',
    ];
    const supportPatterns = ['medical', 'insurance', 'cultural', 'maintenance'];

    const categories: Record<string, MiscItemGroup> = {
        academic: {
            category: 'Academic Services',
            subcategory: 'Academic',
            label: 'Academic Services',
            items: [],
            total: 0,
        },
        student_life: {
            category: 'Student Life & Activities',
            subcategory: 'Student',
            label: 'Student Life & Activities',
            items: [],
            total: 0,
        },
        support: {
            category: 'Support Services',
            subcategory: 'Support',
            label: 'Support Services',
            items: [],
            total: 0,
        },
        other: {
            category: 'Other Fees',
            subcategory: 'Other',
            label: 'Other Fees',
            items: [],
            total: 0,
        },
    };

    for (const item of items) {
        const name = item.name.toLowerCase();
        if (academicPatterns.some((p) => name.includes(p))) {
            categories.academic.items.push(item);
            categories.academic.total += item.amount;
        } else if (studentPatterns.some((p) => name.includes(p))) {
            categories.student_life.items.push(item);
            categories.student_life.total += item.amount;
        } else if (supportPatterns.some((p) => name.includes(p))) {
            categories.support.items.push(item);
            categories.support.total += item.amount;
        } else {
            categories.other.items.push(item);
            categories.other.total += item.amount;
        }
    }

    return Object.values(categories).filter((cat) => cat.items.length > 0);
});

const totalMiscellaneous = computed(() => {
    // Always use the stored assessment misc_fee — never derive from live fee_settings.
    // fee_settings can change after assessment creation; the stored value is the
    // authoritative historical amount that the student was actually assessed.
    const miscEntry = (
        (selectedAssessment.value as any)?.fee_breakdown ?? []
    ).find((i: any) => i.category === 'Miscellaneous');
    return miscEntry ? parseFloat(String(miscEntry.amount)) : 0;
});

const feeCalculationSummary = computed(() => {
    const assess = selectedAssessment.value as any;
    if (!assess) return '';

    // lec_units from fee_breakdown already includes nstp (controller sums them).
    // Fallback: use assessment.lec_units + assessment.nstp_lec_units directly.
    const lecFromBreakdown = tuitionItems.value.reduce(
        (sum: number, item: any) => sum + (parseFloat(String(item.units ?? 0)) || 0),
        0,
    );
    const totalLecUnits = lecFromBreakdown > 0
        ? lecFromBreakdown
        : (parseFloat(String(assess.lec_units ?? 0)) + parseFloat(String(assess.nstp_lec_units ?? 0)));

    const labCount = labItems.value.reduce(
        (sum: number, item: any) => sum + (parseFloat(String(item.units ?? 0)) || 0),
        0,
    );

    if (totalLecUnits <= 0) return '';

    const parts: string[] = [];

    // Read the rate directly — never reverse-engineer it from tuition_fee / units
    const lecRate = (assess?.tuition_per_unit ?? 364.00).toFixed(2);

    parts.push(`${totalLecUnits.toFixed(1)} LEC units × ₱${lecRate}`);

    if (labCount > 0)
        parts.push(`${labCount} LAB subjects × ₱1,656.00`);

    const entrepFee = parseFloat(String(assess.entrepreneurship_fee ?? 0));
    if (entrepFee > 0)
        parts.push(`₱${entrepFee.toFixed(2)} entrep`);

    if (totalMiscellaneous.value > 0)
        parts.push(`₱${totalMiscellaneous.value.toFixed(2)} misc`);

    return parts.length > 0 ? parts.join(' + ') : '—';
});

const discountLabel = computed(() => {
    const assess = selectedAssessment.value as any;
    const type = assess?.discount_type ?? 'none';
    const pct  = parseFloat(String(assess?.discount_percentage ?? 0));
    if (type === 'none' || !pct) return null;
    const labels: Record<string, string> = {
        scholarship: 'Scholarship',
        sibling:     'Sibling Discount',
        percentage:  'Discount',
        employee:    'Employee Discount',
    };
    return `${labels[type] ?? 'Discount'} (${pct}% off)`;
});

// ─── Transaction history ─────────────────────────────────────────────────────

interface TxGroup {
    key: string;
    assessmentId: number | null;
    transactions: any[];
    totalCharges: number;
    totalPaid: number;
    balance: number;
}

// All payment-kind transactions for this student (unfiltered — used by the Ledger)
const allPaymentTransactions = computed(() =>
    (props.transactions ?? []).filter((t: any) => t.kind === 'payment'),
);

// Transactions filtered to the selected assessment — used only by Payment History card
const filteredTransactions = computed(() => {
    if (!selectedAssessmentId.value || !selectedAssessment.value) return allPaymentTransactions.value;
    const assessment = selectedAssessment.value;
    return allPaymentTransactions.value.filter((t: any) => {
        const startYear = parseInt(String(assessment.school_year?.split('-')[0] ?? ''), 10);
        return (
            parseInt(String(t.year), 10) === startYear &&
            String(t.semester).trim() === String(assessment.semester).trim()
        );
    });
});

const transactionsByTerm = computed((): TxGroup[] => {
    const groups: Record<string, { transactions: any[]; assessmentId: number | null }> = {};

    for (const t of allPaymentTransactions.value) {
        let key: string;
        if (t.year && t.semester) {
            const startYear = parseInt(String(t.year), 10);
            key = `${isNaN(startYear) ? String(t.year) : `${startYear}-${startYear + 1}`} ${t.semester}`;
        } else {
            key = 'Other';
        }
        if (!groups[key]) groups[key] = { transactions: [], assessmentId: null };
        groups[key].transactions.push(t);

        if (groups[key].assessmentId === null && t.year && t.semester) {
            const startYear = parseInt(String(t.year), 10);
            const syEnd = startYear + 1;
            const match = (props.allAssessments ?? []).find(
                (a) =>
                    a.school_year === `${startYear}-${syEnd}` &&
                    String(a.semester).trim() === String(t.semester).trim(),
            );
            groups[key].assessmentId = match?.id ?? null;
        }
    }

    return Object.entries(groups)
        .map(([key, group]) => {
            const totalPaidAmt = group.transactions
                .filter((t) => t.kind === 'payment' && t.status === 'paid')
                .reduce((s, t) => s + parseFloat(t.amount), 0);

            // Use each group's own assessment total, not the selected assessment
            const groupAssessment = group.assessmentId
                ? (props.allAssessments ?? []).find((a) => a.id === group.assessmentId)
                : null;
            const assessmentTotal = parseFloat(
                String(groupAssessment?.total_assessment ?? selectedAssessment.value?.total_assessment ?? props.assessment?.total_assessment ?? 0),
            );

            return {
                key,
                assessmentId: group.assessmentId,
                transactions: group.transactions,
                totalCharges: assessmentTotal,
                totalPaid: totalPaidAmt,
                balance: assessmentTotal - totalPaidAmt,
            };
        })
        .sort((a, b) =>
            parseInt(a.key.split('-')[0] ?? '0', 10) - parseInt(b.key.split('-')[0] ?? '0', 10) >
            0
                ? -1
                : 1,
        );
});

const expandedTerms = ref<Record<string, boolean>>({});
const toggleTerm = (key: string) => {
    expandedTerms.value = { ...expandedTerms.value, [key]: !expandedTerms.value[key] };
};

// ─── Auto-expand helper ───────────────────────────────────────────────────────
function autoExpandCurrentTerm() {
    if (transactionsByTerm.value.length === 0) return;
    const matchKey = currentAssessmentTermKey.value;
    const autoKey =
        matchKey && transactionsByTerm.value.some((g) => g.key === matchKey)
            ? matchKey
            : transactionsByTerm.value[0].key;
    expandedTerms.value = { [autoKey]: true };
}

const currentAssessmentTermKey = computed<string | null>(() => {
    if (!selectedAssessment.value?.school_year || !selectedAssessment.value?.semester)
        return null;
    return `${selectedAssessment.value.school_year} ${selectedAssessment.value.semester}`;
});

const expandedTxSubjectPanels = ref<Set<string>>(new Set());

function toggleTxSubjectPanel(key: string) {
    if (expandedTxSubjectPanels.value.has(key)) {
        expandedTxSubjectPanels.value.delete(key);
    } else {
        expandedTxSubjectPanels.value.add(key);
    }
}

function buildSubjectPanel(a: Assessment) {
    const rawData = (props.enrolledSubjectsByAssessment ?? {})[a.id] ?? [];

    // ── Determine data source ──────────────────────────────────────────────────
    // New assessments: rawData is AssessmentSubjectRow[] (objects with fee fields)
    // Legacy assessments: rawData is number[] (subject IDs from student_enrollments)
    const isSnapshotData =
        rawData.length > 0 && typeof rawData[0] === 'object' && rawData[0] !== null;

    if (isSnapshotData) {
        // ── New path: assessment_subjects snapshot ────────────────────────────
        const snapshotRows = rawData as AssessmentSubjectRow[];

        const subjects = snapshotRows.map((s) => ({
            subject_id:    s.subject_id,
            code:          s.code,
            name:          s.name,
            lecUnits:      s.lec_units,
            labUnits:      s.lab_units,
            tuitionAmount: s.tuition_fee,
            labAmount:     s.lab_fee,
            totalFee:      s.total_fee,
            hasLab:        s.lab_units > 0,
            isNstp:        s.is_nstp,
            isPathfit:     s.is_pathfit,
            isBillable:    s.is_billable,
            nstpUnits:     s.nstp_billing_units,
            // Snapshot subjects are always considered "enrolled" (they were
            // active at assessment creation time)
            isEnrolled:    true,
        }));

        const billable   = subjects.filter((s) => s.isBillable);
        const nstpRows   = subjects.filter((s) => s.isNstp);
        const pathfitRows = subjects.filter((s) => s.isPathfit);

        const totalLecUnits   = billable.reduce((acc, s) => acc + s.lecUnits, 0);
        const totalLabUnits   = billable.reduce((acc, s) => acc + s.labUnits, 0);
        const totalUnits      = subjects.reduce((acc, s) => acc + s.lecUnits + s.labUnits, 0);
        const totalTuitionVal = subjects.reduce((acc, s) => acc + s.tuitionAmount, 0);
        const totalLabVal     = subjects.reduce((acc, s) => acc + s.labAmount, 0);

        return {
            assessmentId: a.id,
            label:        `${a.year_level} — ${a.semester}`,
            schoolYear:   a.school_year,
            course:       a.course ?? '—',
            source:       'snapshot' as const,
            totalLecUnits,
            totalLabUnits,
            totalUnits,
            totalTuition:  Math.round(totalTuitionVal * 100) / 100,
            totalLab:      Math.round(totalLabVal * 100) / 100,
            subjectCount:  subjects.length,
            enrolledCount: subjects.length,
            subjects,
            nstpCount:    nstpRows.length,
            pathfitCount: pathfitRows.length,
        };
    }

    // ── Legacy path: student_enrollments IDs only ─────────────────────────────
    // Pre-snapshot assessments have no per-subject fee data. Reconstruct a
    // minimal display from fee_breakdown aggregate rows.
    const subjectRows = (a.fee_breakdown ?? []).filter(
        (item) => item.category === 'Tuition' || item.category === 'Laboratory',
    );

    const enrolledIds = new Set(rawData as number[]);

    const subjectMap: Record<
        number,
        {
            subject_id: number;
            code: string;
            name: string;
            lecUnits: number;
            labUnits: number;
            tuitionAmount: number;
            labAmount: number;
            totalFee: number;
            hasLab: boolean;
            isNstp: boolean;
            isPathfit: boolean;
            isBillable: boolean;
            nstpUnits: number;
            isEnrolled: boolean;
        }
    > = {};

    for (const row of subjectRows) {
        const sid = row.subject_id;
        if (sid === undefined) continue;

        if (!subjectMap[sid]) {
            subjectMap[sid] = {
                subject_id:    sid,
                code:          row.code ?? '—',
                name:          row.name,
                lecUnits:      0,
                labUnits:      0,
                tuitionAmount: 0,
                labAmount:     0,
                totalFee:      0,
                hasLab:        false,
                isNstp:        false,
                isPathfit:     false,
                isBillable:    true,
                nstpUnits:     0,
                isEnrolled:    enrolledIds.has(sid),
            };
        }

        if (row.category === 'Tuition') {
            subjectMap[sid].tuitionAmount = parseFloat(String(row.amount));
            subjectMap[sid].lecUnits = row.units ?? subjectMap[sid].lecUnits;
            if (!subjectMap[sid].name || subjectMap[sid].name.startsWith('Laboratory')) {
                subjectMap[sid].name = row.name;
            }
        } else if (row.category === 'Laboratory') {
            subjectMap[sid].labAmount = parseFloat(String(row.amount));
            subjectMap[sid].labUnits = row.units ?? 0;
            subjectMap[sid].hasLab = true;
        }
        subjectMap[sid].totalFee = subjectMap[sid].tuitionAmount + subjectMap[sid].labAmount;
    }

    const subjects       = Object.values(subjectMap);
    const totalLecUnits  = subjects.reduce((s, sub) => s + sub.lecUnits, 0);
    const totalLabUnits  = subjects.reduce((s, sub) => s + sub.labUnits, 0);
    const totalUnits     = totalLecUnits + totalLabUnits;
    const totalTuitionVal = subjects.reduce((s, sub) => s + sub.tuitionAmount, 0);
    const totalLabVal    = subjects.reduce((s, sub) => s + sub.labAmount, 0);
    const enrolledCount  = subjects.filter((sub) => sub.isEnrolled).length;

    return {
        assessmentId: a.id,
        label:        `${a.year_level} — ${a.semester}`,
        schoolYear:   a.school_year,
        course:       a.course ?? '—',
        source:       'legacy' as const,
        totalLecUnits,
        totalLabUnits,
        totalUnits,
        totalTuition:  totalTuitionVal,
        totalLab:      totalLabVal,
        subjectCount:  subjects.length,
        enrolledCount,
        subjects,
        nstpCount:    0,
        pathfitCount: 0,
    };
}

const txSubjectPanels = computed(
    (): Record<string, ReturnType<typeof buildSubjectPanel> | null> => {
        const result: Record<string, ReturnType<typeof buildSubjectPanel> | null> = {};
        for (const group of transactionsByTerm.value) {
            if (group.assessmentId === null) {
                result[group.key] = null;
                continue;
            }
            const assessment = (props.allAssessments ?? []).find(
                (a) => a.id === group.assessmentId,
            );
            if (!assessment || !assessment.fee_breakdown?.length) {
                result[group.key] = null;
                continue;
            }
            const panel = buildSubjectPanel(assessment);
            result[group.key] = panel.subjects.length > 0 ? panel : null;
        }
        return result;
    },
);

// ─── Payment form ─────────────────────────────────────────────────────────────

const breadcrumbs = [
    { title: 'Dashboard', href: route('admin.dashboard') },
    { title: 'Student Fee Management', href: route('student-fees.index') },
    { title: props.student.name },
];

const PAYMENT_PAGE_SIZE = 5;
const paymentHistoryLimit = ref(PAYMENT_PAGE_SIZE);

const filteredPayments = computed(() => {
    const selectedId = selectedAssessmentId.value;
    if (!selectedId) return props.payments ?? [];
    return (props.payments ?? []).filter((p: any) => p.assessment_id === selectedId);
});

const visiblePayments = computed(() =>
    filteredPayments.value.slice(0, paymentHistoryLimit.value),
);
const hasMorePayments = computed(
    () => filteredPayments.value.length > paymentHistoryLimit.value,
);
const loadMorePayments = () => {
    paymentHistoryLimit.value += PAYMENT_PAGE_SIZE;
};

const showPaymentDialog = ref(false);

const paymentForm = useForm({
    amount: '',
    payment_method: 'cash',
    assessment_id: null as number | null,
    payment_date: new Date().toISOString().split('T')[0],
    or_number: '',
});

const paymentAmountError = computed(() => {
    const amount = parseFloat(paymentForm.amount) || 0;
    if (amount <= 0 && paymentForm.amount) return 'Amount must be greater than zero';
    if (amount > remainingBalance.value) {
        return `Amount cannot exceed the outstanding balance of ${formatCurrency(remainingBalance.value)}`;
    }
    return '';
});

const projectedRemainingBalance = computed(() =>
    Math.max(0, remainingBalance.value - (parseFloat(paymentForm.amount) || 0)),
);

// ─────────────────────────────────────────────────────────────────────────────
// ALLOCATION PREVIEW — mirrors backend allocatePaymentAcrossTerms() exactly.
//
// Uses integer-cents arithmetic throughout (multiply by 100, floor, never float).
// All rounding happens at toCents() boundary only.
//
// STEP 1: Sequential allocation — apply payment to each term, oldest first.
// STEP 2: Close-and-carry — for any term left with balance after Step 1,
//         close it (balance → 0, processed = true) and record the carry details.
//
// This means: a partial payment on Prelim closes Prelim and the remaining
// balance shows up as a carry annotation, not as a persistent Prelim debt.
// ─────────────────────────────────────────────────────────────────────────────

/** Convert a peso float/string to integer cents. Mirrors PHP MoneyService::roundToCents(). */
const _toCents = (value: number | string): number =>
    Math.round(parseFloat(String(value)) * 100);

/** Convert integer cents back to a peso float. */
const _fromCents = (cents: number): number => cents / 100;

type OtcAllocationRow = {
    name:            string;
    applied:         number;
    balanceAfter:    number;
    willBePaid:      boolean;
    // Carry-forward fields (one-time term processing rule)
    processed:       boolean;        // true = term closed, balance carried forward
    carriedForward:  number;         // peso amount carried to next term
    carriedToTerm:   string | null;  // name of the receiving term
};

const allocationPreview = computed((): OtcAllocationRow[] => {
    const entered = parseFloat(paymentForm.amount) || 0;
    if (entered <= 0) return [];

    const unpaid = [...allTermsSorted.value]
        .filter((t) => parseFloat(String(t.balance)) > 0)
        .sort((a, b) => a.term_order - b.term_order);

    // ── STEP 1: Sequential allocation in integer cents ────────────────────────
    let remainingCents = _toCents(entered);
    const rows: OtcAllocationRow[] = [];

    for (const term of unpaid) {
        if (remainingCents <= 0) break;
        const balBeforeCents = _toCents(term.balance);
        const appliedCents   = Math.min(remainingCents, balBeforeCents);
        const balAfterCents  = balBeforeCents - appliedCents;
        rows.push({
            name:           term.term_name,
            applied:        _fromCents(appliedCents),
            balanceAfter:   _fromCents(balAfterCents),
            willBePaid:     balAfterCents === 0,
            processed:      false,
            carriedForward: 0,
            carriedToTerm:  null,
        });
        remainingCents -= appliedCents;
    }

    // ── STEP 2: Close-and-carry ───────────────────────────────────────────────
    // Any row that still has balance after Step 1 gets closed.
    // The remaining balance is annotated as "carried to next term".
    for (let i = 0; i < rows.length; i++) {
        const row = rows[i];
        if (row.willBePaid || row.balanceAfter <= 0) continue;

        const carryoverCents = _toCents(row.balanceAfter);

        // Find the next term in the full sorted list (beyond this row's term).
        const currentTermObj = unpaid.find((t) => t.term_name === row.name);
        const nextTermObj = currentTermObj
            ? unpaid.find((t) => t.term_order > (currentTermObj.term_order ?? 0))
            : null;

        row.processed      = true;
        row.carriedForward = _fromCents(carryoverCents);
        row.carriedToTerm  = nextTermObj?.term_name ?? null;
        row.balanceAfter   = 0;   // the server will zero this
        row.willBePaid     = false; // processed ≠ paid
    }

    return rows;
});

const canSubmitPayment = computed(
    () =>
        parseFloat(paymentForm.amount) > 0 &&
        !paymentAmountError.value &&
        paymentForm.assessment_id !== null &&
        paymentForm.or_number.trim() !== '' && 
        !paymentForm.processing,
);

const getTermStatusConfig = (status: string) => {
    const map: Record<string, { bg: string; text: string; label: string }> = {
        unpaid:    { bg: 'bg-yellow-100',  text: 'text-yellow-800',  label: 'Unpaid' },
        pending:   { bg: 'bg-yellow-100',  text: 'text-yellow-800',  label: 'Unpaid' },
        partial:   { bg: 'bg-orange-100',  text: 'text-orange-800',  label: 'Partial' },
        paid:      { bg: 'bg-green-100',   text: 'text-green-800',   label: 'Paid' },
        overdue:   { bg: 'bg-red-100',     text: 'text-red-800',     label: 'Overdue' },
        // processed = term closed; balance was carried forward to the next term.
        // Label shown as "Carried Forward" so students/accounting immediately
        // understand what happened without needing to know the internal status name.
        processed: { bg: 'bg-blue-100',    text: 'text-blue-800',    label: 'Carried Forward' },
    };
    return map[status] ?? { bg: 'bg-gray-100', text: 'text-gray-800', label: status };
};

watch(
    () => showPaymentDialog.value,
    (isOpen) => {
        if (isOpen) {
            paymentForm.assessment_id =
                selectedAssessmentId.value ?? props.assessment?.id ?? null;
        }
    },
);

watch(
    () => selectedAssessmentId.value,
    (newId) => {
        paymentForm.assessment_id = newId ?? props.assessment?.id ?? null;
        paymentForm.reset();
        paymentForm.clearErrors();
        autoExpandCurrentTerm();
    },
);

onMounted(() => {
    autoExpandCurrentTerm();
});

// ─── ✅ FIXED submitPayment ──────────────────────────────────────────────────
// Original code used paymentForm.post() which relies on Inertia's internal
// fetch. The 419 was caused by a stale CSRF token after the session expired
// or was refreshed server-side. The fix:
//  1. Use router.visit() to do a fresh Inertia visit before submitting — this
//     forces the XSRF-TOKEN cookie to be refreshed.
//  2. Use the Inertia useForm post with `forceFormData: true` so the CSRF
//     token is re-read from the latest cookie, not a cached value.

const submitPayment = () => {
    if (!canSubmitPayment.value) {
        if (!paymentForm.amount) paymentForm.setError('amount', 'Please enter an amount');
        return;
    }

    paymentForm.post(route('student-fees.payments.store', props.student.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            showPaymentDialog.value = false;
            paymentForm.reset();
            paymentForm.clearErrors();
        },
        onError: (errors) => {
            console.error('Payment errors:', errors);
        },
    });
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

const formatDate = (d: string) =>
    new Date(d).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
const formatDateShort = (d: string) =>
    new Date(d).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });

const toYearRange = (year: string | number | null | undefined): string => {
    if (!year) return '—';
    const y = parseInt(String(year), 10);
    return isNaN(y) ? String(year) : `${y}-${y + 1}`;
};

const getStudentStatusColor = (status: string) => {
    const map: Record<string, string> = {
        active: 'bg-green-100 text-green-800',
        graduated: 'bg-blue-100 text-blue-800',
        dropped: 'bg-red-100 text-red-800',
    };
    return map[status] ?? 'bg-gray-100 text-gray-800';
};

const formatPaymentMethod = (m: string): string => {
    const labels: Record<string, string> = {
        cash: 'Cash',
        gcash: 'GCash',
        bank_transfer: 'Bank Transfer',
        credit_card: 'Credit Card',
        debit_card: 'Debit Card',
        paymaya: 'Maya',
        maya: 'Maya',
        paymongo: 'PayMongo',
    };
    return labels[m?.toLowerCase()] ?? m ?? '—';
};


const paymentMethodBadgeClass = (method: string): string => {
    const m = method?.toLowerCase();
    if (m === 'cash') return 'rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 capitalize';
    return 'rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800 capitalize';
};

// ─── Academic History ───────────────────────────────────────────────────────
// Chronological list of ALL assessments (active + completed), each enriched
// with a subject snapshot, payment term summary, and balance status.
// Used by the Academic History card for accounting staff.

type AcademicHistoryEntry = {
    id:                 number;
    semester:           string;
    school_year:        string;
    year_level:         string;
    course:             string | null;
    status:             string;
    total_assessment:   number;
    tuition_fee:        number;
    lab_fee:            number;
    misc_fee:           number;
    lec_units:          number;
    nstp_lec_units:     number;
    lab_units:          number;
    discount_name:      string | null;
    discount_percentage:number;
    is_taking_nstp:     boolean;
    totalPaid:          number;
    balance:            number;
    fullyPaid:          boolean;
    paymentTerms:       any[];
    enrolled_subjects:  any[];
    subjectCount:       number;
};

const academicHistory = computed<AcademicHistoryEntry[]>(() => {
    const all = (props.allAssessments ?? []) as any[];
    return [...all]
        .sort((a, b) => {
            // Sort oldest first: school_year ASC, then 1st < 2nd < Summer
            const semOrd: Record<string, number> = { '1st': 1, '2nd': 2, 'Summer': 3 };
            if (a.school_year !== b.school_year) return a.school_year.localeCompare(b.school_year);
            return (semOrd[a.semester] ?? 9) - (semOrd[b.semester] ?? 9);
        })
        .map((a) => {
            const terms         = (a.paymentTerms ?? []) as any[];
            const balance       = terms.reduce((s: number, t: any) => s + Math.max(0, Number(t.balance ?? 0)), 0);
            const totalPaid     = Math.max(0, Number(a.total_assessment ?? 0) - balance);
            const enrolled      = (a.enrolled_subjects ?? []) as any[];
            return {
                id:                  a.id,
                semester:            a.semester,
                school_year:         a.school_year,
                year_level:          a.year_level ?? '—',
                course:              a.course ?? null,
                status:              a.status ?? 'unknown',
                total_assessment:    Number(a.total_assessment ?? 0),
                tuition_fee:         Number(a.tuition_fee ?? 0),
                lab_fee:             Number(a.lab_fee ?? 0),
                misc_fee:            Number(a.misc_fee ?? 0),
                lec_units:           Number(a.lec_units ?? 0),
                nstp_lec_units:      Number(a.nstp_lec_units ?? 0),
                lab_units:           Number(a.lab_units ?? 0),
                discount_name:       a.discount_name ?? null,
                discount_percentage: Number(a.discount_percentage ?? 0),
                is_taking_nstp:      Boolean(a.is_taking_nstp),
                totalPaid:           Math.round(totalPaid * 100) / 100,
                balance:             Math.round(balance  * 100) / 100,
                fullyPaid:           balance <= 0,
                paymentTerms:        terms,
                enrolled_subjects:   enrolled,
                subjectCount:        enrolled.length,
            };
        });
});

// Track which semester row is expanded in the Academic History card
const expandedHistoryIds = ref<Set<number>>(new Set());
function toggleHistoryRow(id: number) {
    if (expandedHistoryIds.value.has(id)) {
        expandedHistoryIds.value.delete(id);
    } else {
        expandedHistoryIds.value.add(id);
    }
}

// Grand totals across all semesters — for the summary footer
const academicTotals = computed(() => {
    const list = academicHistory.value;
    return {
        semesters:         list.length,
        totalAssessed:     list.reduce((s, e) => s + e.total_assessment, 0),
        totalPaid:         list.reduce((s, e) => s + e.totalPaid,        0),
        totalBalance:      list.reduce((s, e) => s + e.balance,          0),
        completedCount:    list.filter((e) => e.fullyPaid).length,
    };
});

</script>

<template>
    <Head :title="`Fee Details — ${student.name}`" />

    <AppLayout>
        <div class="w-full space-y-6 p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- ── Header ── -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-4">
                    <Link :href="backUrl">
                        <Button variant="outline" size="sm">
                            <ArrowLeft class="mr-2 h-4 w-4" /> Back
                        </Button>
                    </Link>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">{{ student.name }}</h1>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <select
                        v-if="allAssessments.length > 1"
                        v-model.number="selectedAssessmentId"
                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none"
                        title="Select semester to view"
                    >
                        <option v-for="a in allAssessments" :key="a.id" :value="a.id">
                            {{ a.year_level }} — {{ a.semester }} Sem {{ a.school_year }}
                        </option>
                    </select>
                    <a :href="exportUrl" target="_blank">
                        <Button variant="outline" size="sm">
                            <Download class="mr-2 h-4 w-4" /> Assessment
                        </Button>
                    </a>
                    <a v-if="selectedAssessment" :href="route('student-fees.export-pdf', student.id) + (selectedAssessmentId ? '?assessment_id=' + selectedAssessmentId + '&type=receipt' : '?type=receipt')" target="_blank">
                        <Button variant="outline" size="sm">
                            <Download class="mr-2 h-4 w-4" /> Receipt
                        </Button>
                    </a>
                    <Link v-if="isAccounting" :href="route('student-fees.edit-student', student.student_db_id)">
                        <Button variant="outline" size="sm">
                            <BookOpen class="mr-2 h-4 w-4" /> Edit Info
                        </Button>
                    </Link>
                    <Link v-if="isAccounting && selectedAssessment" :href="route('student-fees.edit', student.id)">
                        <Button variant="outline" size="sm">
                            <BookOpen class="mr-2 h-4 w-4" /> Edit Assessment
                        </Button>
                    </Link>
                    <Dialog v-if="isAccounting" v-model:open="showPaymentDialog">
                        <DialogTrigger as-child>
                            <Button size="sm"
                                ><Plus class="mr-2 h-4 w-4" /> Record Payment</Button
                            >
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Record Payment</DialogTitle>
                                <DialogDescription>
                                    <div class="space-y-1">
                                        <p>
                                            Recording payment for
                                            <strong>{{ student.name }}</strong>
                                        </p>
                                        <p class="text-sm text-gray-500">
                                            {{ selectedAssessment?.year_level }} —
                                            {{ selectedAssessment?.semester }}
                                            {{ selectedAssessment?.school_year }}
                                        </p>
                                        <p class="text-base font-semibold text-slate-900">
                                            Outstanding Balance:
                                            {{ formatCurrency(remainingBalance) }}
                                        </p>
                                    </div>
                                </DialogDescription>
                            </DialogHeader>

                            <div class="max-h-[65vh] space-y-4 overflow-y-auto pr-1">
                                <div class="space-y-2">
                                    <Label for="amount">Amount *</Label>
                                    <Input
                                        id="amount"
                                        v-model="paymentForm.amount"
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        placeholder="0.00"
                                        :class="{ 'border-red-500': paymentAmountError }"
                                    />
                                    <p
                                        v-if="paymentAmountError"
                                        class="text-sm font-medium text-red-500"
                                    >
                                        {{ paymentAmountError }}
                                    </p>
                                    <p v-else class="text-xs text-gray-500">
                                        Enter any amount — payment will be applied sequentially
                                        across outstanding terms.
                                    </p>
                                    <p
                                        v-if="paymentForm.errors.amount"
                                        class="text-sm text-red-500"
                                    >
                                        {{ paymentForm.errors.amount }}
                                    </p>
                                </div>

                                <div class="space-y-2">
                                    <Label>Payment Method</Label>
                                    <div
                                        class="rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm font-medium text-gray-700"
                                    >
                                        💵 Cash (In-Person Payment)
                                    </div>
                                    <p class="text-xs text-gray-500">
                                        All payments are recorded as cash transactions for
                                        in-person payments.
                                    </p>
                                </div>

                                <div class="space-y-2">
                                    <Label for="payment_date">Payment Date *</Label>
                                    <Input
                                        id="payment_date"
                                        v-model="paymentForm.payment_date"
                                        type="date"
                                        required
                                    />
                                    <p
                                        v-if="paymentForm.errors.payment_date"
                                        class="text-sm text-red-500"
                                    >
                                        {{ paymentForm.errors.payment_date }}
                                    </p>
                                </div>

                                <div class="space-y-2">
                                    <Label for="or_number">OR Number *</Label>
                                    <Input
                                        id="or_number"
                                        v-model="paymentForm.or_number"
                                        type="text"
                                        placeholder="e.g. 2025-00123"
                                        :class="{ 'border-red-500': paymentForm.errors.or_number }"
                                    />
                                    <p v-if="paymentForm.errors.or_number" class="text-sm text-red-500">
                                        {{ paymentForm.errors.or_number }}
                                    </p>
                                    <p v-else class="text-xs text-gray-500">
                                        Enter the Official Receipt number from the cashier.
                                    </p>
                                </div>

                                <div
                                    v-if="allocationPreview.length > 0"
                                    class="overflow-hidden rounded-lg border border-indigo-200 bg-indigo-50 text-sm"
                                >
                                    <div
                                        class="flex items-center justify-between border-b border-indigo-200 bg-indigo-100 px-4 py-2"
                                    >
                                        <p
                                            class="text-xs font-semibold tracking-wide text-indigo-700 uppercase"
                                        >
                                            Allocation Preview
                                        </p>
                                        <p class="text-xs text-indigo-600">
                                            Applied oldest term first
                                        </p>
                                    </div>
                                    <div class="divide-y divide-indigo-100">
                                        <div
                                            v-for="row in allocationPreview"
                                            :key="row.name"
                                            class="px-4 py-2.5"
                                            :class="{
                                                'bg-green-50': row.willBePaid,
                                                'bg-blue-50':  row.processed,
                                            }"
                                        >
                                            <!-- Main row: icon + name + applied amount -->
                                            <div class="flex items-center justify-between">
                                                <div class="flex items-center gap-2">
                                                    <span
                                                        :class="[
                                                            'inline-flex h-5 w-5 flex-shrink-0 items-center justify-center rounded-full text-xs font-bold',
                                                            row.willBePaid
                                                                ? 'bg-green-100 text-green-700'
                                                                : row.processed
                                                                  ? 'bg-blue-100 text-blue-700'
                                                                  : 'bg-amber-100 text-amber-700',
                                                        ]"
                                                    >
                                                        {{ row.willBePaid ? '✓' : row.processed ? '→' : '~' }}
                                                    </span>
                                                    <div>
                                                        <p class="font-medium text-gray-900">
                                                            {{ row.name }}
                                                        </p>
                                                        <p class="text-xs text-gray-500">
                                                            <!-- Fully paid: balance zeroed -->
                                                            <span v-if="row.willBePaid" class="font-semibold text-green-600">
                                                                Fully paid · ₱0.00 remaining
                                                            </span>
                                                            <!-- Processed: closed via one-time rule, carry forward -->
                                                            <span v-else-if="row.processed" class="text-blue-600">
                                                                Processed · balance carried to
                                                                <strong>{{ row.carriedToTerm ?? 'next term' }}</strong>
                                                            </span>
                                                            <!-- Still has balance remaining (last active term) -->
                                                            <span v-else class="text-amber-600">
                                                                Balance after: {{ formatCurrency(row.balanceAfter) }}
                                                            </span>
                                                        </p>
                                                    </div>
                                                </div>
                                                <span class="font-semibold text-indigo-700">
                                                    {{ formatCurrency(row.applied) }}
                                                </span>
                                            </div>
                                            <!-- Carry-forward detail line -->
                                            <div
                                                v-if="row.processed && row.carriedForward > 0"
                                                class="mt-1.5 ml-7 flex items-center gap-1 text-xs text-blue-600"
                                            >
                                                <span>↪</span>
                                                <span>
                                                    {{ formatCurrency(row.carriedForward) }} moved to
                                                    <strong>{{ row.carriedToTerm ?? 'next term' }}</strong>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div
                                        class="flex items-center justify-between border-t border-indigo-200 bg-indigo-100 px-4 py-2"
                                    >
                                        <div>
                                            <p class="text-xs font-semibold text-indigo-800">
                                                Total Applied
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-bold text-indigo-800">
                                                {{
                                                    formatCurrency(
                                                        parseFloat(paymentForm.amount) || 0,
                                                    )
                                                }}
                                            </p>
                                            <p class="text-xs text-indigo-600">
                                                Balance after:
                                                {{ formatCurrency(projectedRemainingBalance) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <p
                                    v-if="paymentForm.errors.payment"
                                    class="text-sm font-medium text-red-600"
                                >
                                    {{ paymentForm.errors.payment }}
                                </p>

                                <p
                                    v-if="(paymentForm.errors as any).error"
                                    class="text-sm font-medium text-red-600"
                                >
                                    {{ (paymentForm.errors as any).error }}
                                </p>
                            </div>

                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    @click="showPaymentDialog = false"
                                    >Cancel</Button
                                >
                                <Button
                                    type="button"
                                    :disabled="!canSubmitPayment"
                                    :class="{ 'cursor-not-allowed opacity-50': !canSubmitPayment }"
                                    @click="submitPayment"
                                >
                                    <span v-if="paymentForm.processing">Recording…</span>
                                    <span v-else>Record Payment</span>
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>

            <!-- ── Personal Info ── -->
            <Card>
                <CardHeader><CardTitle>Personal Information</CardTitle></CardHeader>
                <CardContent>
                    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                        <div>
                            <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Full Name</Label>
                            <p class="mt-0.5 font-medium text-foreground">{{ student.name }}</p>
                        </div>
                        <div>
                            <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Email</Label>
                            <p class="mt-0.5 font-medium text-foreground">{{ student.email }}</p>
                        </div>
                        <div>
                            <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Birthday</Label>
                            <p class="mt-0.5 font-medium text-foreground">
                                {{ student.birthday ? formatDate(student.birthday) : 'N/A' }}
                            </p>
                        </div>
                        <div>
                            <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Phone</Label>
                            <p class="mt-0.5 font-medium text-foreground">
                                {{ student.phone || 'N/A' }}
                            </p>
                        </div>
                        <div>
                            <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Account ID</Label>
                            <p class="mt-0.5 font-mono text-sm font-medium text-foreground">
                                {{ student.account_id }}
                            </p>
                        </div>
                        <div>
                            <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Course</Label>
                            <p class="mt-0.5 font-medium text-foreground">{{ student.course }}</p>
                        </div>
                        <div>
                            <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Year Level</Label>
                            <p class="mt-0.5 font-medium text-foreground">
                                {{ assessment?.year_level || student.year_level }}
                            </p>
                        </div>
                        <div>
                            <Label class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Status</Label>
                            <div class="mt-0.5 flex flex-wrap items-center gap-2">
                                <span
                                    class="inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="getStudentStatusColor(student.status)"
                                    >{{ student.status }}</span
                                >
                                <span
                                    :class="[
                                        'inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                        student.is_irregular
                                            ? 'bg-amber-100 text-amber-700'
                                            : 'bg-blue-100 text-blue-700',
                                    ]"
                                >
                                    {{ student.is_irregular ? 'Irregular' : 'Regular' }}
                                </span>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- ── Fee Breakdown ── -->
            <Card>
                <CardHeader>
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <CardTitle>Fee Breakdown</CardTitle>
                            <CardDescription class="mt-1 flex flex-col gap-1">
                                <span class="inline-block">
                                    Assessment for
                                    <strong>{{ selectedAssessment?.year_level }}</strong> —
                                    <strong>{{ selectedAssessment?.semester }}</strong>
                                    ({{ selectedAssessment?.school_year }})
                                </span>
                                <span
                                    v-if="feeCalculationSummary"
                                    class="inline-block w-fit rounded bg-indigo-50 px-2 py-1 font-mono text-xs text-indigo-600"
                                >
                                    {{ feeCalculationSummary }}
                                </span>
                                <span
                                    v-if="discountLabel"
                                    class="inline-flex w-fit items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                                    {{ discountLabel }}
                                </span>
                            </CardDescription>
                        </div>
                        <div v-if="assessment?.course" class="ml-4 flex-shrink-0 text-right">
                            <span class="text-xs font-semibold text-gray-600">Course:</span>
                            <span
                                class="ml-2 rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700"
                                >{{ assessment.course }}</span
                            >
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div
                        class="flex items-center justify-between rounded-xl border border-border bg-card p-4"
                    >
                        <div>
                            <p class="font-semibold text-foreground">Tuition Fees</p>
                        </div>
                        <span class="text-lg font-bold text-indigo-600">{{
                            formatCurrency(totalTuition)
                        }}</span>
                    </div>

                    <div
                        class="flex items-center justify-between rounded-xl border border-border bg-card p-4"
                    >
                        <div>
                            <p class="font-semibold text-foreground">Laboratory Fees</p>
                        </div>
                        <span class="text-lg font-bold text-purple-600">{{
                            formatCurrency(totalLab)
                        }}</span>
                    </div>

                    <div
                        class="flex items-center justify-between rounded-xl border border-border bg-card p-4"
                    >
                        <div>
                            <p class="font-semibold text-foreground">Miscellaneous Fees</p>
                        </div>
                        <span class="text-lg font-bold text-amber-600">{{
                            formatCurrency(totalMiscellaneous)
                        }}</span>
                    </div>

                    <div class="flex items-center justify-between border-t-2 border-gray-200 px-1 pt-3">
                        <span class="text-lg font-bold text-gray-900">Total Assessment</span>
                        <span class="text-2xl font-extrabold text-indigo-600">{{
                            formatCurrency(totalAssessment)
                        }}</span>
                    </div>

                    <div class="space-y-1 px-1">
                        <div class="flex justify-between text-xs text-gray-500">
                            <span>Payment Progress</span>
                            <span>{{
                                totalAssessment > 0
                                    ? Math.round((totalPaid / totalAssessment) * 100)
                                    : 0
                            }}%</span>
                        </div>
                        <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-200">
                            <div
                                class="h-2.5 rounded-full transition-all duration-500"
                                :class="
                                    paymentTimingStatus === 'behind'
                                        ? 'bg-red-500'
                                        : paymentTimingStatus === 'on_track'
                                          ? 'bg-blue-500'
                                          : paymentTimingStatus === 'no_due_date'
                                            ? 'bg-gray-400'
                                            : 'bg-green-500'
                                "
                                :style="{
                                    width:
                                        totalAssessment > 0
                                            ? `${Math.min(100, (totalPaid / totalAssessment) * 100)}%`
                                            : '0%',
                                }"
                            ></div>
                        </div>
                        <div class="flex justify-between pt-0.5 text-xs text-gray-500">
                            <span
                                >Paid:
                                <strong class="text-green-600">{{
                                    formatCurrency(totalPaid)
                                }}</strong></span
                            >
                            <span
                                >Remaining:
                                <strong
                                    :class="
                                        paymentTimingStatus === 'paid'
                                            ? 'text-green-600'
                                            : 'text-red-600'
                                    "
                                    >{{ formatCurrency(remainingBalance) }}</strong
                                ></span
                            >
                        </div>
                    </div>

                    <div :class="['mt-2 rounded-xl border-2 p-4', balanceCardConfig.bg]">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm" :class="balanceCardConfig.labelColor">
                                    Remaining Balance
                                </p>
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-bold"
                                    :class="balanceCardConfig.badge.cls"
                                    >{{ balanceCardConfig.badge.label }}</span
                                >
                            </div>
                            <p
                                class="mt-0.5 text-3xl font-extrabold"
                                :class="balanceCardConfig.amountColor"
                            >
                                {{ formatCurrency(remainingBalance) }}
                            </p>
                            <p
                                v-if="assessment?.paymentTerms?.length"
                                class="mt-1 text-xs"
                                :class="balanceCardConfig.labelColor"
                            >
                                {{ paidTermsCount }} of {{ allTermsSorted.length }} terms paid
                            </p>
                        </div>
                    </div>

                    <div v-if="allTermsSorted.length > 0" class="space-y-2 pt-1">
                        <p
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Payment Terms
                        </p>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-5">
                            <div
                                v-for="term in allTermsSorted"
                                :key="term.id"
                                :class="[
                                    'rounded-xl border p-3 text-center text-xs transition-all',
                                    term.status === 'paid'
                                        ? 'border-emerald-200 bg-emerald-50'
                                        : term.status === 'partial'
                                          ? 'border-amber-200 bg-amber-50'
                                          : term.status === 'overdue'
                                            ? 'border-red-200 bg-red-50'
                                            : 'border-border bg-muted/30',
                                ]"
                            >
                                <p class="truncate font-semibold text-foreground">
                                    {{ term.term_name }}
                                </p>
                                <!-- Original assessed amount — never changes after assessment creation -->
                                <p class="mt-1 font-bold tabular-nums text-foreground">
                                    {{ formatCurrency(parseFloat(String(term.amount))) }}
                                </p>
                                <!-- Remaining balance for this term — shown only when not fully paid -->
                                <!-- Balance shown only when the term is not closed (paid or processed).     -->
                                <!-- processed terms have balance = 0 — showing "Balance: ₱0.00" is redundant. -->
                                <p
                                    v-if="term.status !== 'paid' && term.status !== 'processed'"
                                    class="mt-0.5 tabular-nums"
                                    :class="
                                        term.status === 'overdue'
                                            ? 'text-red-500'
                                            : term.status === 'partial'
                                              ? 'text-amber-600'
                                              : 'text-gray-500'
                                    "
                                >
                                    Balance: {{ formatCurrency(parseFloat(String(term.balance))) }}
                                </p>
                                <!-- Carry-over annotation: shown on processed terms (source) ─────────── -->
                                <p
                                    v-if="term.status === 'processed' && term.remarks"
                                    class="mt-0.5 text-xs text-blue-600"
                                >
                                    ↪ {{ term.remarks }}
                                </p>
                                <!-- Carry-over annotation: shown on receiving terms ──────────────────── -->
                                <p
                                    v-if="term.carryover_amount && parseFloat(String(term.carryover_amount)) > 0"
                                    class="mt-0.5 text-xs text-blue-500"
                                >
                                    Includes {{ formatCurrency(parseFloat(String(term.carryover_amount))) }} carry-over
                                </p>
                                <span
                                    :class="[
                                        'mt-1.5 inline-block rounded-full px-2 py-0.5 font-semibold',
                                        getTermStatusConfig(term.status).bg,
                                        getTermStatusConfig(term.status).text,
                                    ]"
                                >
                                    {{ getTermStatusConfig(term.status).label }}
                                </span>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- ── Academic History ─────────────────────────────────────────── -->
            <!-- Shows ALL assessments (active + completed) for this student.    -->
            <!-- Each row is collapsible: expands to show the subject snapshot   -->
            <!-- and the payment term breakdown for that semester.               -->
            <Card v-if="isAccounting || isAdmin">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle class="flex items-center gap-2">
                                <GraduationCap class="h-5 w-5 text-blue-600" />
                                Academic History
                            </CardTitle>
                            <CardDescription class="mt-1">
                                {{ academicHistory.length }} semester{{ academicHistory.length !== 1 ? 's' : '' }} on record
                                &mdash;
                                {{ academicTotals.completedCount }} fully paid,
                                {{ academicHistory.length - academicTotals.completedCount }} with balance
                            </CardDescription>
                        </div>
                        <!-- Grand-total summary chips -->
                        <div class="hidden gap-4 text-right sm:flex">
                            <div>
                                <p class="text-xs text-gray-400">Total Assessed</p>
                                <p class="text-sm font-bold text-gray-800">{{ formatCurrency(academicTotals.totalAssessed) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-400">Total Paid</p>
                                <p class="text-sm font-bold text-green-700">{{ formatCurrency(academicTotals.totalPaid) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-400">Outstanding</p>
                                <p class="text-sm font-bold" :class="academicTotals.totalBalance > 0 ? 'text-red-600' : 'text-green-700'">
                                    {{ formatCurrency(academicTotals.totalBalance) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </CardHeader>

                <CardContent class="p-0">

                    <!-- Empty state -->
                    <div v-if="academicHistory.length === 0" class="flex flex-col items-center justify-center py-14 text-gray-400">
                        <History class="mb-3 h-10 w-10 opacity-30" />
                        <p class="text-sm font-medium">No assessments on record</p>
                        <p class="mt-1 text-xs">Create an assessment to start tracking this student's academic history.</p>
                    </div>

                    <!-- Semester rows -->
                    <div v-else class="divide-y">
                        <div
                            v-for="entry in academicHistory"
                            :key="entry.id"
                            class="overflow-hidden"
                        >
                            <!-- ── Row header — click to expand ── -->
                            <button
                                type="button"
                                class="w-full cursor-pointer select-none px-5 py-4 text-left transition-colors hover:bg-gray-50/70"
                                @click="toggleHistoryRow(entry.id)"
                            >
                                <div class="flex items-center justify-between gap-4">

                                    <!-- Left: semester label + badges -->
                                    <div class="flex min-w-0 flex-1 flex-col gap-1.5 sm:flex-row sm:items-center sm:gap-3">
                                        <!-- Status dot -->
                                        <span
                                            class="mt-1 h-2.5 w-2.5 flex-shrink-0 rounded-full sm:mt-0"
                                            :class="entry.fullyPaid ? 'bg-green-400' : 'bg-amber-400'"
                                        />

                                        <!-- Semester + school year -->
                                        <div>
                                            <p class="font-semibold text-gray-900">
                                                {{ entry.semester === '1st' ? '1st Semester' : entry.semester === '2nd' ? '2nd Semester' : 'Summer' }}
                                                <span class="ml-1 text-gray-500 font-normal">· SY {{ entry.school_year }}</span>
                                            </p>
                                            <p class="text-xs text-gray-400">
                                                {{ entry.year_level }}
                                                <span v-if="entry.course"> · {{ entry.course }}</span>
                                            </p>
                                        </div>

                                        <!-- Chips row -->
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <!-- Fully-paid / has-balance -->
                                            <span
                                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                                :class="entry.fullyPaid
                                                    ? 'bg-green-100 text-green-700'
                                                    : 'bg-amber-100 text-amber-700'"
                                            >
                                                {{ entry.fullyPaid ? 'Fully Paid' : `Balance: ${formatCurrency(entry.balance)}` }}
                                            </span>

                                            <!-- Discount -->
                                            <span
                                                v-if="entry.discount_percentage > 0"
                                                class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200"
                                            >
                                                {{ entry.discount_name ? `${entry.discount_name} (${entry.discount_percentage}%)` : `${entry.discount_percentage}% discount` }}
                                            </span>

                                            <!-- NSTP -->
                                            <span
                                                v-if="entry.is_taking_nstp"
                                                class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-amber-200"
                                            >
                                                NSTP
                                            </span>

                                            <!-- Subject count -->
                                            <span
                                                v-if="entry.subjectCount > 0"
                                                class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-600"
                                            >
                                                {{ entry.subjectCount }} subject{{ entry.subjectCount !== 1 ? 's' : '' }}
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Right: assessment total + chevron -->
                                    <div class="flex flex-shrink-0 items-center gap-4 text-right">
                                        <div>
                                            <p class="text-xs text-gray-400">Assessment</p>
                                            <p class="text-sm font-bold text-gray-800">{{ formatCurrency(entry.total_assessment) }}</p>
                                        </div>
                                        <ChevronDown
                                            class="h-4 w-4 text-gray-400 transition-transform duration-200"
                                            :class="{ 'rotate-180': expandedHistoryIds.has(entry.id) }"
                                        />
                                    </div>
                                </div>
                            </button>

                            <!-- ── Expanded detail ── -->
                            <div
                                v-if="expandedHistoryIds.has(entry.id)"
                                class="border-t bg-gray-50/60 px-5 pb-5 pt-4"
                            >
                                <div class="grid gap-5 md:grid-cols-2">

                                    <!-- Fee summary -->
                                    <div class="rounded-lg border bg-white p-4 shadow-sm">
                                        <p class="mb-3 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            <ReceiptText class="h-3.5 w-3.5" /> Fee Summary
                                        </p>
                                        <div class="space-y-1.5 text-sm">
                                            <div class="flex justify-between">
                                                <span class="text-gray-500">
                                                    Tuition
                                                    <span class="text-xs text-gray-400">
                                                        ({{ entry.lec_units + entry.nstp_lec_units }} lec units)
                                                    </span>
                                                </span>
                                                <span class="font-medium">{{ formatCurrency(entry.tuition_fee) }}</span>
                                            </div>
                                            <div v-if="entry.discount_percentage > 0" class="flex justify-between text-xs text-emerald-600 pl-2">
                                                <span>
                                                    incl. {{ entry.discount_name ?? 'Discount' }} ({{ entry.discount_percentage }}% off)
                                                </span>
                                            </div>
                                            <div class="flex justify-between">
                                                <span class="text-gray-500">
                                                    Lab. Fee
                                                    <span class="text-xs text-gray-400">({{ entry.lab_units }} subjects)</span>
                                                </span>
                                                <span class="font-medium">{{ formatCurrency(entry.lab_fee) }}</span>
                                            </div>
                                            <div class="flex justify-between">
                                                <span class="text-gray-500">Misc. Fee</span>
                                                <span class="font-medium">{{ formatCurrency(entry.misc_fee) }}</span>
                                            </div>
                                            <div class="mt-1 flex justify-between border-t pt-2 font-bold">
                                                <span>Total Assessment</span>
                                                <span class="text-blue-700">{{ formatCurrency(entry.total_assessment) }}</span>
                                            </div>
                                            <div class="flex justify-between text-xs">
                                                <span class="text-gray-500">Paid</span>
                                                <span class="font-semibold text-green-700">{{ formatCurrency(entry.totalPaid) }}</span>
                                            </div>
                                            <div class="flex justify-between text-xs">
                                                <span class="text-gray-500">Remaining</span>
                                                <span
                                                    class="font-semibold"
                                                    :class="entry.balance > 0 ? 'text-red-600' : 'text-green-700'"
                                                >
                                                    {{ formatCurrency(entry.balance) }}
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Payment terms mini-table -->
                                        <div v-if="entry.paymentTerms.length" class="mt-4">
                                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">Payment Schedule</p>
                                            <div class="space-y-1">
                                                <div
                                                    v-for="term in entry.paymentTerms"
                                                    :key="term.id"
                                                    class="flex items-center justify-between rounded px-2 py-1.5 text-xs"
                                                    :class="{
                                                        'bg-green-50 text-green-800':  term.status === 'paid',
                                                        'bg-blue-50  text-blue-800':   term.status === 'processed',
                                                        'bg-amber-50 text-amber-800':  term.status === 'partial',
                                                        'bg-gray-50  text-gray-600':   ['pending','unpaid'].includes(term.status),
                                                        'bg-red-50   text-red-700':    term.status === 'overdue',
                                                    }"
                                                >
                                                    <span class="font-medium">{{ term.term_name }}</span>
                                                    <span class="flex items-center gap-2">
                                                        <span>{{ formatCurrency(term.amount) }}</span>
                                                        <span
                                                            class="rounded-full px-1.5 py-0.5 text-xs font-semibold capitalize"
                                                            :class="{
                                                                'bg-green-200 text-green-900':  term.status === 'paid',
                                                                'bg-blue-200  text-blue-900':   term.status === 'processed',
                                                                'bg-amber-200 text-amber-900':  term.status === 'partial',
                                                                'bg-gray-200  text-gray-700':   ['pending','unpaid'].includes(term.status),
                                                                'bg-red-200   text-red-800':    term.status === 'overdue',
                                                            }"
                                                        >
                                                            {{ term.status === 'processed' ? 'Carried Fwd' : term.status }}
                                                        </span>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Subject snapshot -->
                                    <div class="rounded-lg border bg-white shadow-sm overflow-hidden">
                                        <div class="px-4 py-3 border-b bg-gray-50 flex items-center gap-2">
                                            <BookOpen class="h-3.5 w-3.5 text-blue-500" />
                                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                Enrolled Subjects
                                                <span v-if="entry.subjectCount" class="ml-1 font-bold text-blue-600">
                                                    ({{ entry.subjectCount }})
                                                </span>
                                            </p>
                                        </div>

                                        <!-- No snapshot available -->
                                        <div
                                            v-if="entry.enrolled_subjects.length === 0"
                                            class="flex flex-col items-center justify-center py-8 text-gray-400 text-xs"
                                        >
                                            <BookOpen class="mb-2 h-6 w-6 opacity-30" />
                                            <p>No subject snapshot</p>
                                            <p class="mt-0.5 opacity-70">Assessment predates per-subject records</p>
                                        </div>

                                        <!-- Subject rows -->
                                        <div v-else class="overflow-auto max-h-72">
                                            <table class="w-full text-xs">
                                                <thead class="sticky top-0 bg-gray-50 text-gray-500 uppercase tracking-wide">
                                                    <tr>
                                                        <th class="px-3 py-2 text-left font-semibold">Code</th>
                                                        <th class="px-3 py-2 text-left font-semibold">Subject</th>
                                                        <th class="px-2 py-2 text-center font-semibold w-10">Lec</th>
                                                        <th class="px-2 py-2 text-center font-semibold w-10">Lab</th>
                                                        <th class="px-2 py-2 text-right font-semibold w-20">Fee</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-100">
                                                    <tr
                                                        v-for="subj in entry.enrolled_subjects"
                                                        :key="subj.subject_id ?? subj.code"
                                                        :class="{
                                                            'bg-amber-50/70': subj.is_nstp,
                                                            'bg-sky-50/50':   subj.is_pathfit,
                                                            'bg-white':       !subj.is_nstp && !subj.is_pathfit,
                                                        }"
                                                    >
                                                        <td class="px-3 py-2 font-mono font-semibold text-gray-700">{{ subj.code }}</td>
                                                        <td class="px-3 py-2 text-gray-800 max-w-[160px] truncate" :title="subj.name">
                                                            {{ subj.name }}
                                                            <span v-if="subj.is_nstp" class="ml-1 text-amber-600 font-bold text-xs">NSTP</span>
                                                            <span v-else-if="subj.is_pathfit" class="ml-1 text-sky-600 font-bold text-xs">PATHFIT</span>
                                                        </td>
                                                        <td class="px-2 py-2 text-center font-mono text-gray-700">
                                                            <template v-if="subj.is_nstp && subj.nstp_billing_units !== subj.lec_units">
                                                                <span class="text-amber-700 font-semibold">{{ subj.nstp_billing_units }}</span>
                                                                <span class="block text-gray-400 font-normal" style="font-size:10px">({{ subj.lec_units }} acad.)</span>
                                                            </template>
                                                            <template v-else>{{ subj.lec_units || '—' }}</template>
                                                        </td>
                                                        <td class="px-2 py-2 text-center font-mono text-gray-700">{{ subj.lab_units || '—' }}</td>
                                                        <td class="px-2 py-2 text-right font-mono text-gray-700">
                                                            <template v-if="subj.is_billable">
                                                                {{ formatCurrency(subj.total_fee) }}
                                                            </template>
                                                            <span v-else class="text-gray-300">—</span>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                                <!-- Totals footer -->
                                                <tfoot class="border-t-2 border-gray-200 bg-gray-50">
                                                    <tr>
                                                        <td colspan="2" class="px-3 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wide">
                                                            Totals
                                                        </td>
                                                        <td class="px-2 py-2 text-center font-mono font-bold text-blue-700">
                                                            {{ entry.lec_units + entry.nstp_lec_units }}
                                                        </td>
                                                        <td class="px-2 py-2 text-center font-mono font-bold text-gray-700">
                                                            {{ entry.lab_units }}
                                                        </td>
                                                        <td class="px-2 py-2 text-right font-mono font-bold text-blue-700">
                                                            {{ formatCurrency(entry.total_assessment - entry.misc_fee) }}
                                                        </td>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>

                                </div><!-- /grid -->
                            </div><!-- /expanded detail -->
                        </div><!-- /v-for entry -->
                    </div><!-- /semester rows -->

                </CardContent>
            </Card>

            <!-- ── Payment History ── -->
            <Card>
                <CardHeader>
                    <CardTitle>Payment History</CardTitle>
                    <CardDescription>
                        {{ filteredPayments.length }} payment(s) for
                        {{ selectedAssessment?.year_level }} — {{ selectedAssessment?.semester }}
                        {{ selectedAssessment?.school_year }}
                        <span
                            v-if="(payments ?? []).length > filteredPayments.length"
                            class="mt-1 block text-xs text-gray-500"
                        >
                            ({{ (payments ?? []).length }} total across all assessments)
                        </span>
                    </CardDescription>
                </CardHeader>
                <CardContent class="p-0">
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead class="border-b border-gray-200 bg-gray-50">
                                <tr>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-gray-500 uppercase"
                                    >
                                        Date
                                    </th>
                                    <th 
                                        class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-gray-500 uppercase">
                                        OR / Ref No.
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-gray-500 uppercase"
                                    >
                                        Method
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-gray-500 uppercase"
                                    >
                                        Description
                                    </th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-semibold tracking-wider text-gray-500 uppercase"
                                    >
                                        Year & Sem
                                    </th>
                                    <th
                                        class="px-6 py-3 text-right text-xs font-semibold tracking-wider text-gray-500 uppercase"
                                    >
                                        Amount
                                    </th>
                                    <th
                                        class="px-6 py-3 text-center text-xs font-semibold tracking-wider text-gray-500 uppercase"
                                    >
                                        Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="filteredPayments.length === 0">
                                    <td
                                        colspan="7"
                                        class="px-6 py-10 text-center text-gray-400"
                                    >
                                        <CreditCard class="mx-auto mb-2 h-8 w-8 opacity-30" />
                                        <p>No payment history found</p>
                                    </td>
                                </tr>
                                <tr
                                    v-for="payment in visiblePayments"
                                    :key="payment.id"
                                    class="transition-colors hover:bg-gray-50"
                                >
                                    <td
                                        class="px-6 py-3 text-sm whitespace-nowrap text-gray-600"
                                    >
                                        {{ formatDateShort(payment.paid_at) }}
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <span class="font-mono text-xs text-gray-700">
                                            {{ payment.payment_method?.toLowerCase() === 'cash'
                                                ? (payment.or_number ?? '—')
                                                : (payment.system_reference ?? '—') }}
                                        </span>
                                        <p class="mt-0.5 text-xs text-gray-400">
                                            {{ payment.payment_method?.toLowerCase() === 'cash' ? 'OR No.' : 'Ref No.' }}
                                        </p>
                                    </td>
                                    <td class="px-6 py-3 whitespace-nowrap">
                                        <span
                                            :class="paymentMethodBadgeClass(payment.payment_method)"
                                            >{{ formatPaymentMethod(payment.payment_method) }}</span
                                        >
                                    </td>
                                    <td class="px-6 py-3 text-sm text-gray-600">
                                        {{ payment.description }}
                                    </td>
                                    <td class="px-6 py-3 text-sm whitespace-nowrap">
                                        <div v-if="payment.school_year || payment.semester">
                                            <p class="font-medium text-gray-800">
                                                {{ payment.school_year }}
                                            </p>
                                            <p class="text-xs text-gray-500">
                                                {{ payment.semester }}
                                            </p>
                                        </div>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                    <td
                                        class="px-6 py-3 text-right text-sm font-semibold whitespace-nowrap text-green-600"
                                    >
                                        + {{ formatCurrency(payment.amount) }}
                                    </td>
                                    <td class="px-6 py-3 text-center whitespace-nowrap">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="
                                                payment.status === 'completed'
                                                    ? 'bg-green-100 text-green-800'
                                                    : 'bg-yellow-100 text-yellow-800'
                                            "
                                        >
                                            {{ payment.status }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="hasMorePayments" class="border-t px-6 py-3 text-center">
                        <button
                            type="button"
                            class="text-sm font-medium text-blue-600 transition-colors hover:text-blue-800 hover:underline"
                            @click="loadMorePayments"
                        >
                            See More ({{ filteredPayments.length - paymentHistoryLimit }} remaining)
                        </button>
                    </div>
                </CardContent>
            </Card>

            <!-- ── Transaction Ledger ── -->
            <div>
                <div class="mb-3 flex items-center justify-between px-1">
                    <div>
                        <h2 class="text-xl font-bold text-gray-900">Transaction Ledger</h2>
                        <p class="text-sm text-gray-500">
                            All payment transactions grouped by term
                        </p>
                    </div>
                </div>

                <div
                    v-if="transactionsByTerm.length === 0"
                    class="rounded-xl border bg-white p-10 text-center text-gray-400"
                >
                    <AlertCircle class="mx-auto mb-2 h-8 w-8 opacity-30" />
                    <p>No payment transactions found</p>
                </div>

                <div
                    v-for="group in transactionsByTerm"
                    :key="group.key"
                    class="mb-4 overflow-hidden rounded-xl border bg-white shadow-sm"
                >
                    <div
                        class="flex cursor-pointer items-center justify-between p-5 transition-colors select-none hover:bg-gray-50"
                        @click="toggleTerm(group.key)"
                    >
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">{{ group.key }}</h3>
                            <p class="mt-0.5 text-sm text-gray-400">
                                {{ group.transactions.length }} transaction{{
                                    group.transactions.length !== 1 ? 's' : ''
                                }}
                            </p>
                        </div>
                        <div class="flex items-center gap-8 text-right md:gap-12">
                            <div>
                                <p class="text-xs text-gray-400">Total Assessed</p>
                                <p class="text-sm font-bold text-red-600">
                                    {{ formatCurrency(group.totalCharges) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-400">Total Paid</p>
                                <p class="text-sm font-bold text-green-600">
                                    {{ formatCurrency(group.totalPaid) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs text-gray-400">Balance</p>
                                <p
                                    class="text-sm font-bold"
                                    :class="
                                        group.balance > 0 ? 'text-red-600' : 'text-green-600'
                                    "
                                >
                                    {{ formatCurrency(Math.abs(group.balance)) }}
                                </p>
                            </div>
                            <ChevronDown
                                class="h-5 w-5 text-gray-400 transition-transform"
                                :class="{ 'rotate-180': expandedTerms[group.key] }"
                            />
                        </div>
                    </div>

                    <div v-if="expandedTerms[group.key]" class="border-t">
                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse text-left">
                                <thead>
                                    <tr class="bg-gray-50 text-xs text-gray-500 uppercase">
                                        <th class="px-4 py-3 font-semibold">Date</th>
                                        <th class="px-4 py-3 font-semibold">OR / Ref No.</th>
                                        <th class="px-4 py-3 font-semibold">Method</th>
                                        <th class="px-4 py-3 font-semibold">Category</th>
                                        <th class="px-4 py-3 font-semibold">Year & Semester</th>
                                        <th class="px-4 py-3 font-semibold">Amount</th>
                                        <th class="px-4 py-3 font-semibold">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="t in group.transactions"
                                        :key="t.id"
                                        class="border-b border-gray-100 transition-colors hover:bg-gray-50"
                                    >
                                        <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">
                                            {{ formatDateShort(t.created_at) }}
                                        </td>
                                        <td class="px-4 py-3 font-mono text-xs text-gray-700 whitespace-nowrap">
                                            {{ (t.payment_channel ?? '').toLowerCase() === 'cash'
                                                ? (t.or_number ?? '—')
                                                : (t.reference ?? '—') }}
                                            <p class="mt-0.5 font-sans text-xs text-gray-400">
                                                {{ (t.payment_channel ?? '').toLowerCase() === 'cash' ? 'OR No.' : 'Ref No.' }}
                                            </p>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span :class="paymentMethodBadgeClass(t.payment_channel ?? '')">
                                                {{ formatPaymentMethod(t.payment_channel ?? '') }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-700">
                                            {{ t.type }}
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <div v-if="t.year || t.semester">
                                                <p class="font-medium text-gray-800">
                                                    {{ toYearRange(t.year) }}
                                                </p>
                                                <p class="text-xs text-gray-500">
                                                    {{ t.semester }}
                                                </p>
                                            </div>
                                            <span v-else class="text-gray-400">—</span>
                                        </td>
                                        <td
                                            class="px-4 py-3 text-sm font-semibold text-green-600"
                                        >
                                            +{{ formatCurrency(t.amount) }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <span
                                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                                :class="{
                                                    'bg-green-100 text-green-800':
                                                        t.status === 'paid',
                                                    'bg-yellow-100 text-yellow-800':
                                                        t.status === 'pending',
                                                    'bg-blue-100 text-blue-800':
                                                        t.status === 'awaiting_approval',
                                                    'bg-red-100 text-red-800':
                                                        t.status === 'failed',
                                                    'bg-gray-100 text-gray-700':
                                                        t.status === 'cancelled',
                                                }"
                                            >
                                                {{
                                                    t.status === 'awaiting_approval'
                                                        ? 'Awaiting Verification'
                                                        : t.status
                                                }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div
                            v-if="txSubjectPanels[group.key]"
                            class="border-t border-gray-100"
                        >
                            <button
                                type="button"
                                class="flex w-full items-center justify-between bg-indigo-50 px-5 py-3 text-left transition-colors select-none hover:bg-indigo-100"
                                @click="toggleTxSubjectPanel(group.key)"
                            >
                                <div class="flex items-center gap-2">
                                    <BookOpen class="h-4 w-4 text-indigo-500" />
                                    <span class="text-sm font-semibold text-indigo-800">
                                        Subject Billing Snapshot — {{ group.key }}
                                    </span>
                                    <span
                                        class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700"
                                    >
                                        {{ txSubjectPanels[group.key]!.subjectCount }} subject{{
                                            txSubjectPanels[group.key]!.subjectCount !== 1
                                                ? 's'
                                                : ''
                                        }}
                                        · {{ txSubjectPanels[group.key]!.totalUnits }} units
                                    </span>
                                    <span
                                        v-if="txSubjectPanels[group.key]!.source === 'snapshot'"
                                        class="rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700"
                                    >
                                        Live snapshot
                                    </span>
                                </div>
                                <ChevronDown
                                    class="h-4 w-4 text-indigo-400 transition-transform duration-200"
                                    :class="{
                                        'rotate-180': expandedTxSubjectPanels.has(group.key),
                                    }"
                                />
                            </button>

                            <div
                                v-if="expandedTxSubjectPanels.has(group.key)"
                                class="overflow-x-auto bg-gray-50"
                            >
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr
                                            class="border-b border-gray-200 bg-gray-100 text-xs font-semibold tracking-wide text-gray-500 uppercase"
                                        >
                                            <th class="px-5 py-2.5 text-left">Type</th>
                                            <th class="px-5 py-2.5 text-left">Code</th>
                                            <th class="px-5 py-2.5 text-left">Subject Name</th>
                                            <th class="px-5 py-2.5 text-center">LEC</th>
                                            <th class="px-5 py-2.5 text-center">LAB</th>
                                            <th class="px-5 py-2.5 text-right">Tuition</th>
                                            <th class="px-5 py-2.5 text-right">Lab Fee</th>
                                            <th class="px-5 py-2.5 text-right">Total</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr
                                            v-for="subject in txSubjectPanels[group.key]!
                                                .subjects"
                                            :key="subject.subject_id ?? subject.code"
                                            :class="[
                                                'transition-colors',
                                                subject.isNstp
                                                    ? 'bg-amber-50/50 hover:bg-amber-50'
                                                    : subject.isPathfit
                                                    ? 'bg-purple-50/30 hover:bg-purple-50'
                                                    : 'hover:bg-green-50/40',
                                            ]"
                                        >
                                            <!-- Type badge -->
                                            <td class="px-5 py-3">
                                                <span
                                                    v-if="subject.isNstp"
                                                    class="inline-block rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700"
                                                >NSTP</span>
                                                <span
                                                    v-else-if="subject.isPathfit"
                                                    class="inline-block rounded-full bg-purple-100 px-2 py-0.5 text-xs font-semibold text-purple-700"
                                                >PATHFIT</span>
                                                <span
                                                    v-else
                                                    class="inline-block rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700"
                                                >Regular</span>
                                            </td>
                                            <td class="px-5 py-3">
                                                <span
                                                    class="rounded bg-indigo-50 px-2 py-0.5 font-mono text-xs font-semibold text-indigo-700"
                                                    >{{ subject.code }}</span
                                                >
                                            </td>
                                            <td class="px-5 py-3">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="font-medium text-gray-900">{{
                                                        subject.name
                                                    }}</span>
                                                    <FlaskConical
                                                        v-if="subject.hasLab || subject.labUnits > 0"
                                                        class="h-3.5 w-3.5 flex-shrink-0 text-purple-500"
                                                    />
                                                </div>
                                                <span
                                                    v-if="subject.isNstp"
                                                    class="text-xs text-amber-600"
                                                >Billed at {{ subject.nstpUnits ?? 1.5 }} units fixed</span>
                                            </td>
                                            <td class="px-5 py-3 text-center">
                                                <span
                                                    class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700"
                                                >{{ subject.lecUnits }}</span>
                                            </td>
                                            <td class="px-5 py-3 text-center">
                                                <span
                                                    v-if="(subject.labUnits ?? 0) > 0"
                                                    class="rounded-full bg-orange-50 px-2 py-0.5 text-xs font-semibold text-orange-600"
                                                >{{ subject.labUnits }}</span>
                                                <span v-else class="text-xs text-gray-300">—</span>
                                            </td>
                                            <td
                                                class="px-5 py-3 text-right font-medium text-gray-900"
                                                :class="{ 'text-amber-700': subject.isNstp, 'text-gray-400': subject.isPathfit }"
                                            >
                                                {{ subject.isPathfit ? '—' : formatCurrency(subject.tuitionAmount) }}
                                            </td>
                                            <td class="px-5 py-3 text-right">
                                                <span
                                                    v-if="(subject.labUnits ?? 0) > 0 && subject.labAmount > 0"
                                                    class="font-medium text-purple-700"
                                                    >{{ formatCurrency(subject.labAmount) }}</span>
                                                <span v-else class="text-xs text-gray-300">—</span>
                                            </td>
                                            <td
                                                class="px-5 py-3 text-right font-semibold text-gray-900"
                                                :class="{ 'text-gray-400': subject.isPathfit }"
                                            >
                                                {{ subject.isPathfit ? '—' : formatCurrency(subject.totalFee) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                    <tfoot>
                                        <tr
                                            class="border-t-2 border-gray-200 bg-gray-50 text-sm font-semibold"
                                        >
                                            <td colspan="3" class="px-5 py-3 text-gray-700">
                                                Subtotal —
                                                {{
                                                    txSubjectPanels[group.key]!.subjectCount
                                                }}
                                                subjects
                                            </td>
                                            <td
                                                class="px-5 py-3 text-center font-bold text-blue-700"
                                            >
                                                {{ txSubjectPanels[group.key]!.totalLecUnits }}</td>
                                            <td class="px-5 py-3 text-center text-orange-600 font-bold">
                                                {{ txSubjectPanels[group.key]!.totalLabUnits || '—' }}</td>
                                            <td class="px-5 py-3 text-right text-gray-900">
                                                {{
                                                    formatCurrency(
                                                        txSubjectPanels[group.key]!.totalTuition,
                                                    )
                                                }}
                                            </td>
                                            <td class="px-5 py-3 text-right text-purple-700">
                                                <span
                                                    v-if="
                                                        txSubjectPanels[group.key]!.totalLab > 0
                                                    "
                                                    >{{
                                                        formatCurrency(
                                                            txSubjectPanels[group.key]!.totalLab,
                                                        )
                                                    }}</span
                                                >
                                                <span
                                                    v-else
                                                    class="text-xs font-normal text-gray-300"
                                                    >—</span
                                                >
                                            </td>
                                            <td class="px-5 py-3 text-right text-indigo-700">
                                                {{
                                                    formatCurrency(
                                                        txSubjectPanels[group.key]!.totalTuition +
                                                            txSubjectPanels[group.key]!.totalLab,
                                                    )
                                                }}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>