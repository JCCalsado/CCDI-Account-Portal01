<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useDataFormatting } from '@/composables/useDataFormatting'
import AppLayout from '@/layouts/AppLayout.vue'
import { Head, router } from '@inertiajs/vue3'
import { BarChart3, Download, Eye, FileText, TrendingUp, X } from 'lucide-vue-next'
import { computed, ref } from 'vue'

// ─── Types ────────────────────────────────────────────────────────────────────

interface PaymentMethod {
    method: string
    count: number
    total: number
}

interface AssessedStudent {
    userId: number
    accountId: string
    studentName: string
    course: string
    total: number
    balance: number
    status: 'Fully Paid' | 'Partial' | 'Unpaid'
}

interface StudentTransaction {
    id: number
    reference: string
    orNumber: string | null
    amount: number
    method: string
    termName: string
    schoolYear: string | number
    semester: string
    paidAt: string
}

interface Props {
    summary: {
        totalAssessments: number
        totalAssessmentAmount: number
        totalPaid: number
        totalOutstanding: number
    }
    charts: {
        byCourse: Array<{ course: string; student_count: number; total: number }>
        byMonth: Array<{ month: string; total: number }>
    }
    paymentMethods: PaymentMethod[]
    assessedStudents: AssessedStudent[]
    filters: {
        schoolYear: string
        semester: string
    }
    schoolYears: string[]
    semesters: string[]
    userRole: string
}

// ─── Setup ────────────────────────────────────────────────────────────────────

const props = defineProps<Props>()
const { formatCurrency } = useDataFormatting()

// ─── Role helpers ─────────────────────────────────────────────────────────────
const isAdmin = computed(() => props.userRole === 'admin')

const selectedSchoolYear = ref(props.filters.schoolYear)
const selectedSemester   = ref(props.filters.semester)
const searchQuery        = ref('')

// ── Transaction history modal state ──────────────────────────────────────────
const modalOpen        = ref(false)
const modalStudent     = ref<AssessedStudent | null>(null)
const modalLoading     = ref(false)
const modalError       = ref<string | null>(null)
const modalTransactions = ref<StudentTransaction[]>([])

const breadcrumbs = computed(() => [
    { title: 'Dashboard', href: isAdmin.value ? route('admin.dashboard') : route('accounting.dashboard') },
    ...(isAdmin.value ? [] : [{ title: 'Accounting', href: route('accounting.dashboard') }]),
    { title: 'Financial Reports' },
])

// ─── Computed ─────────────────────────────────────────────────────────────────

const collectionRate = computed(() => {
    const total = props.summary.totalAssessmentAmount
    if (total === 0) return 0
    return Math.round((props.summary.totalPaid / total) * 100)
})

const filteredPaymentMethods = computed(() =>
    props.paymentMethods.filter(
        (m) =>
            !['credit card', 'credit_card', 'debit card', 'debit_card'].includes(
                m.method.toLowerCase(),
            ),
    ),
)

const filteredAssessedStudents = computed(() => {
    if (!searchQuery.value.trim()) return props.assessedStudents
    const q = searchQuery.value.toLowerCase()
    return props.assessedStudents.filter(
        (s) =>
            s.studentName.toLowerCase().includes(q) ||
            s.accountId.toLowerCase().includes(q) ||
            s.course.toLowerCase().includes(q),
    )
})

// ─── Helpers ─────────────────────────────────────────────────────────────────

function statusBadgeClass(status: AssessedStudent['status']): string {
    switch (status) {
        case 'Fully Paid':
            return 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-green-100 text-green-800'
        case 'Partial':
            return 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800'
        case 'Unpaid':
            return 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-red-100 text-red-800'
        default:
            return 'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-muted text-muted-foreground'
    }
}

function balanceClass(balance: number): string {
    return balance <= 0
        ? 'px-4 py-3 text-right text-sm font-semibold text-green-600'
        : 'px-4 py-3 text-right text-sm font-semibold text-red-600'
}

