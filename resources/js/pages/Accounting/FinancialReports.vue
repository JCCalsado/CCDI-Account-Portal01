<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useDataFormatting } from '@/composables/useDataFormatting'
import AppLayout from '@/layouts/AppLayout.vue'
import { Head, router } from '@inertiajs/vue3'
import { BarChart3, Download, TrendingDown, TrendingUp } from 'lucide-vue-next'
import { computed, ref } from 'vue'

// ── Types ──────────────────────────────────────────────────────────────────────

interface PaymentMethod {
    method: string
    count: number
    total: number
}

interface OutstandingStudent {
    accountId: string
    latestRef: string
    studentName: string
    course: string
    total: number
    balance: number
}

interface HistoricalSummary {
    label: string
    schoolYear: string
    semester: string
    totalAssessments: number
    totalAssessmentAmount: number
    totalPaid: number
    totalOutstanding: number
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
    historicalComparison: HistoricalSummary | null
    outstandingStudents: OutstandingStudent[]
    filters: {
        schoolYear: string
        semester: string
    }
    schoolYears: string[]
    semesters: string[]
}

// ── Props & composables ────────────────────────────────────────────────────────

const props = defineProps<Props>()
const { formatCurrency } = useDataFormatting()

// ── Local state ────────────────────────────────────────────────────────────────

const selectedSchoolYear = ref(props.filters.schoolYear)
const selectedSemester   = ref(props.filters.semester)
const searchQuery        = ref('')

// ── Breadcrumbs ────────────────────────────────────────────────────────────────

const breadcrumbs = [
    { title: 'Dashboard',  href: route('dashboard') },
    { title: 'Accounting', href: route('accounting.dashboard') },
    { title: 'Financial Reports' },
]

// ── Computed ───────────────────────────────────────────────────────────────────

const collectionRate = computed(() => {
    const total = props.summary.totalAssessmentAmount
    if (total <= 0) return 0
    return Math.min(Math.round((props.summary.totalPaid / total) * 100), 100)
})

const historicalCollectionRate = computed(() => {
    if (!props.historicalComparison) return 0
    const total = props.historicalComparison.totalAssessmentAmount
    if (total <= 0) return 0
    return Math.min(
        Math.round((props.historicalComparison.totalPaid / total) * 100),
        100,
    )
})

// Deltas: current period vs same semester last year
const delta = computed(() => {
    if (!props.historicalComparison) return null
    const prev = props.historicalComparison
    return {
        assessments:    props.summary.totalAssessments      - prev.totalAssessments,
        paid:           props.summary.totalPaid             - prev.totalPaid,
        outstanding:    props.summary.totalOutstanding      - prev.totalOutstanding,
        collectionRate: collectionRate.value                - historicalCollectionRate.value,
    }
})

// Payment method filter — hide card methods (they can't be used in test mode anyway)
const filteredPaymentMethods = computed(() =>
    props.paymentMethods.filter((m) => {
        const key = m.method.toLowerCase().replace(/\s+/g, '_')
        return key !== 'credit_card' && key !== 'debit_card'
    }),
)

const maxCourseTotal = computed(() =>
    Math.max(...props.charts.byCourse.map((c) => c.total), 1),
)

const maxMonthTotal = computed(() =>
    Math.max(...props.charts.byMonth.map((m) => m.total), 1),
)

// Client-side search — all students are already loaded so no round-trip needed
const filteredOutstandingStudents = computed(() => {
    const q = searchQuery.value.trim().toLowerCase()
    if (!q) return props.outstandingStudents
    return props.outstandingStudents.filter(
        (s) =>
            s.studentName.toLowerCase().includes(q) ||
            s.accountId.toLowerCase().includes(q)   ||
            s.course.toLowerCase().includes(q)       ||
            s.latestRef.toLowerCase().includes(q),
    )
})

// ── Actions ────────────────────────────────────────────────────────────────────

