<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useDataFormatting } from '@/composables/useDataFormatting';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, usePage, useForm } from '@inertiajs/vue3';
import { Edit, Eye, Plus, Search, UserPlus, UserX } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface PaymentTerm {
    id: number;
    term_name: string;
    term_order: number;
    amount: number;
    balance: number;
    status: string;
    due_date: string | null;
}

interface Assessment {
    id: number;
    semester: string | null;
    total_assessment: number;
    paymentTerms: PaymentTerm[];
}

interface Student {
    id: number;
    student_db_id: number | null;
    account_id: string;
    name: string;
    course: string;
    year_level: string;
    status: string;
    remaining_balance: number;
    account: { balance: number } | null;
    latestAssessment: Assessment | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Props {
    students: {
        data: Student[];
        links: PaginationLink[];
        current_page: number;
        last_page: number;
    };
    filters: {
        search?: string;
        course?: string;
        year_level?: string;
        status?: string;
    };
    sort: 'name' | 'balance';
    direction: 'asc' | 'desc';
    courses: string[];
    yearLevels: string[];
    statuses: Record<string, string>;
}

const props = defineProps<Props>();

const { formatCurrency } = useDataFormatting();
const page = usePage();

const isAdmin = computed(() => (page.props.auth as any).user?.role === 'admin');
const isAccounting = computed(() => (page.props.auth as any).user?.role === 'accounting');

// ── CHANGED: Admin title is now "Student Overview" (read-only) ──
const pageTitle = computed(() => isAdmin.value ? 'Student Overview' : 'Student Fee Management');
const pageDescription = computed(() => isAdmin.value
    ? 'View student enrollment and fee information (read-only)'
    : 'Manage student assessments and fee records');

const breadcrumbs = computed(() => [{ title: 'Dashboard', href: route('dashboard') }, { title: pageTitle.value }]);

const courses = computed(() => props.courses);
const yearLevels = computed(() => props.yearLevels);
const statuses = computed(() => props.statuses);

const searchForm = ref({
    search:     props.filters.search || '',
    course:     props.filters.course || '',
    year_level: props.filters.year_level || '',
    status:     props.filters.status || '',
});

const dispatchRequest = (overrides: Record<string, string> = {}) => {
    router.get(
        route('student-fees.index'),
        {
            search:     searchForm.value.search,
            course:     searchForm.value.course,
            year_level: searchForm.value.year_level,
            status:     searchForm.value.status,
            sort:       props.sort,
            direction:  props.direction,
            ...overrides,
        },
        { preserveState: true, replace: true },
    );
};

let searchTimeout: ReturnType<typeof setTimeout>;
const performSearch = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => dispatchRequest(), 300);
};

watch(searchForm, () => performSearch(), { deep: true });

const search = () => performSearch();

const toggleSort = (field: 'name' | 'balance') => {
    let newDirection: 'asc' | 'desc';
    if (props.sort === field) {
        newDirection = props.direction === 'asc' ? 'desc' : 'asc';
    } else {
        newDirection = field === 'balance' ? 'desc' : 'asc';
    }
    dispatchRequest({ sort: field, direction: newDirection });
};

const getStatusColor = (status: string) => {
    switch (status) {
        case 'active':    return 'bg-green-500 text-white';
        case 'graduated': return 'bg-blue-500 text-white';
        case 'dropped':   return 'bg-red-500 text-white';
        default:          return 'bg-gray-500 text-white';
    }
};

const getStatusConfig = (status: string) => {
    const map: Record<string, { badge: string; label: string }> = {
        active:    { badge: 'bg-green-100 text-green-800 border border-green-200',    label: 'Active' },
        graduated: { badge: 'bg-blue-100 text-blue-800 border border-blue-200',       label: 'Graduated' },
        pending:   { badge: 'bg-yellow-100 text-yellow-800 border border-yellow-200', label: 'Pending' },
        suspended: { badge: 'bg-orange-100 text-orange-800 border border-orange-200', label: 'Suspended' },
        dropped:   { badge: 'bg-red-100 text-red-800 border border-red-200',          label: 'Dropped' },
    };
    return map[status] ?? { badge: 'bg-gray-100 text-gray-800 border border-gray-200', label: status };
};

