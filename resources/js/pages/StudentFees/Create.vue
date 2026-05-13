<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useDataFormatting } from '@/composables/useDataFormatting'
import {
  Search, User, BookOpen, Calculator,
  CheckCircle2, Loader2, AlertTriangle, Info, History,
} from 'lucide-vue-next'

// ─── Types ────────────────────────────────────────────────────────────────────

interface FeeRates {
  tuition_per_unit: number
  lab_fee_per_subject: number
  entrepreneurship_fee: number
  misc_total: number
  misc_items: Array<{ id: number; key: string; label: string; amount: number; category: string }>
  payment_terms: Array<{ term_name: string; term_order: number; percentage: number }>
}

interface PaidSemester {
  semester: '1st' | '2nd' | 'Summer'
  school_year: string
  assessment_id: number
  total_assessment: number
}

interface PreselectedStudent {
  id: number
  name: string
  account_id: string
  course: string
  year_level: string
  is_irregular: boolean
  remaining_balance: number
  paid_semesters: PaidSemester[]
}

interface CurriculumSubject {
  id: number
  code: string
  name: string
  lec_units: number
  lab_units: number
  total_units: number
  is_nstp: boolean
  is_pathfit: boolean
  is_billable: boolean
}

// ─── Props ────────────────────────────────────────────────────────────────────

const props = defineProps<{
  preselectedStudent: PreselectedStudent | null
  feeRates: FeeRates
}>()

const { formatCurrency } = useDataFormatting()

// ─── Breadcrumbs ──────────────────────────────────────────────────────────────

const breadcrumbs = [
  { title: 'Dashboard',      href: route('accounting.dashboard') },
  { title: 'Student Fees',   href: route('student-fees.index') },
  { title: 'New Assessment', href: route('student-fees.create') },
]

// ─── Paid Semester Logic ──────────────────────────────────────────────────────

const SEM_ORDER: Record<string, number> = { '1st': 1, '2nd': 2, 'Summer': 3 }

/**
 * Given the list of fully-paid assessments, compute the next recommended
 * semester and school year that Accounting should create an assessment for.
 *
 * Progression:
 *   1st → 2nd (same school year)
 *   2nd → 1st (next school year)
 *   Summer → 1st (next school year)
 *   no history → current school year, 1st semester
 */
function computeNextSemester(paid: PaidSemester[]): { semester: '1st' | '2nd' | 'Summer'; school_year: string } {
  const currentYear = new Date().getFullYear()
  const defaultYear = `${currentYear}-${currentYear + 1}`

  if (!paid.length) {
    return { semester: '1st', school_year: defaultYear }
  }

  const sorted = [...paid].sort((a, b) => {
    if (a.school_year !== b.school_year) return a.school_year.localeCompare(b.school_year)
    return (SEM_ORDER[a.semester] ?? 99) - (SEM_ORDER[b.semester] ?? 99)
  })

  const last = sorted[sorted.length - 1]

  if (last.semester === '1st') {
    return { semester: '2nd', school_year: last.school_year }
  }

  // 2nd or Summer → advance to 1st of next academic year
  const [startStr] = last.school_year.split('-')
  const startYear  = parseInt(startStr, 10)
  return { semester: '1st', school_year: `${startYear + 1}-${startYear + 2}` }
}

// ─── Student Search ───────────────────────────────────────────────────────────

const studentSearch   = ref('')
const searchResults   = ref<PreselectedStudent[]>([])
const searchLoading   = ref(false)
const selectedStudent = ref<PreselectedStudent | null>(props.preselectedStudent ?? null)
const paidSemesters   = ref<PaidSemester[]>(props.preselectedStudent?.paid_semesters ?? [])

const hasRemainingBalance = computed(
  () => (selectedStudent.value?.remaining_balance ?? 0) > 0
)

/**
 * Returns true if the given semester is already fully paid for the
 * currently selected school year. Used to disable semester buttons.
 */
function isSemesterPaid(semester: string): boolean {
  return paidSemesters.value.some(
    (ps) => ps.semester === semester && ps.school_year === form.school_year
  )
}

/**
 * Returns the PaidSemester record for a given semester + current school year,
 * or null if not paid.
 */
function getPaidRecord(semester: string): PaidSemester | null {
  return paidSemesters.value.find(
    (ps) => ps.semester === semester && ps.school_year === form.school_year
  ) ?? null
}

let searchTimeout: ReturnType<typeof setTimeout>

async function searchStudents() {
  if (studentSearch.value.length < 2) { searchResults.value = []; return }
  searchLoading.value = true
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(async () => {
    try {
      const res  = await fetch(route('student-fees.search') + '?q=' + encodeURIComponent(studentSearch.value))
      const data = await res.json()
      searchResults.value = data.students ?? []
    } catch { searchResults.value = [] }
    finally   { searchLoading.value = false }
  }, 300)
}

function selectStudent(student: PreselectedStudent) {
  selectedStudent.value    = student
  paidSemesters.value      = student.paid_semesters ?? []
  searchResults.value      = []
  studentSearch.value      = ''
  form.user_id             = student.id
  curriculumSubjects.value = []
  curriculumMessage.value  = ''
  hasNstp.value            = false

  // Auto-advance semester + school year based on paid history
  const next        = computeNextSemester(paidSemesters.value)
  form.semester     = next.semester
  form.school_year  = next.school_year
}