// ─── Modal: View student transaction history ──────────────────────────────────

async function openHistoryModal(student: AssessedStudent) {
    modalStudent.value     = student
    modalTransactions.value = []
    modalError.value       = null
    modalLoading.value     = true
    modalOpen.value        = true

    try {
        const url   = route('accounting.financial-reports.student-history')
        const resp  = await fetch(`${url}?user_id=${student.userId}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
        if (!resp.ok) throw new Error(`Server error: ${resp.status}`)
        const data = await resp.json()
        modalTransactions.value = data.transactions ?? []
    } catch (err: unknown) {
        modalError.value = err instanceof Error ? err.message : 'Failed to load transactions.'
    } finally {
        modalLoading.value = false
    }
}

function closeModal() {
    modalOpen.value    = false
    modalStudent.value = null
}

// ─── Download per-student semester receipt ────────────────────────────────────

function downloadStudentReceipt(student: AssessedStudent) {
    const url = route('accounting.financial-reports.student-receipt')
    window.location.href =
        `${url}?user_id=${student.userId}` +
        `&school_year=${encodeURIComponent(selectedSchoolYear.value)}` +
        `&semester=${encodeURIComponent(selectedSemester.value)}`
}

// ─── Page-level actions ───────────────────────────────────────────────────────

const applyFilters = () => {
    router.get(
        route('accounting.financial-reports'),
        { school_year: selectedSchoolYear.value, semester: selectedSemester.value },
        { preserveState: false },
    )
}

const exportPDF          = () => { window.location.href = route('accounting.financial-reports.export', { school_year: selectedSchoolYear.value, semester: selectedSemester.value }) }
const exportAssessments  = () => { window.location.href = route('accounting.financial-reports.export-assessments', { school_year: selectedSchoolYear.value, semester: selectedSemester.value }) }
const exportReceipts     = () => { window.location.href = route('accounting.financial-reports.export-receipts', { school_year: selectedSchoolYear.value, semester: selectedSemester.value }) }
const exportYearly       = () => { window.location.href = route('accounting.financial-reports.export-yearly', { school_year: selectedSchoolYear.value }) }
</script>

<template>
    <AppLayout>
        <Head title="Financial Reports" />

        <div class="w-full space-y-6 p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- ── Page Header ───────────────────────────────────────────────── -->
            <div class="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <h1 class="text-3xl font-bold text-foreground">Financial Reports</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Monitor assessments, payments, and financial health</p>
                </div>
                <div class="flex gap-2 flex-wrap">
                    <Button @click="exportPDF" class="gap-2">
                        <Download class="h-4 w-4" />
                        Financial Report
                    </Button>
                    <Button @click="exportAssessments" variant="outline" class="gap-2">
                        <Download class="h-4 w-4" />
                        Student Assessments
                    </Button>
                    <Button @click="exportReceipts" variant="outline" class="gap-2">
                        <Download class="h-4 w-4" />
                        Payment Receipts
                    </Button>
                    <Button @click="exportYearly" variant="outline" class="gap-2 border-indigo-300 text-indigo-700 hover:bg-indigo-50">
                        <FileText class="h-4 w-4" />
                        Full Year Report
                    </Button>
                </div>
            </div>

            <!-- ── Filters ───────────────────────────────────────────────────── -->
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Filters</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
                        <div class="flex-1">
                            <label for="school-year" class="block text-sm font-medium text-foreground mb-1">School Year</label>
                            <select
                                id="school-year"
                                v-model="selectedSchoolYear"
                                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                            >
                                <option v-for="year in schoolYears" :key="year" :value="year">{{ year }}</option>
                            </select>
                        </div>
                        <div class="flex-1">
                            <label for="semester" class="block text-sm font-medium text-foreground mb-1">Semester</label>
                            <select
                                id="semester"
                                v-model="selectedSemester"
                                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                            >
                                <option v-for="sem in semesters" :key="sem" :value="sem">{{ sem }}</option>
                            </select>
                        </div>
                        <Button @click="applyFilters" class="bg-blue-600 hover:bg-blue-700">Apply Filters</Button>
                    </div>
                </CardContent>
            </Card>

            <!-- ── Summary Cards ─────────────────────────────────────────────── -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Total Assessments</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-3xl font-bold">{{ summary.totalAssessments }}</div>
                        <p class="mt-1 text-xs text-muted-foreground">Students assessed</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Total Assessment</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ formatCurrency(summary.totalAssessmentAmount) }}</div>
                        <p class="mt-1 text-xs text-muted-foreground">Total billed</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Total Paid</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold text-green-600">{{ formatCurrency(summary.totalPaid) }}</div>
                        <p class="mt-1 text-xs text-muted-foreground">{{ collectionRate }}% collection rate</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Outstanding</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold text-red-600">{{ formatCurrency(summary.totalOutstanding) }}</div>
                        <p class="mt-1 text-xs text-muted-foreground">Pending payments</p>
                    </CardContent>
                </Card>
            </div>

            <!-- ── Charts ────────────────────────────────────────────────────── -->
            <div class="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <BarChart3 class="h-5 w-5" />
                            Assessments by Course
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-4">
                            <div v-if="charts.byCourse.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                                No assessment data for this period.
                            </div>
                            <div v-for="course in charts.byCourse" :key="course.course" class="flex items-end gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-foreground truncate">{{ course.course }}</div>
                                    <div class="h-2 mt-1 w-full rounded-full bg-muted overflow-hidden">
                                        <div
                                            class="h-full bg-blue-500"
                                            :style="{ width: (course.total / Math.max(...charts.byCourse.map((c) => c.total))) * 100 + '%' }"
                                        ></div>
                                    </div>
                                </div>
                                <div class="text-right whitespace-nowrap">
                                    <div class="text-sm font-semibold">{{ course.student_count }}</div>
                                    <div class="text-xs text-muted-foreground">{{ formatCurrency(course.total) }}</div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <TrendingUp class="h-5 w-5" />
                            Payments by Month
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-4">
                            <div v-if="charts.byMonth.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                                No payment data for this period.
                            </div>
                            <div v-for="month in charts.byMonth" :key="month.month" class="flex items-end gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium text-foreground">{{ month.month }}</div>
                                    <div class="h-2 mt-1 w-full rounded-full bg-muted overflow-hidden">
                                        <div
                                            class="h-full bg-green-500"
                                            :style="{ width: (month.total / Math.max(...charts.byMonth.map((m) => m.total), 1)) * 100 + '%' }"
                                        ></div>
                                    </div>
                                </div>
                                <div class="text-right whitespace-nowrap">
                                    <div class="text-sm font-semibold">{{ formatCurrency(month.total) }}</div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- ── Payment Methods ───────────────────────────────────────────── -->
            <Card>
                <CardHeader>
                    <CardTitle>Payment Method Breakdown</CardTitle>
                </CardHeader>
                <CardContent>
                    <div v-if="filteredPaymentMethods.length === 0" class="py-6 text-center text-sm text-muted-foreground">
                        No payment data for this period.
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 md:grid-cols-3">
                        <div v-for="method in filteredPaymentMethods" :key="method.method" class="rounded-lg border border-border p-4">
                            <div class="text-sm font-medium text-muted-foreground capitalize">{{ method.method }}</div>
                            <div class="mt-2 text-2xl font-bold">{{ method.count }}</div>
                            <div class="mt-1 text-xs text-muted-foreground">{{ formatCurrency(method.total) }}</div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- ── Student Account Status ─────────────────────────────────────
                 All assessed students for this period.
                 Sorted by outstanding balance descending (debtors first).
                 Actions: View (all-year history modal) | Download Receipt (semester PDF)
            ─────────────────────────────────────────────────────────────────── -->
            <Card>
                <CardHeader>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <CardTitle>
                            Student Account Status
                            <span class="ml-2 text-sm font-normal text-muted-foreground">
                                <template v-if="searchQuery.trim()">
                                    {{ filteredAssessedStudents.length }} of {{ assessedStudents.length }} students
                                </template>
                                <template v-else>
                                    {{ assessedStudents.length }} student{{ assessedStudents.length !== 1 ? 's' : '' }}
                                </template>
                            </span>
                        </CardTitle>
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search by name, ID, course..."
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 sm:w-64"
                        />
                    </div>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border">
                            <thead class="bg-muted/50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Account ID</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Student Name</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Course</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Total Assessment</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Outstanding Balance</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-muted-foreground">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr
                                    v-for="(student, index) in filteredAssessedStudents"
                                    :key="index"
                                    class="hover:bg-muted/30"
                                >
                                    <td class="px-4 py-3 text-sm font-mono text-muted-foreground">{{ student.accountId }}</td>
                                    <td class="px-4 py-3 text-sm font-medium">{{ student.studentName }}</td>
                                    <td class="px-4 py-3 text-sm text-muted-foreground">{{ student.course }}</td>
                                    <td class="px-4 py-3 text-right text-sm">{{ formatCurrency(student.total) }}</td>
                                    <td :class="balanceClass(student.balance)">{{ formatCurrency(student.balance) }}</td>
                                    <td class="px-4 py-3 text-center">
                                        <span :class="statusBadgeClass(student.status)">{{ student.status }}</span>
                                    </td>
                                    <!-- Actions -->
                                    <td class="px-4 py-3 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <!-- View: opens all-year transaction history modal -->
                                            <button
                                                @click="openHistoryModal(student)"
                                                class="inline-flex items-center gap-1 rounded-md border border-border bg-background px-2.5 py-1.5 text-xs font-medium text-foreground shadow-sm hover:bg-muted transition-colors"
                                                title="View transaction history"
                                            >
                                                <Eye class="h-3.5 w-3.5" />
                                                View
                                            </button>
                                            <!-- Download Receipt: semester PDF -->
                                            <button
                                                @click="downloadStudentReceipt(student)"
                                                class="inline-flex items-center gap-1 rounded-md border border-border bg-background px-2.5 py-1.5 text-xs font-medium text-foreground shadow-sm hover:bg-muted transition-colors"
                                                title="Download semester receipt"
                                            >
                                                <Download class="h-3.5 w-3.5" />
                                                Receipt
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <div v-if="assessedStudents.length === 0" class="py-8 text-center">
                            <p class="text-sm text-muted-foreground">No students assessed for this period.</p>
                        </div>
                        <div v-else-if="filteredAssessedStudents.length === 0" class="py-8 text-center">
                            <p class="text-sm text-muted-foreground">No students match your search.</p>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- ── Transaction History Modal ──────────────────────────────────────
             Appears as a fixed overlay. Fetches all-time paid transactions
             for the selected student via the student-history JSON endpoint.
             No page load — stays on the Financial Reports page.
        ──────────────────────────────────────────────────────────────────────── -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition-opacity duration-200"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition-opacity duration-150"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="modalOpen"
                    class="fixed inset-0 z-50 flex items-center justify-center"
                    @click.self="closeModal"
                >
                    <!-- Backdrop -->
                    <div class="absolute inset-0 bg-black/50" @click="closeModal" />

                    <!-- Panel -->
                    <div class="relative z-10 mx-4 w-full max-w-3xl rounded-xl border border-border bg-background shadow-2xl">

                        <!-- Header -->
                        <div class="flex items-center justify-between border-b border-border px-6 py-4">
                            <div>
                                <h2 class="text-lg font-semibold text-foreground">
                                    Transaction History
                                </h2>
                                <p v-if="modalStudent" class="mt-0.5 text-sm text-muted-foreground">
                                    {{ modalStudent.studentName }}
                                    <span class="ml-2 font-mono text-xs">{{ modalStudent.accountId }}</span>
                                </p>
                            </div>
                            <button
                                @click="closeModal"
                                class="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
                            >
                                <X class="h-5 w-5" />
                            </button>
                        </div>

                        <!-- Body -->
                        <div class="max-h-[60vh] overflow-y-auto px-6 py-4">

                            <!-- Loading -->
                            <div v-if="modalLoading" class="flex items-center justify-center py-12">
                                <div class="h-8 w-8 animate-spin rounded-full border-4 border-blue-600 border-t-transparent"></div>
                                <span class="ml-3 text-sm text-muted-foreground">Loading transactions…</span>
                            </div>

                            <!-- Error -->
                            <div v-else-if="modalError" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                                {{ modalError }}
                            </div>

                            <!-- Empty -->
                            <div v-else-if="modalTransactions.length === 0" class="py-10 text-center">
                                <p class="text-sm text-muted-foreground">No paid transactions found for this student.</p>
                            </div>

                            <!-- Transaction table -->
                            <template v-else>
                                <p class="mb-3 text-xs text-muted-foreground">
                                    Showing all {{ modalTransactions.length }} paid transaction{{ modalTransactions.length !== 1 ? 's' : '' }} across all school years.
                                </p>
                                <table class="min-w-full divide-y divide-border text-sm">
                                    <thead class="bg-muted/50">
                                        <tr>
                                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Date Paid</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Reference / OR</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Term</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">School Year</th>
                                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Method</th>
                                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-border">
                                        <tr
                                            v-for="txn in modalTransactions"
                                            :key="txn.id"
                                            class="hover:bg-muted/30"
                                        >
                                            <td class="px-3 py-2.5 text-xs text-muted-foreground whitespace-nowrap">{{ txn.paidAt }}</td>
                                            <td class="px-3 py-2.5">
                                                <span class="font-mono text-xs text-indigo-600">{{ txn.reference }}</span>
                                                <span v-if="txn.orNumber" class="ml-1 text-xs text-muted-foreground">(OR: {{ txn.orNumber }})</span>
                                            </td>
                                            <td class="px-3 py-2.5 text-xs">{{ txn.termName }}</td>
                                            <td class="px-3 py-2.5 text-xs text-muted-foreground whitespace-nowrap">
                                                {{ txn.schoolYear }} {{ txn.semester }}
                                            </td>
                                            <td class="px-3 py-2.5 text-xs text-muted-foreground">{{ txn.method }}</td>
                                            <td class="px-3 py-2.5 text-right text-sm font-semibold text-green-600">
                                                {{ formatCurrency(txn.amount) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                    <!-- Grand total row -->
                                    <tfoot>
                                        <tr class="border-t-2 border-border bg-muted/30">
                                            <td colspan="5" class="px-3 py-2.5 text-sm font-semibold text-right text-foreground">
                                                Total Paid (all time):
                                            </td>
                                            <td class="px-3 py-2.5 text-right text-sm font-bold text-green-600">
                                                {{ formatCurrency(modalTransactions.reduce((s, t) => s + t.amount, 0)) }}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </template>
                        </div>

                        <!-- Footer -->
                        <div class="flex items-center justify-end gap-2 border-t border-border px-6 py-3">
                            <button
                                v-if="modalStudent"
                                @click="downloadStudentReceipt(modalStudent)"
                                class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
                            >
                                <Download class="h-4 w-4" />
                                Download {{ filters.semester }} Receipt
                            </button>
                            <button
                                @click="closeModal"
                                class="inline-flex items-center rounded-md border border-border bg-background px-4 py-2 text-sm font-medium text-foreground hover:bg-muted transition-colors"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AppLayout>
</template>