const getRemainingBalance = (student: Student): number => {
    const accountBal = parseFloat(String(student.account?.balance ?? 0));
    if (accountBal > 0) return accountBal;

    const terms = student.latestAssessment?.paymentTerms ?? [];
    if (terms.length > 0) {
        const termsTotal = terms.reduce((sum: number, t: PaymentTerm) => sum + parseFloat(String(t.balance)), 0);
        if (termsTotal > 0) return termsTotal;
    }

    return 0;
};

const getBalanceTimingStatus = (student: Student): 'red' | 'green' | 'zero' | null => {
    const terms = student.latestAssessment?.paymentTerms;
    if (!terms || terms.length === 0) return null;

    const balance = getRemainingBalance(student);
    if (balance === 0) return 'zero';

    const sorted = [...terms].sort((a, b) => a.term_order - b.term_order);
    const today  = new Date();
    today.setHours(0, 0, 0, 0);

    // A student is only "Behind Schedule" when at least one unpaid term has a
    // configured due_date that has already passed.
    // No due_date = no deadline = no urgency state.
    const hasPastDue = sorted.some((term) => {
        if (!term.due_date) return false;                         // no due date — never behind
        if (parseFloat(String(term.balance)) <= 0) return false; // fully paid — skip
        const due = new Date(term.due_date);
        due.setHours(0, 0, 0, 0);
        return due < today;
    });

    if (hasPastDue) return 'red';

    // Outstanding balance exists but no past-due dates → on track
    return 'green';
};

const getBalanceClasses = (student: Student): string => {
    const timing = getBalanceTimingStatus(student);
    switch (timing) {
        case 'red':   return 'text-red-600 font-bold';
        case 'green': return 'text-green-600 font-bold';
        case 'zero':  return 'text-green-600 font-semibold';
        default:      return 'text-gray-900 font-medium';
    }
};

const getBalanceBadge = (student: Student): { label: string; cls: string } | null => {
    const timing = getBalanceTimingStatus(student);
    if (timing === 'red')   return { label: 'Behind',     cls: 'bg-red-100 text-red-700 border border-red-200' };
    if (timing === 'green') return { label: 'On Track',   cls: 'bg-green-100 text-green-700 border border-green-200' };
    if (timing === 'zero')  return { label: 'Fully Paid', cls: 'bg-blue-100 text-blue-700 border border-blue-200' };
    return null;
};

const totalStudents    = computed(() => props.students.data.length);
const totalOutstanding = computed(() => props.students.data.reduce((sum, s) => sum + getRemainingBalance(s), 0));
const fullyPaidCount   = computed(() => props.students.data.filter(s => getRemainingBalance(s) === 0).length);
const behindCount      = computed(() => props.students.data.filter(s => getBalanceTimingStatus(s) === 'red').length);

const summary = computed(() => ({
    shownStudents:    totalStudents.value,
    totalOutstanding: totalOutstanding.value,
    fullyPaid:        fullyPaidCount.value,
    behindSchedule:   behindCount.value,
}));

// Drop modal — only used by Accounting
const dropModal = ref(false);
const selectedDropStudent = ref<Student | null>(null);
const dropForm = useForm({ reason: '' });

const openDrop  = (student: Student) => { selectedDropStudent.value = student; dropForm.reset(); dropModal.value = true; };
const closeDrop = () => { dropModal.value = false; selectedDropStudent.value = null; };
const submitDrop = () => {
    if (!selectedDropStudent.value) return;
    dropForm.post(route('student-fees.drop', selectedDropStudent.value.id), {
        onSuccess: () => closeDrop(),
    });
};
</script>