function clearStudent() {
  selectedStudent.value    = null
  paidSemesters.value      = []
  form.user_id             = 0
  form.lec_units           = 0
  form.lab_units           = 0
  curriculumSubjects.value = []
  curriculumMessage.value  = ''
  hasNstp.value            = false
}

// ─── Curriculum Auto-Populate ─────────────────────────────────────────────────

const curriculumLoading  = ref(false)
const curriculumSubjects = ref<CurriculumSubject[]>([])
const curriculumMessage  = ref('')

// ── NSTP state ────────────────────────────────────────────────────────────────
//
// hasNstp  = user-controllable checkbox.
//            Pre-populated from the curriculum API (preset.has_nstp OR subjects detection).
//            Can be toggled manually by accounting to handle irregular students or edge cases.
//
// nstpLecUnits = pure computed from hasNstp.
//                Always 1.5 when NSTP is active, always 0 when not.
//                Never a stale ref — derived solely from the checkbox.
//
const hasNstp = ref(false)

const NSTP_UNITS = 1.5  // mirrors AssessmentService::NSTP_MINIMUM_UNITS

// ─── Form ─────────────────────────────────────────────────────────────────────
//
// form.lec_units   = BILLABLE lecture units only — NSTP excluded.
//                    This is what the backend expects and what AssessmentService::compute()
//                    receives as the `$lecUnits` parameter.
//
// form.nstp_lec_units = 1.5 when NSTP is on, 0 when off.
//                       Sent to the backend separately.
//
const form = useForm({
  user_id:             props.preselectedStudent?.id ?? 0,
  semester:            '1st' as '1st' | '2nd' | 'Summer',
  school_year:         '',
  lec_units:           0,
  lab_units:           0,
  nstp_lec_units:      0,
  discount_percentage: 0 as number,
  term_percentages:    {} as Record<string, number>,
})

// Initialise semester / school_year from paid history if a student was preselected
if (props.preselectedStudent) {
  const next       = computeNextSemester(props.preselectedStudent.paid_semesters ?? [])
  form.semester    = next.semester
  form.school_year = next.school_year
} else {
  const currentYear = new Date().getFullYear()
  form.school_year  = `${currentYear}-${currentYear + 1}`
}

async function loadCurriculum() {
  const student = selectedStudent.value
  if (!student || student.is_irregular) {
    curriculumSubjects.value = []
    curriculumMessage.value  = student?.is_irregular ? 'Irregular student — enter units manually.' : ''
    hasNstp.value = false
    return
  }
  if (!form.semester) return

  curriculumLoading.value  = true
  curriculumSubjects.value = []
  curriculumMessage.value  = ''
  hasNstp.value            = false

  try {
    const res  = await fetch(
      route('student-fees.curriculum-units') +
      '?student_id=' + student.id +
      '&semester='   + encodeURIComponent(form.semester)
    )
    const data = await res.json()

    if (data.found) {
      curriculumSubjects.value = data.subjects
      form.lec_units           = data.billable_lec_units
      form.lab_units           = data.lab_subject_count
      hasNstp.value            = data.has_nstp ?? false

      // Soft warning when units came from preset, not subjects table
      if (data.source === 'preset') {
        curriculumMessage.value = data.message ?? 'Units auto-filled from preset — no subject breakdown available.'
      } else {
        curriculumMessage.value = ''
      }
    } else {
      curriculumMessage.value = data.message ?? 'No curriculum data found for this student.'
    }
  } catch {
    curriculumMessage.value = 'Could not load curriculum — enter units manually.'
  } finally {
    curriculumLoading.value = false
  }
}

watch([selectedStudent, () => form.semester], () => {
  if (selectedStudent.value && !selectedStudent.value.is_irregular) loadCurriculum()
})

// ─── Derived NSTP values ──────────────────────────────────────────────────────

// Always derived from the checkbox — never stale.
const nstpLecUnits = computed(() => hasNstp.value ? NSTP_UNITS : 0)

// Keep form.nstp_lec_units in sync for backend submission
watch(nstpLecUnits, (val) => { form.nstp_lec_units = val }, { immediate: true })

// ─── Live Fee Computation ─────────────────────────────────────────────────────
//
// Discount rules (mirrors AssessmentService::compute exactly):
//   discount < 100% : applies to ALL lec units including NSTP
//   discount = 100% : all billable lec units → ₱0, NSTP (1.5 units) charged at full price
//   lab + misc      : never discounted regardless
//
const rate = computed(() => props.feeRates.tuition_per_unit)

// Total lec units for display and billing = billable + NSTP
const totalLecUnits = computed(() => Number(form.lec_units) + nstpLecUnits.value)

// Raw tuition before any discount
const rawTotalTuition    = computed(() => totalLecUnits.value * rate.value)
const rawBillableTuition = computed(() => Number(form.lec_units) * rate.value)
const nstpTuition        = computed(() => nstpLecUnits.value * rate.value)

const pct = computed(() => Number(form.discount_percentage) || 0)

const discountSaving = computed(() => {
  if (pct.value === 100) {
    // 100% discount: entire billable (non-NSTP) tuition waived
    return rawBillableTuition.value
  }
  if (pct.value > 0) {
    // Partial: discount on ALL lec units including NSTP
    return Math.round(rawTotalTuition.value * (pct.value / 100) * 100) / 100
  }
  return 0
})

const tuitionFee = computed(() => {
  if (pct.value === 100) {
    // Only NSTP survives the 100% discount
    return nstpTuition.value
  }
  return Math.round((rawTotalTuition.value - discountSaving.value) * 100) / 100
})