const applyFilters = () => {
    router.get(
        route('accounting.financial-reports'),
        {
            school_year: selectedSchoolYear.value,
            semester:    selectedSemester.value,
        },
        { preserveState: false },
    )
}

const exportPDF = () => {
    window.location.href = route('accounting.financial-reports.export', {
        school_year: selectedSchoolYear.value,
        semester:    selectedSemester.value,
    })
}

const exportAssessments = () => {
    window.location.href = route('accounting.financial-reports.export-assessments', {
        school_year: selectedSchoolYear.value,
        semester:    selectedSemester.value,
    })
}

const exportReceipts = () => {
    window.location.href = route('accounting.financial-reports.export-receipts', {
        school_year: selectedSchoolYear.value,
        semester:    selectedSemester.value,
    })
}
</script>

<template>
    <AppLayout>
        <Head title="Financial Reports" />

        <div class="w-full space-y-6 p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- ── Page Header ─────────────────────────────────────────────── -->
            <div class="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <h1 class="text-3xl font-bold text-foreground">Financial Reports</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Monitor assessments, payments, and financial health
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
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
                </div>
            </div>

            <!-- ── Filters ─────────────────────────────────────────────────── -->
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Filters</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
                        <div class="flex-1">
                            <label for="school-year" class="mb-1 block text-sm font-medium text-foreground">
                                School Year
                            </label>
                            <select
                                id="school-year"
                                v-model="selectedSchoolYear"
                                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                            >
                                <option v-for="year in schoolYears" :key="year" :value="year">{{ year }}</option>
                            </select>
                        </div>
                        <div class="flex-1">
                            <label for="semester" class="mb-1 block text-sm font-medium text-foreground">
                                Semester
                            </label>
                            <select
                                id="semester"
                                v-model="selectedSemester"
                                class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100"
                            >
                                <option v-for="sem in semesters" :key="sem" :value="sem">{{ sem }}</option>
                            </select>
                        </div>
                        <Button @click="applyFilters" class="bg-blue-600 hover:bg-blue-700">
                            Apply Filters
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- ── Summary KPI Cards ───────────────────────────────────────── -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

                <!-- Total Assessments -->
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Total Assessments</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-3xl font-bold">{{ summary.totalAssessments }}</div>
                        <p class="mt-1 text-xs text-muted-foreground">Students assessed</p>
                        <p
                            v-if="delta"
                            class="mt-2 flex items-center gap-1 text-xs font-medium"
                            :class="delta.assessments >= 0 ? 'text-green-600' : 'text-red-500'"
                        >
                            <TrendingUp v-if="delta.assessments >= 0" class="h-3 w-3" />
                            <TrendingDown v-else class="h-3 w-3" />
                            {{ delta.assessments >= 0 ? '+' : '' }}{{ delta.assessments }}
                            vs {{ historicalComparison!.label }}
                        </p>
                    </CardContent>
                </Card>

                <!-- Total Assessment Amount -->
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Total Assessment</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ formatCurrency(summary.totalAssessmentAmount) }}</div>
                        <p class="mt-1 text-xs text-muted-foreground">Total billed</p>
                        <p v-if="historicalComparison" class="mt-2 text-xs text-muted-foreground">
                            Prev: {{ formatCurrency(historicalComparison.totalAssessmentAmount) }}
                        </p>
                    </CardContent>
                </Card>

                <!-- Total Paid -->
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Total Paid</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold text-green-600">{{ formatCurrency(summary.totalPaid) }}</div>
                        <p class="mt-1 text-xs text-muted-foreground">{{ collectionRate }}% collection rate</p>
                        <p
                            v-if="delta"
                            class="mt-2 flex items-center gap-1 text-xs font-medium"
                            :class="delta.paid >= 0 ? 'text-green-600' : 'text-red-500'"
                        >
                            <TrendingUp v-if="delta.paid >= 0" class="h-3 w-3" />
                            <TrendingDown v-else class="h-3 w-3" />
                            {{ delta.paid >= 0 ? '+' : '' }}{{ formatCurrency(delta.paid) }}
                            vs {{ historicalComparison!.label }}
                        </p>
                    </CardContent>
                </Card>

                <!-- Outstanding -->
                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Outstanding</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold text-red-600">{{ formatCurrency(summary.totalOutstanding) }}</div>
                        <p class="mt-1 text-xs text-muted-foreground">Pending payments</p>
                        <!-- Lower outstanding = improvement, so flip the colour logic -->
                        <p
                            v-if="delta"
                            class="mt-2 flex items-center gap-1 text-xs font-medium"
                            :class="delta.outstanding <= 0 ? 'text-green-600' : 'text-red-500'"
                        >
                            <TrendingDown v-if="delta.outstanding <= 0" class="h-3 w-3" />
                            <TrendingUp v-else class="h-3 w-3" />
                            {{ delta.outstanding >= 0 ? '+' : '' }}{{ formatCurrency(delta.outstanding) }}
                            vs {{ historicalComparison!.label }}
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!-- ── Year-over-Year Comparison Panel ────────────────────────── -->
            <!--
                Only rendered when the same semester existed one AY ago.
                Label example: "1st Sem 2024-2025"
            -->
            <Card v-if="historicalComparison">
                <CardHeader>
                    <CardTitle class="text-base">
                        Year-over-Year Comparison
                        <span class="ml-2 text-sm font-normal text-muted-foreground">
                            {{ filters.semester }} Sem {{ filters.schoolYear }}
                            vs
                            {{ historicalComparison.label }}
                        </span>
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">

                        <div class="rounded-lg border border-border bg-muted/30 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                {{ historicalComparison.label }}
                            </p>
                            <p class="mt-2 text-2xl font-bold">{{ historicalComparison.totalAssessments }}</p>
                            <p class="mt-1 text-xs text-muted-foreground">students assessed</p>
                        </div>

                        <div class="rounded-lg border border-border bg-muted/30 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                Total Billed
                            </p>
                            <p class="mt-2 text-xl font-bold">
                                {{ formatCurrency(historicalComparison.totalAssessmentAmount) }}
                            </p>
                        </div>

                        <div class="rounded-lg border border-border bg-muted/30 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                Total Paid
                            </p>
                            <p class="mt-2 text-xl font-bold text-green-600">
                                {{ formatCurrency(historicalComparison.totalPaid) }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ historicalCollectionRate }}% collection rate
                            </p>
                        </div>

                        <div class="rounded-lg border border-border bg-muted/30 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                Outstanding
                            </p>
                            <p class="mt-2 text-xl font-bold text-red-600">
                                {{ formatCurrency(historicalComparison.totalOutstanding) }}
                            </p>
                        </div>

                    </div>
                </CardContent>
            </Card>

            <!-- ── Charts ─────────────────────────────────────────────────── -->
            <div class="grid gap-6 lg:grid-cols-2">

                <!-- Assessments by Course -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <BarChart3 class="h-5 w-5" />
                            Assessments by Course
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-4">
                            <div
                                v-if="charts.byCourse.length === 0"
                                class="py-6 text-center text-sm text-muted-foreground"
                            >
                                No assessment data for this period.
                            </div>
                            <div
                                v-for="course in charts.byCourse"
                                :key="course.course"
                                class="flex items-end gap-3"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-medium text-foreground">
                                        {{ course.course }}
                                    </div>
                                    <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-muted">
                                        <div
                                            class="h-full bg-blue-500"
                                            :style="{ width: (course.total / maxCourseTotal) * 100 + '%' }"
                                        ></div>
                                    </div>
                                </div>
                                <div class="whitespace-nowrap text-right">
                                    <div class="text-sm font-semibold">{{ course.student_count }}</div>
                                    <div class="text-xs text-muted-foreground">{{ formatCurrency(course.total) }}</div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Payments by Month -->
                <Card>
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <TrendingUp class="h-5 w-5" />
                            Payments by Month
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-4">
                            <div
                                v-if="charts.byMonth.length === 0"
                                class="py-6 text-center text-sm text-muted-foreground"
                            >
                                No payment data for this period.
                            </div>
                            <div
                                v-for="month in charts.byMonth"
                                :key="month.month"
                                class="flex items-end gap-3"
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="text-sm font-medium text-foreground">{{ month.month }}</div>
                                    <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-muted">
                                        <div
                                            class="h-full bg-green-500"
                                            :style="{ width: (month.total / maxMonthTotal) * 100 + '%' }"
                                        ></div>
                                    </div>
                                </div>
                                <div class="whitespace-nowrap text-right">
                                    <div class="text-sm font-semibold">{{ formatCurrency(month.total) }}</div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- ── Payment Methods ─────────────────────────────────────────── -->
            <Card>
                <CardHeader>
                    <CardTitle>Payment Method Breakdown</CardTitle>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="filteredPaymentMethods.length === 0"
                        class="py-6 text-center text-sm text-muted-foreground"
                    >
                        No payment data for this period.
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 md:grid-cols-3">
                        <div
                            v-for="method in filteredPaymentMethods"
                            :key="method.method"
                            class="rounded-lg border border-border p-4"
                        >
                            <div class="text-sm font-medium capitalize text-muted-foreground">{{ method.method }}</div>
                            <div class="mt-2 text-2xl font-bold">{{ method.count }}</div>
                            <div class="mt-1 text-xs text-muted-foreground">{{ formatCurrency(method.total) }}</div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- ── Outstanding Balances — ALL students, client-side search ── -->
            <Card>
                <CardHeader>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <CardTitle>
                            Outstanding Balances
                            <span class="ml-2 text-sm font-normal text-muted-foreground">
                                <template v-if="searchQuery.trim()">
                                    {{ filteredOutstandingStudents.length }} of {{ outstandingStudents.length }}
                                </template>
                                <template v-else>
                                    {{ outstandingStudents.length }}
                                </template>
                                student{{ outstandingStudents.length !== 1 ? 's' : '' }}
                            </span>
                        </CardTitle>
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Search by name, ID, course…"
                            class="w-full rounded-lg border border-border bg-background px-3 py-2 text-sm focus:border-blue-400 focus:outline-none focus:ring-2 focus:ring-blue-100 sm:w-72"
                        />
                    </div>
                </CardHeader>
                <CardContent>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border">
                            <thead class="bg-muted/50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Account ID
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Latest Reference
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Student Name
                                    </th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Course
                                    </th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Total Assessment
                                    </th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Outstanding Balance
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr
                                    v-for="(student, index) in filteredOutstandingStudents"
                                    :key="index"
                                    class="hover:bg-muted/30"
                                >
                                    <td class="px-4 py-3 font-mono text-sm text-muted-foreground">
                                        {{ student.accountId }}
                                    </td>
                                    <td class="px-4 py-3 font-mono text-sm text-indigo-600">
                                        {{ student.latestRef }}
                                    </td>
                                    <td class="px-4 py-3 text-sm font-medium">{{ student.studentName }}</td>
                                    <td class="px-4 py-3 text-sm text-muted-foreground">{{ student.course }}</td>
                                    <td class="px-4 py-3 text-right text-sm">
                                        {{ formatCurrency(student.total) }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-sm font-semibold text-red-600">
                                        {{ formatCurrency(student.balance) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <!-- Empty: no data for period -->
                        <div v-if="outstandingStudents.length === 0" class="py-8 text-center">
                            <p class="text-sm text-muted-foreground">No outstanding balances for this period.</p>
                        </div>

                        <!-- Empty: search returned nothing -->
                        <div
                            v-else-if="filteredOutstandingStudents.length === 0"
                            class="py-8 text-center"
                        >
                            <p class="text-sm text-muted-foreground">No students match your search.</p>
                        </div>
                    </div>
                </CardContent>
            </Card>

        </div>
    </AppLayout>
</template>