<template>
    <AppLayout>
        <Head :title="pageTitle" />

        <div class="w-full space-y-5 p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- ── CHANGED: Admin header — read-only, no Add Student button ── -->
            <div v-if="isAdmin" class="ccdi-page-header border-l-4 border-l-blue-600 bg-gradient-to-r from-blue-50 to-transparent">
                <div>
                    <h1 class="ccdi-section-title text-blue-900">Student Overview</h1>
                    <p class="ccdi-section-desc text-blue-700">View student enrollment and fee information</p>
                </div>
                <!-- No action buttons for Admin — read-only view -->
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-700">
                        Read-only
                    </span>
                </div>
            </div>

            <!-- Page Header — Accounting -->
            <div v-else class="ccdi-page-header border-l-4 border-l-green-600 bg-gradient-to-r from-green-50 to-transparent">
                <div>
                    <h1 class="ccdi-section-title text-green-900">Student Fee Management</h1>
                    <p class="ccdi-section-desc text-green-700">Create assessments, manage fees, and record student payments</p>
                </div>
                <div class="flex items-center gap-2">
                    <Link :href="route('student-fees.create')" class="ccdi-btn-primary">
                        Create Assessment
                    </Link>
                </div>
            </div>

            <!-- Stats (current page scope) -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="ccdi-stat-card">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Students (Page)</p>
                        <p class="text-xl font-bold text-foreground">{{ summary.shownStudents }}</p>
                        <p class="text-xs text-muted-foreground">Shown in list</p>
                    </div>
                </div>
                <div class="ccdi-stat-card">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Outstanding (Page)</p>
                        <p class="text-xl font-bold text-red-600">₱{{ summary.totalOutstanding.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</p>
                    </div>
                </div>
                <div class="ccdi-stat-card">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Fully Paid</p>
                        <p class="text-xl font-bold text-emerald-600">{{ summary.fullyPaid }}</p>
                        <p class="text-xs text-muted-foreground">All terms settled</p>
                    </div>
                </div>
                <div class="ccdi-stat-card">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Behind Schedule</p>
                        <p class="text-xl font-bold" :class="summary.behindSchedule > 0 ? 'text-amber-600' : 'text-foreground'">{{ summary.behindSchedule }}</p>
                        <p class="text-xs text-muted-foreground">Payment overdue</p>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="ccdi-card p-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                        <input v-model="searchForm.search" type="text" placeholder="Search by ID or name..." class="w-full rounded-lg border border-border bg-background py-2 pl-9 pr-3 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" @keyup.enter="search" />
                    </div>
                    <select v-model="searchForm.course" class="rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" @change="search">
                        <option value="">All Courses</option>
                        <option v-for="course in courses" :key="course" :value="course">{{ course }}</option>
                    </select>
                    <select v-model="searchForm.year_level" class="rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" @change="search">
                        <option value="">All Year Levels</option>
                        <option v-for="level in yearLevels" :key="level" :value="level">{{ level }}</option>
                    </select>
                    <select v-model="searchForm.status" class="rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100" @change="search">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="graduated">Graduated</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <!-- Table -->
            <div class="ccdi-card overflow-hidden">
                <table class="min-w-full divide-y divide-border">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                Account ID
                            </th>
                            <th
                                @click="toggleSort('name')"
                                class="cursor-pointer select-none px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground transition-colors hover:bg-muted/70 hover:text-foreground"
                            >
                                <div class="flex items-center gap-1.5">
                                    <span>Name</span>
                                    <span class="inline-flex w-4 justify-center text-xs font-bold">
                                        <template v-if="sort === 'name'">{{ direction === 'asc' ? '↑' : '↓' }}</template>
                                        <template v-else><span class="text-muted-foreground/40">↕</span></template>
                                    </span>
                                </div>
                            </th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Course</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Year Level</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Semester</th>
                            <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                            <th
                                @click="toggleSort('balance')"
                                class="cursor-pointer select-none px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground transition-colors hover:bg-muted/70 hover:text-foreground"
                            >
                                <div class="flex items-center justify-end gap-1.5">
                                    <span>Balance</span>
                                    <span class="inline-flex w-4 justify-center text-xs font-bold">
                                        <template v-if="sort === 'balance'">{{ direction === 'asc' ? '↑' : '↓' }}</template>
                                        <template v-else><span class="text-muted-foreground/40">↕</span></template>
                                    </span>
                                </div>
                            </th>
                            <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border bg-card">
                        <tr v-for="student in students.data" :key="student.id" class="transition-colors hover:bg-muted/30">
                            <td class="px-5 py-3.5 font-mono text-xs text-muted-foreground">{{ student.account_id }}</td>
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-xs font-semibold" :class="getStatusColor(student.status)">
                                        {{ student.name.split(',')[0]?.charAt(0) ?? '?' }}
                                    </div>
                                    <span class="text-sm font-medium text-foreground">{{ student.name }}</span>
                                </div>
                            </td>
                            <td class="px-5 py-3.5 text-sm text-muted-foreground">{{ student.course }}</td>
                            <td class="px-5 py-3.5 text-sm text-muted-foreground">{{ student.year_level }}</td>
                            <td class="px-5 py-3.5 text-sm text-muted-foreground">
                                <span v-if="student.latestAssessment?.semester" class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">
                                    {{ student.latestAssessment.semester }}
                                </span>
                                <span v-else class="text-muted-foreground/40">—</span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span :class="getStatusConfig(student.status).badge">{{ getStatusConfig(student.status).label }}</span>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <span class="text-sm font-semibold" :class="(student.remaining_balance ?? 0) > 0 ? 'text-red-600' : 'text-emerald-600'">
                                    {{ formatCurrency(student.remaining_balance ?? 0) }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-1.5">

                                    <!-- View — available to both Admin and Accounting -->
                                    <Link
                                        :href="route('student-fees.show', student.id)"
                                        class="rounded-lg border border-border bg-card p-1.5 text-muted-foreground transition-all hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700"
                                        title="View Fees"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                    </Link>

                                    <!-- ── CHANGED: Edit and Archive — Accounting only ── -->
                                    <template v-if="isAccounting">
                                        <Link
                                            :href="route('student-fees.edit-student', student.student_db_id)"
                                            class="rounded-lg border border-border bg-card p-1.5 text-muted-foreground transition-all hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-700"
                                            title="Edit Student Info"
                                        >
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                        </Link>
                                        <button
                                            v-if="student.status !== 'graduated'"
                                            @click="openDrop(student)"
                                            class="rounded-lg border border-border bg-card p-1.5 text-muted-foreground transition-all hover:border-red-300 hover:bg-red-50 hover:text-red-700"
                                            title="Archive"
                                        >
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8l1 12a2 2 0 002 2h8a2 2 0 002-2L19 8m-9 4h4" /></svg>
                                        </button>
                                    </template>

                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Empty state -->
                <div v-if="!students.data?.length" class="flex flex-col items-center justify-center py-16 text-center">
                    <p class="text-base font-semibold text-foreground">No students found</p>
                    <p class="mt-1 text-sm text-muted-foreground">Try adjusting the search filters above</p>
                </div>

                <!-- Legend + Pagination -->
                <div class="flex flex-col gap-3 border-t border-border bg-muted/20 px-5 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-4 text-xs text-muted-foreground">
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-red-500"></span>Behind — past due date</span>
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>On Track</span>
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-blue-500"></span>Fully Paid</span>
                    </div>
                    <div v-if="students.links && students.links.length > 3" class="flex gap-1">
                        <Link
                            v-for="(link, index) in students.links"
                            :key="index"
                            :href="link.url || '#'"
                            :class="['rounded-lg border px-3 py-1.5 text-xs font-medium transition-all', link.active ? 'border-blue-600 bg-blue-600 text-white' : 'border-border bg-card text-foreground hover:bg-muted', !link.url ? 'cursor-not-allowed opacity-40' : '']"
                            :disabled="!link.url"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>

    <!-- Drop Modal — only ever triggered by Accounting -->
    <Teleport to="body">
        <div v-if="dropModal" class="fixed inset-0 z-50 flex items-center justify-center">
            <div class="absolute inset-0 bg-black/50" @click="closeDrop" />
            <div class="relative z-10 w-full max-w-md rounded-xl border border-border bg-card p-6 shadow-2xl">
                <h2 class="text-base font-semibold text-foreground">Archive Student</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    You are about to archive
                    <span class="font-medium text-foreground">{{ selectedDropStudent?.name }}</span>.
                    This will mark them as dropped and move them to the archive.
                </p>
                <div class="mt-4">
                    <label class="mb-1 block text-xs font-medium text-foreground">Reason <span class="text-red-500">*</span></label>
                    <textarea
                        v-model="dropForm.reason"
                        rows="3"
                        placeholder="Enter reason for archiving..."
                        class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-red-400 focus:outline-none focus:ring-2 focus:ring-red-100"
                    />
                    <p v-if="dropForm.errors.reason" class="mt-1 text-xs text-red-600">{{ dropForm.errors.reason }}</p>
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" class="rounded-lg border border-border bg-card px-4 py-2 text-sm font-medium text-foreground transition hover:bg-muted" @click="closeDrop">
                        Cancel
                    </button>
                    <button
                        type="button"
                        :disabled="dropForm.processing || !dropForm.reason.trim()"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                        @click="submitDrop"
                    >
                        <span v-if="dropForm.processing">Archiving…</span>
                        <span v-else>Archive Student</span>
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>