const entrepreneurFee = computed(() =>
  Number(form.lab_units) > 0 ? (props.feeRates.entrepreneurship_fee ?? 600) : 0
)
const labFee  = computed(() => Number(form.lab_units) * props.feeRates.lab_fee_per_subject)
const miscFee = computed(() => props.feeRates.misc_total)

const totalAssessment = computed(() =>
  tuitionFee.value + labFee.value + entrepreneurFee.value + miscFee.value
)

const tuitionAndLab = computed(() =>
  tuitionFee.value + labFee.value + entrepreneurFee.value
)

// ─── Lecture Units Input ──────────────────────────────────────────────────────
//
// The input field displays totalLecUnits (billable + NSTP).
// When accounting types a new value, we back-calculate billable = typed - nstpLecUnits.
// This means the NSTP checkbox state at the moment of override determines the split.
// If NSTP is checked and accounting types 20 → billable stored as 18.5 (20 - 1.5).
// If NSTP is unchecked and accounting types 20 → billable stored as 20 (20 - 0).
//
const displayLecUnits = computed({
  get() {
    return totalLecUnits.value
  },
  set(val: number) {
    // Strip NSTP portion before storing billable units
    form.lec_units = Math.max(0, Number(val) - nstpLecUnits.value)
  },
})

// ─── Payment Terms ────────────────────────────────────────────────────────────

const tlTermNames = props.feeRates.payment_terms
  .filter((t) => t.term_name !== 'Upon Registration')
  .map((t) => t.term_name)

const editablePercentages = ref<Record<string, number>>(
  Object.fromEntries(
    props.feeRates.payment_terms
      .filter((t) => t.term_name !== 'Upon Registration')
      .map((t) => [t.term_name, t.percentage])
  )
)

const tlPercentageTotal = computed(() =>
  tlTermNames.reduce((sum, name) => sum + (Number(editablePercentages.value[name]) || 0), 0)
)

const paymentTermBreakdown = computed(() => {
  const tl   = tuitionAndLab.value
  const misc = miscFee.value
  let runningTL = 0

  return props.feeRates.payment_terms.map((t) => {
    let amount: number
    if (t.term_name === 'Upon Registration') {
      amount = misc
    } else if (tl === 0) {
      amount = 0
    } else {
      const termPct  = Number(editablePercentages.value[t.term_name]) || 0
      const tlIdx    = tlTermNames.indexOf(t.term_name)
      const isLastTL = tlIdx === tlTermNames.length - 1
      if (isLastTL) {
        amount = Math.round((tl - runningTL) * 100) / 100
      } else {
        amount = Math.round(tl * (termPct / 100) * 100) / 100
        runningTL += amount
      }
    }
    const displayPct = t.term_name === 'Upon Registration'
      ? null
      : (Number(editablePercentages.value[t.term_name]) || 0)
    return { term_name: t.term_name, term_order: t.term_order, percentage: displayPct, amount }
  })
})

// ─── Submit ───────────────────────────────────────────────────────────────────

function submit() {
  if (!selectedStudent.value) return
  if (hasRemainingBalance.value) return
  form.user_id          = selectedStudent.value.id
  form.nstp_lec_units   = nstpLecUnits.value   // 1.5 or 0
  form.term_percentages = { ...editablePercentages.value }

  form.post(route('student-fees.store'), {
    onError:  (errors) => console.error('[submit] validation errors:', errors),
    onSuccess: ()      => console.log('[submit] success'),
    onFinish: ()       => console.log('[submit] finished'),
  })
}

// ─── Paid History Helpers ─────────────────────────────────────────────────────

/** All distinct school years that appear in paid semesters, sorted desc */
const paidSchoolYears = computed(() => {
  const years = [...new Set(paidSemesters.value.map((ps) => ps.school_year))]
  return years.sort((a, b) => b.localeCompare(a))
})

const SEMESTERS: Array<'1st' | '2nd' | 'Summer'> = ['1st', '2nd', 'Summer']

function semLabel(s: string) {
  if (s === '1st') return '1st Semester'
  if (s === '2nd') return '2nd Semester'
  return 'Summer'
}
</script>

<template>
  <AppLayout>
    <div class="w-full p-6 space-y-6">
      <Breadcrumbs :items="breadcrumbs" />

      <div class="flex items-center gap-3">
        <Calculator class="h-6 w-6 text-blue-600" />
        <h1 class="text-2xl font-bold">New Student Assessment</h1>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

        <!-- ── LEFT: Form ─────────────────────────────────────────── -->
        <div class="xl:col-span-2 space-y-5">

          <!-- Student Selector -->
          <Card>
            <CardHeader>
              <CardTitle class="flex items-center gap-2 text-base">
                <User class="h-4 w-4" /> Student
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
              <div v-if="selectedStudent"
                class="flex items-center justify-between rounded-lg border bg-blue-50 dark:bg-blue-950 p-4">
                <div>
                  <p class="font-semibold text-blue-900 dark:text-blue-100">{{ selectedStudent.name }}</p>
                  <p class="text-sm text-blue-700 dark:text-blue-300">
                    {{ selectedStudent.account_id }} · {{ selectedStudent.course }} · {{ selectedStudent.year_level }}
                    <span v-if="selectedStudent.is_irregular"
                      class="ml-2 inline-flex items-center gap-1 text-xs font-medium text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">
                      <AlertTriangle class="h-3 w-3" /> Irregular
                    </span>
                    <span v-else
                      class="ml-2 inline-flex items-center gap-1 text-xs font-medium text-green-700 bg-green-100 px-2 py-0.5 rounded-full">
                      ✓ Regular
                    </span>
                  </p>
                </div>
                <Button variant="outline" size="sm" @click="clearStudent">Change</Button>
              </div>

              <div v-else class="relative">
                <div class="relative">
                  <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                  <Input
                    v-model="studentSearch"
                    class="pl-9"
                    placeholder="Search student name or account ID…"
                    @input="searchStudents"
                  />
                  <Loader2 v-if="searchLoading"
                    class="absolute right-3 top-1/2 -translate-y-1/2 h-4 w-4 animate-spin text-muted-foreground" />
                </div>
                <div v-if="searchResults.length > 0"
                  class="absolute z-20 mt-1 w-full rounded-md border bg-white dark:bg-zinc-900 shadow-lg">
                  <button
                    v-for="s in searchResults" :key="s.id"
                    class="w-full text-left px-4 py-3 hover:bg-accent transition-colors border-b last:border-0"
                    @click="selectStudent(s)"
                  >
                    <p class="font-medium text-sm flex items-center gap-2">
                      {{ s.name }}
                      <span v-if="s.is_irregular" class="text-xs text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded">Irregular</span>
                      <span v-if="s.paid_semesters?.length"
                        class="text-xs text-green-700 bg-green-100 px-1.5 py-0.5 rounded flex items-center gap-1">
                        <CheckCircle2 class="h-3 w-3" />
                        {{ s.paid_semesters.length }} sem{{ s.paid_semesters.length > 1 ? 's' : '' }} paid
                      </span>
                    </p>
                    <p class="text-xs text-muted-foreground">{{ s.account_id }} · {{ s.course }} · {{ s.year_level }}</p>
                  </button>
                </div>
                <p v-if="form.errors.user_id" class="text-sm text-destructive mt-1">
                  {{ form.errors.user_id }}
                </p>
              </div>
            </CardContent>
          </Card>

          <!-- ── Paid Semester History (shown only when student has paid semesters) ── -->
          <Card v-if="paidSemesters.length > 0" class="border-green-200 bg-green-50/40">
            <CardHeader class="pb-3">
              <CardTitle class="flex items-center gap-2 text-base text-green-800">
                <History class="h-4 w-4 text-green-600" />
                Completed Semesters
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
              <div v-for="year in paidSchoolYears" :key="year" class="space-y-1.5">
                <p class="text-xs font-semibold text-green-700 uppercase tracking-wide">
                  SY {{ year }}
                </p>
                <div class="flex flex-wrap gap-2">
                  <template v-for="sem in SEMESTERS" :key="sem">
                    <div
                      v-if="paidSemesters.some(ps => ps.semester === sem && ps.school_year === year)"
                      class="inline-flex items-center gap-1.5 rounded-full bg-green-100 border border-green-300 px-3 py-1 text-xs font-semibold text-green-800"
                    >
                      <CheckCircle2 class="h-3.5 w-3.5 text-green-600" />
                      {{ semLabel(sem) }}
                      <span class="text-green-600 font-normal">
                        · {{ formatCurrency(paidSemesters.find(ps => ps.semester === sem && ps.school_year === year)!.total_assessment) }}
                      </span>
                    </div>
                  </template>
                </div>
              </div>
              <p class="text-xs text-green-700/70 mt-1">
                New assessment auto-advanced to
                <span class="font-semibold">{{ semLabel(form.semester) }} · SY {{ form.school_year }}</span>.
                You may change the semester below if needed.
              </p>
            </CardContent>
          </Card>

          <!-- Semester / School Year -->
          <Card>
            <CardHeader>
              <CardTitle class="text-base">Enrollment Period</CardTitle>
            </CardHeader>
            <CardContent class="grid grid-cols-2 gap-4">
              <!-- Semester — styled button group replacing plain <select> so we can disable paid options -->
              <div class="space-y-1.5">
                <Label>Semester</Label>
                <div class="flex gap-2">
                  <template v-for="sem in SEMESTERS" :key="sem">
                    <button
                      type="button"
                      :disabled="isSemesterPaid(sem)"
                      :title="isSemesterPaid(sem) ? `${semLabel(sem)} (${form.school_year}) is already fully paid` : ''"
                      @click="!isSemesterPaid(sem) && (form.semester = sem)"
                      :class="[
                        'relative flex-1 rounded-md border px-3 py-2 text-sm font-medium transition-all',
                        isSemesterPaid(sem)
                          ? 'cursor-not-allowed border-green-300 bg-green-50 text-green-700 opacity-80'
                          : form.semester === sem
                            ? 'border-blue-500 bg-blue-500 text-white shadow-sm'
                            : 'border-input bg-background text-muted-foreground hover:bg-muted',
                      ]"
                    >
                      <!-- Paid checkmark badge -->
                      <span
                        v-if="isSemesterPaid(sem)"
                        class="absolute -top-1.5 -right-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-green-500 text-white"
                      >
                        <CheckCircle2 class="h-3 w-3" />
                      </span>
                      {{ sem === 'Summer' ? 'Summer' : sem + ' Sem' }}
                      <span v-if="isSemesterPaid(sem)" class="ml-1 text-xs font-normal opacity-75">Paid</span>
                    </button>
                  </template>
                </div>
                <!-- Warn if ALL semesters of this school year are paid -->
                <p
                  v-if="SEMESTERS.every(s => isSemesterPaid(s))"
                  class="flex items-center gap-1.5 text-xs text-amber-700 font-medium mt-1"
                >
                  <AlertTriangle class="h-3.5 w-3.5" />
                  All semesters for SY {{ form.school_year }} are paid. Please update the School Year.
                </p>
                <p v-if="form.errors.semester" class="text-sm text-destructive">{{ form.errors.semester }}</p>
              </div>

              <div class="space-y-1.5">
                <Label for="school_year">School Year</Label>
                <Input id="school_year" v-model="form.school_year" placeholder="e.g. 2025-2026" />
                <p v-if="form.errors.school_year" class="text-sm text-destructive">{{ form.errors.school_year }}</p>
              </div>
            </CardContent>
          </Card>

          <!-- Unit Breakdown Table -->
          <!--
            Lec Units column = totalLecUnits (billable + NSTP when checked).
            Total Units      = totalLecUnits + lab_units.
            This reflects exactly what the student is billed for.
          -->
          <div v-if="selectedStudent && (curriculumSubjects.length > 0 || form.lec_units > 0 || hasNstp)"
               class="rounded-xl border border-gray-200 bg-white overflow-hidden">
            <div class="px-5 py-3 bg-gray-50 border-b border-gray-200">
              <h3 class="text-sm font-semibold text-gray-700">Unit Breakdown</h3>
              <p class="text-xs text-gray-400 mt-0.5">
                {{ selectedStudent.course }} &middot; {{ selectedStudent.year_level }} &middot;
                {{ semLabel(form.semester) }} &middot; {{ form.school_year }}
              </p>
            </div>
            <table class="w-full text-sm">
              <thead class="text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200">
                <tr>
                  <th class="text-left px-5 py-2">Course</th>
                  <th class="text-left px-5 py-2">Year Level</th>
                  <th class="text-left px-5 py-2">Semester</th>
                  <th class="text-center px-4 py-2">Lec Units</th>
                  <th class="text-center px-4 py-2">Lab Units</th>
                  <th class="text-center px-4 py-2">Lab Subjects</th>
                  <th class="text-center px-4 py-2">Total Units</th>
                </tr>
              </thead>
              <tbody>
                <tr class="border-t border-gray-100">
                  <td class="px-5 py-3 text-gray-700">{{ selectedStudent.course }}</td>
                  <td class="px-5 py-3 text-gray-700">{{ selectedStudent.year_level }}</td>
                  <td class="px-5 py-3 text-gray-700">{{ semLabel(form.semester) }}</td>
                  <!-- Lec Units: billable + NSTP (1.5) when NSTP is active -->
                  <td class="px-4 py-3 text-center font-mono font-semibold text-gray-900">
                    {{ totalLecUnits }}
                    <span v-if="hasNstp" class="block text-xs font-normal text-amber-600">
                      {{ form.lec_units }} + 1.5 NSTP
                    </span>
                  </td>
                  <td class="px-4 py-3 text-center font-mono text-gray-900">{{ form.lab_units }}</td>
                  <td class="px-4 py-3 text-center font-mono text-gray-900">{{ form.lab_units }}</td>
                  <!-- Total = billable lec + NSTP (if active) + lab -->
                  <td class="px-4 py-3 text-center font-mono font-bold text-blue-700">
                    {{ totalLecUnits + Number(form.lab_units) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Irregular student notice -->
          <div v-if="selectedStudent?.is_irregular"
            class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <AlertTriangle class="h-5 w-5 shrink-0 text-amber-600 mt-0.5" />
            <div>
              <p class="font-semibold">Irregular Student</p>
              <p class="text-amber-800 text-xs mt-0.5">
                Curriculum auto-populate is disabled. Enter lecture units and lab subjects manually.
                Use the NSTP checkbox below if this student is enrolled in NSTP.
              </p>
            </div>
          </div>

          <!-- Units Input + NSTP Checkbox -->
          <Card>
            <CardHeader>
              <CardTitle class="flex items-center gap-2 text-base">
                <BookOpen class="h-4 w-4" />
                Units Enrolled
                <span class="ml-auto text-xs font-normal text-muted-foreground">
                  Auto-filled from curriculum — override if needed
                </span>
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-5">

              <div class="grid grid-cols-2 gap-6">
                <!-- Lecture Units input -->
                <div class="space-y-1.5">
                  <Label for="lec_units" class="flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-blue-500 inline-block"></span>
                    Lecture Units
                    <!-- Label is conditional: only mention NSTP when it's actually active -->
                    <span v-if="hasNstp" class="text-xs text-amber-600 font-medium">(incl. 1.5 NSTP)</span>
                    <span v-else class="text-xs text-muted-foreground">(billable only)</span>
                  </Label>
                  <Input id="lec_units" type="number"
                    v-model.number="displayLecUnits"
                    min="0" max="50" step="0.5" class="text-center text-lg font-semibold" />
                  <p class="text-xs text-muted-foreground text-center">
                    {{ totalLecUnits }} units × {{ formatCurrency(feeRates.tuition_per_unit) }} / unit
                  </p>
                  <p v-if="form.errors.lec_units" class="text-sm text-destructive">{{ form.errors.lec_units }}</p>
                </div>

                <!-- Lab Subjects input -->
                <div class="space-y-1.5">
                  <Label for="lab_units" class="flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-orange-500 inline-block"></span>
                    Lab Subjects
                    <span class="text-xs text-muted-foreground">(subjects with lab)</span>
                  </Label>
                  <Input id="lab_units" type="number" v-model.number="form.lab_units"
                    min="0" max="20" class="text-center text-lg font-semibold" />
                  <p class="text-xs text-muted-foreground text-center">× {{ formatCurrency(feeRates.lab_fee_per_subject) }} / subject</p>
                  <p v-if="form.errors.lab_units" class="text-sm text-destructive">{{ form.errors.lab_units }}</p>
                </div>
              </div>

              <!-- NSTP Checkbox -->
              <div class="rounded-lg border"
                   :class="hasNstp ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-gray-50'">
                <label for="nstp_checkbox"
                       class="flex items-start gap-3 px-4 py-3 cursor-pointer select-none">
                  <input
                    id="nstp_checkbox"
                    type="checkbox"
                    v-model="hasNstp"
                    class="mt-0.5 h-4 w-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500 cursor-pointer"
                  />
                  <div class="flex-1">
                    <p class="text-sm font-semibold"
                       :class="hasNstp ? 'text-amber-900' : 'text-gray-700'">
                      NSTP — National Service Training Program
                    </p>
                    <p class="text-xs mt-0.5"
                       :class="hasNstp ? 'text-amber-700' : 'text-muted-foreground'">
                      <template v-if="hasNstp">
                        <strong>Checked:</strong> 1.5 NSTP units ({{ formatCurrency(nstpTuition) }}) are included in billing.
                        For partial discounts, NSTP is discounted along with all other lecture units.
                        At 100% discount, NSTP is excluded and charged at full price ({{ formatCurrency(nstpTuition) }}).
                      </template>
                      <template v-else>
                        Check if this course / year level / semester includes an NSTP subject.
                        NSTP is billed at a fixed 1.5 units regardless of the subject's listed unit count.
                      </template>
                    </p>
                  </div>
                  <!-- Live NSTP fee indicator when checked -->
                  <div v-if="hasNstp" class="shrink-0 text-right">
                    <p class="text-xs font-mono font-semibold text-amber-700">
                      + {{ formatCurrency(nstpTuition) }}
                    </p>
                    <p class="text-xs text-amber-600">1.5 units</p>
                  </div>
                </label>
              </div>

            </CardContent>
          </Card>

          <!-- ── Discount / Scholarship ────────────────────────────────────── -->
          <Card>
            <CardHeader>
              <CardTitle class="text-base flex items-center gap-2">
                <span class="text-amber-600">🎓</span>
                Scholarship / Discount
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">

              <!-- NSTP 100% discount notice -->
              <div
                v-if="hasNstp && pct === 100"
                class="flex items-start gap-2 rounded-md bg-amber-50 border border-amber-300 p-3 text-sm text-amber-900"
              >
                <AlertTriangle class="h-4 w-4 mt-0.5 shrink-0 text-amber-600" />
                <div>
                  <p class="font-semibold">100% Discount — NSTP Exception</p>
                  <p class="text-xs text-amber-800 mt-0.5">
                    All billable lecture units ({{ form.lec_units }}) are fully discounted to ₱0.
                    NSTP (1.5 units, {{ formatCurrency(nstpTuition) }}) is excluded from the 100% discount
                    and will be charged at full price.
                  </p>
                </div>
              </div>

              <div class="space-y-3">
                <Label for="discount_percentage">Discount Percentage (%)</Label>
                <p class="text-xs text-muted-foreground -mt-2">
                  <template v-if="hasNstp">
                    For partial discounts (&lt;100%): applies to all lecture units including NSTP ({{ totalLecUnits }} total).
                    At exactly 100%: all billable units waived, NSTP ({{ formatCurrency(nstpTuition) }}) charged at full price.
                    Lab and misc fees are never discounted.
                  </template>
                  <template v-else>
                    Applies to all lecture units ({{ form.lec_units }} units). Lab and miscellaneous fees are never discounted.
                  </template>
                </p>

                <div class="flex gap-1.5 flex-wrap">
                  <button
                    v-for="preset in [0, 10, 20, 25, 50, 75, 100]"
                    :key="preset"
                    type="button"
                    @click="form.discount_percentage = preset"
                    :class="[
                      'px-3 py-1.5 rounded-md text-xs font-medium border transition-colors',
                      form.discount_percentage === preset
                        ? 'bg-amber-500 text-white border-amber-500 shadow-sm'
                        : 'bg-background border-input text-muted-foreground hover:bg-muted'
                    ]"
                  >
                    {{ preset === 0 ? 'No discount' : preset + '%' }}
                  </button>
                </div>

                <div class="flex items-center gap-3">
                  <Input
                    id="discount_percentage"
                    type="number"
                    v-model.number="form.discount_percentage"
                    min="0"
                    max="100"
                    step="0.01"
                    placeholder="0.00"
                    class="w-28 text-center text-lg font-semibold"
                  />
                  <span class="text-sm text-muted-foreground">% off lecture units</span>
                </div>
                <p v-if="form.errors.discount_percentage" class="text-sm text-destructive">
                  {{ form.errors.discount_percentage }}
                </p>
              </div>

              <!-- Discount breakdown panel -->
              <div
                v-if="pct > 0"
                class="rounded-md bg-green-50 border border-green-200 p-3 space-y-1.5 text-sm"
              >
                <p class="font-semibold text-xs uppercase tracking-wide text-green-700 mb-2">
                  Effective Fees After Discount
                </p>

                <!-- Partial discount (< 100%): NSTP included in discount -->
                <template v-if="pct < 100">
                  <template v-if="hasNstp">
                    <div class="flex justify-between text-green-800 text-xs">
                      <span>Billable tuition ({{ form.lec_units }} units × {{ formatCurrency(rate) }})</span>
                      <span>{{ formatCurrency(rawBillableTuition) }}</span>
                    </div>
                    <div class="flex justify-between text-amber-700 text-xs">
                      <span>NSTP tuition (1.5 units × {{ formatCurrency(rate) }})</span>
                      <span>{{ formatCurrency(nstpTuition) }}</span>
                    </div>
                    <div class="flex justify-between text-green-800 text-xs font-medium border-t border-green-100 pt-1">
                      <span>Total tuition before discount ({{ totalLecUnits }} units)</span>
                      <span>{{ formatCurrency(rawTotalTuition) }}</span>
                    </div>
                  </template>
                  <template v-else>
                    <div class="flex justify-between text-green-800 text-xs">
                      <span>Total tuition ({{ form.lec_units }} units × {{ formatCurrency(rate) }})</span>
                      <span>{{ formatCurrency(rawTotalTuition) }}</span>
                    </div>
                  </template>
                  <div class="flex justify-between text-green-600 text-xs">
                    <span>− {{ pct }}% discount ({{ hasNstp ? `applied to all ${totalLecUnits} units incl. NSTP` : `applied to ${form.lec_units} units` }})</span>
                    <span>− {{ formatCurrency(discountSaving) }}</span>
                  </div>
                  <div class="flex justify-between text-green-900 font-medium pt-1 border-t border-green-200">
                    <span>Total Tuition</span>
                    <span>{{ formatCurrency(tuitionFee) }}</span>
                  </div>
                </template>

                <!-- 100% discount: billable lec units → ₱0, NSTP survives if checked -->
                <template v-else>
                  <div class="flex justify-between text-green-800 text-xs">
                    <span>Billable tuition ({{ form.lec_units }} units × {{ formatCurrency(rate) }})</span>
                    <span>{{ formatCurrency(rawBillableTuition) }}</span>
                  </div>
                  <div class="flex justify-between text-green-600 text-xs">
                    <span>− 100% discount (full waiver on {{ form.lec_units }} billable units)</span>
                    <span>− {{ formatCurrency(discountSaving) }}</span>
                  </div>
                  <template v-if="hasNstp">
                    <div class="flex justify-between text-amber-800 text-xs font-medium">
                      <span>NSTP (1.5 units — excluded from 100% discount)</span>
                      <span>{{ formatCurrency(nstpTuition) }}</span>
                    </div>
                  </template>
                  <div class="flex justify-between text-green-900 font-medium pt-1 border-t border-green-200">
                    <span>Total Tuition</span>
                    <span>{{ formatCurrency(tuitionFee) }}</span>
                  </div>
                </template>

                <div class="flex justify-between text-green-900 pt-1">
                  <span>Lab Fee ({{ form.lab_units }} subjects)</span>
                  <span class="font-semibold">{{ formatCurrency(labFee) }}</span>
                </div>
                <div v-if="entrepreneurFee > 0" class="flex justify-between text-green-900">
                  <span>Entrepreneurship Fee</span>
                  <span class="font-semibold">{{ formatCurrency(entrepreneurFee) }}</span>
                </div>
                <div class="flex justify-between text-green-900">
                  <span>Miscellaneous Fee</span>
                  <span class="font-semibold">{{ formatCurrency(miscFee) }}</span>
                </div>
                <div class="border-t border-green-300 pt-2 flex justify-between font-bold text-green-900 text-base">
                  <span>Total Assessment</span>
                  <span>{{ formatCurrency(totalAssessment) }}</span>
                </div>
              </div>

            </CardContent>
          </Card>

          <!-- Cannot Create Assessment Warning -->
          <div v-if="selectedStudent && hasRemainingBalance"
               class="flex items-start gap-3 rounded-lg border-2 border-red-400 bg-red-50 px-4 py-4 text-sm">
            <AlertTriangle class="h-5 w-5 shrink-0 text-red-600 mt-0.5" />
            <div class="flex-1">
              <p class="font-bold text-red-800">Cannot Create Assessment — Unsettled Balance</p>
              <p class="text-red-700 mt-1">
                This student has an outstanding balance of
                <span class="font-bold">{{ formatCurrency(selectedStudent.remaining_balance) }}</span>.
                The remaining balance must be fully settled before a new assessment can be created.
              </p>
              <p class="text-xs text-red-600 mt-2">Go to the student's profile to record a payment, then return here.</p>
              <div class="mt-3">
                <Button variant="outline" size="sm"
                        class="border-red-400 text-red-700 hover:bg-red-100"
                        @click="router.visit(route('student-fees.show', selectedStudent.id))">
                  View Student Profile &amp; Record Payment
                </Button>
              </div>
            </div>
          </div>

          <!-- Submit -->
          <div class="flex gap-3 justify-end">
            <Button variant="outline" @click="router.visit(route('student-fees.index'))">Cancel</Button>
            <button
              type="button"
              :disabled="form.processing || !selectedStudent || totalAssessment === 0 || hasRemainingBalance || isSemesterPaid(form.semester)"
              class="inline-flex items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow-xs transition-all hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
              @click.prevent="submit"
            >
              <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" />
              <CheckCircle2 v-else class="h-4 w-4" />
              {{ form.processing ? 'Saving…' : 'Create Assessment' }}
            </button>
          </div>

        </div>

        <!-- ── RIGHT: Live Fee Preview ──────────────────────────────── -->
        <div class="space-y-4">
          <Card class="sticky top-6">
            <CardHeader>
              <CardTitle class="text-base flex items-center gap-2">
                <Calculator class="h-4 w-4" /> Fee Breakdown
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-3 text-sm">

              <div class="space-y-2">
                <div class="flex justify-between">
                  <span class="text-muted-foreground">
                    Tuition ({{ totalLecUnits }} lec × {{ formatCurrency(feeRates.tuition_per_unit) }})
                  </span>
                  <span class="font-medium">{{ formatCurrency(tuitionFee) }}</span>
                </div>
                <!-- NSTP sub-line in fee sidebar -->
                <div v-if="hasNstp" class="flex justify-between text-xs text-amber-600 pl-2">
                  <span>incl. 1.5 NSTP units</span>
                  <span>{{ formatCurrency(nstpTuition) }}</span>
                </div>
                <div v-if="discountSaving > 0" class="flex justify-between text-xs text-green-600 pl-2">
                  <span>− {{ pct }}% discount saved</span>
                  <span>− {{ formatCurrency(discountSaving) }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-muted-foreground">
                    Lab Fee ({{ form.lab_units }} subj × {{ formatCurrency(feeRates.lab_fee_per_subject) }})
                  </span>
                  <span class="font-medium">{{ formatCurrency(labFee) }}</span>
                </div>
                <div v-if="entrepreneurFee > 0" class="flex justify-between">
                  <span class="text-muted-foreground">Entrepreneurship Fee</span>
                  <span class="font-medium">{{ formatCurrency(entrepreneurFee) }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-muted-foreground">Miscellaneous (fixed)</span>
                  <span class="font-medium">{{ formatCurrency(miscFee) }}</span>
                </div>
              </div>

              <div class="border-t pt-2 flex justify-between font-bold text-base">
                <span>Total Assessment</span>
                <span class="text-blue-600">{{ formatCurrency(totalAssessment) }}</span>
              </div>

              <div v-if="totalAssessment > 0" class="mt-3 border-t pt-3">
                <p class="text-xs font-semibold uppercase text-muted-foreground mb-2">
                  Payment Schedule ({{ feeRates.payment_terms.length }} terms)
                </p>
                <div class="space-y-1.5">
                  <div
                    v-for="term in paymentTermBreakdown"
                    :key="term.term_order"
                    class="flex items-center justify-between text-xs gap-2"
                  >
                    <span v-if="term.term_name === 'Upon Registration'" class="text-muted-foreground flex-1">
                      {{ term.term_name }}
                    </span>
                    <template v-else>
                      <span class="text-muted-foreground flex-1">{{ term.term_name }}</span>
                      <input
                        type="number"
                        :value="editablePercentages[term.term_name]"
                        @change="editablePercentages[term.term_name] = Math.max(0, Math.min(100, Number(($event.target as HTMLInputElement).value)))"
                        min="0" max="100" step="0.01"
                        class="w-14 text-right border border-input rounded px-1 py-0.5 text-xs bg-background text-foreground"
                      /><span class="text-muted-foreground">%</span>
                    </template>
                    <span class="font-medium ml-1">{{ formatCurrency(term.amount) }}</span>
                  </div>
                </div>
                <p v-if="tlPercentageTotal !== 100" class="mt-2 text-xs text-destructive font-medium">
                  ⚠ Percentages sum to {{ tlPercentageTotal }}% — must total 100%
                </p>
              </div>

              <div v-else class="text-center py-6 text-muted-foreground text-sm">
                Select a student and semester to compute fees.
              </div>
            </CardContent>
          </Card>

          <!-- Misc Breakdown -->
          <Card v-if="feeRates.misc_items.length > 0" class="bg-muted/50">
            <CardContent class="pt-4 space-y-1 text-xs">
              <p class="font-semibold text-foreground text-sm mb-2">Miscellaneous Breakdown</p>
              <div v-for="item in feeRates.misc_items" :key="item.id" class="flex justify-between text-muted-foreground">
                <span>{{ item.label }}</span>
                <span>{{ formatCurrency(item.amount) }}</span>
              </div>
              <div class="flex justify-between font-semibold text-foreground border-t pt-1 mt-1">
                <span>Total Misc</span>
                <span>{{ formatCurrency(feeRates.misc_total) }}</span>
              </div>
            </CardContent>
          </Card>

          <!-- Rate Info -->
          <Card class="bg-muted/50">
            <CardContent class="pt-4 space-y-1 text-xs text-muted-foreground">
              <p class="font-semibold text-foreground text-sm mb-2">Current Rates (AY 2025-2026)</p>
              <div class="flex justify-between">
                <span>Per lecture unit:</span>
                <span>{{ formatCurrency(feeRates.tuition_per_unit) }}</span>
              </div>
              <div class="flex justify-between">
                <span>Per lab subject:</span>
                <span>{{ formatCurrency(feeRates.lab_fee_per_subject) }}</span>
              </div>
              <div class="flex justify-between font-medium text-foreground">
                <span>Misc (fixed):</span>
                <span>{{ formatCurrency(feeRates.misc_total) }}</span>
              </div>
              <p class="pt-2 opacity-70">Rates are live from Fee Settings.</p>
            </CardContent>
          </Card>
        </div>

      </div>
    </div>
  </AppLayout>
</template>