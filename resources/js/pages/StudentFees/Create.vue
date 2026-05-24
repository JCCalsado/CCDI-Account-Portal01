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
  year_level: string | null
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

// ─── Year Level Progression ───────────────────────────────────────────────────

const YEAR_LEVEL_MAP: Record<string, string> = {
  '1st Year': '2nd Year',
  '2nd Year': '3rd Year',
  '3rd Year': '4th Year',
  '4th Year': '5th Year',
}

function advanceYearLevel(current: string): string {
  return YEAR_LEVEL_MAP[current] ?? current
}

// ─── Paid Semester Logic ──────────────────────────────────────────────────────

const SEM_ORDER: Record<string, number> = { '1st': 1, '2nd': 2, 'Summer': 3 }

function computeNextSemesterAndYear(
  paid: PaidSemester[],
  studentYearLevel: string,
): { semester: '1st' | '2nd' | 'Summer'; school_year: string; year_level: string } {
  const currentYear = new Date().getFullYear()
  const defaultYear = `${currentYear}-${currentYear + 1}`

  if (!paid.length) {
    return { semester: '1st', school_year: defaultYear, year_level: studentYearLevel }
  }

  const sorted = [...paid].sort((a, b) => {
    if (a.school_year !== b.school_year) return a.school_year.localeCompare(b.school_year)
    return (SEM_ORDER[a.semester] ?? 99) - (SEM_ORDER[b.semester] ?? 99)
  })

  const last = sorted[sorted.length - 1]

  if (last.semester === '1st') {
    return {
      semester:    '2nd',
      school_year: last.school_year,
      year_level:  last.year_level ?? studentYearLevel,
    }
  }

  const [startStr] = last.school_year.split('-')
  const startYear  = parseInt(startStr, 10)
  const lastYl     = last.year_level ?? studentYearLevel

  return {
    semester:    '1st',
    school_year: `${startYear + 1}-${startYear + 2}`,
    year_level:  advanceYearLevel(lastYl),
  }
}

// ─── Derive initial values BEFORE form is declared ───────────────────────────
// This block runs once synchronously. We compute the initial semester /
// school_year / year_level from paid history so we can pass them directly
// into useForm() — eliminating the need to mutate form fields afterward and
// avoiding any reference-before-initialization risk.

const _currentYear = new Date().getFullYear()
const _defaultYear = `${_currentYear}-${_currentYear + 1}`

const _initial = props.preselectedStudent
  ? computeNextSemesterAndYear(
      props.preselectedStudent.paid_semesters ?? [],
      props.preselectedStudent.year_level,
    )
  : { semester: '1st' as const, school_year: _defaultYear, year_level: '' }

// ─── Form ─────────────────────────────────────────────────────────────────────
// CRITICAL: form must be declared BEFORE any function or watcher that
// references it. isSemesterPaid(), getPaidRecord(), and loadCurriculum()
// all close over `form`. The { immediate: true } watchers below fire
// synchronously during setup — if form isn't initialized yet, Vue throws
// "Cannot access 'form' before initialization" (TDZ error).

const form = useForm({
  user_id:             props.preselectedStudent?.id ?? 0,
  semester:            _initial.semester,
  school_year:         _initial.school_year,
  year_level:          _initial.year_level,
  lec_units:           0,
  lab_units:           0,
  nstp_lec_units:      0,
  discount_percentage: 0 as number,
  term_percentages:    {} as Record<string, number>,
})

// ─── Reactive State ───────────────────────────────────────────────────────────

const studentSearch   = ref('')
const searchResults   = ref<PreselectedStudent[]>([])
const searchLoading   = ref(false)
const selectedStudent = ref<PreselectedStudent | null>(props.preselectedStudent ?? null)
const paidSemesters   = ref<PaidSemester[]>(props.preselectedStudent?.paid_semesters ?? [])

const computedYearLevel = ref<string>(_initial.year_level)

const hasRemainingBalance = computed(
  () => (selectedStudent.value?.remaining_balance ?? 0) > 0,
)

// ─── Paid Semester Helpers ────────────────────────────────────────────────────
// These functions reference `form` — safe now because form is declared above.

function isSemesterPaid(semester: string): boolean {
  return paidSemesters.value.some(
    (ps) => ps.semester === semester && ps.school_year === form.school_year,
  )
}

function getPaidRecord(semester: string): PaidSemester | null {
  return paidSemesters.value.find(
    (ps) => ps.semester === semester && ps.school_year === form.school_year,
  ) ?? null
}

// ─── Student Search ───────────────────────────────────────────────────────────

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
  curriculumSubjects.value = []
  curriculumMessage.value  = ''
  hasNstp.value            = false

  const next              = computeNextSemesterAndYear(paidSemesters.value, student.year_level)
  form.user_id            = student.id
  form.semester           = next.semester
  form.school_year        = next.school_year
  form.year_level         = next.year_level
  computedYearLevel.value = next.year_level
}

function clearStudent() {
  selectedStudent.value     = null
  paidSemesters.value       = []
  computedYearLevel.value   = ''
  form.user_id              = 0
  form.year_level           = ''
  form.lec_units            = 0
  form.lab_units            = 0
  curriculumSubjects.value  = []
  curriculumMessage.value   = ''
  hasNstp.value             = false
}

// ─── Curriculum Auto-Populate ─────────────────────────────────────────────────

const curriculumLoading  = ref(false)
const curriculumSubjects = ref<CurriculumSubject[]>([])
const curriculumMessage  = ref('')

// ── NSTP state ────────────────────────────────────────────────────────────────
const hasNstp = ref(false)

const NSTP_UNITS = 1.5

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
    const url = route('student-fees.curriculum-units')
      + '?student_id=' + student.id
      + '&semester='   + encodeURIComponent(form.semester)
      + '&year_level=' + encodeURIComponent(computedYearLevel.value || student.year_level)

    const res  = await fetch(url)
    const data = await res.json()

    if (data.found) {
      curriculumSubjects.value = data.subjects
      form.lec_units           = data.billable_lec_units
      form.lab_units           = data.lab_subject_count
      hasNstp.value            = data.has_nstp ?? false

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

// Safe: form is initialized above, so these watchers can fire immediately.
watch([selectedStudent, () => form.semester], () => {
  if (selectedStudent.value && !selectedStudent.value.is_irregular) loadCurriculum()
}, { immediate: true })

// ─── Derived NSTP values ──────────────────────────────────────────────────────

const nstpLecUnits = computed(() => hasNstp.value ? NSTP_UNITS : 0)

// Safe: form is initialized above.
watch(nstpLecUnits, (val) => { form.nstp_lec_units = val }, { immediate: true })

// ─── Live Fee Computation ─────────────────────────────────────────────────────

const rate = computed(() => props.feeRates.tuition_per_unit)

const totalLecUnits = computed(() => Number(form.lec_units) + nstpLecUnits.value)

const rawTotalTuition    = computed(() => totalLecUnits.value * rate.value)
const rawBillableTuition = computed(() => Number(form.lec_units) * rate.value)
const nstpTuition        = computed(() => nstpLecUnits.value * rate.value)

const pct = computed(() => Number(form.discount_percentage) || 0)

const discountSaving = computed(() => {
  if (pct.value === 100) return rawBillableTuition.value
  if (pct.value > 0) return Math.round(rawTotalTuition.value * (pct.value / 100) * 100) / 100
  return 0
})

const tuitionFee = computed(() => {
  if (pct.value === 100) return nstpTuition.value
  return Math.round((rawTotalTuition.value - discountSaving.value) * 100) / 100
})

const entrepreneurFee = computed(() =>
  Number(form.lab_units) > 0 ? (props.feeRates.entrepreneurship_fee ?? 600) : 0,
)
const labFee  = computed(() => Number(form.lab_units) * props.feeRates.lab_fee_per_subject)
const miscFee = computed(() => props.feeRates.misc_total)

const totalAssessment = computed(() =>
  tuitionFee.value + labFee.value + entrepreneurFee.value + miscFee.value,
)

const tuitionAndLab = computed(() =>
  tuitionFee.value + labFee.value + entrepreneurFee.value,
)

// ─── Lecture Units Input ──────────────────────────────────────────────────────

const displayLecUnits = computed({
  get() {
    return totalLecUnits.value
  },
  set(val: number) {
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
      .map((t) => [t.term_name, t.percentage]),
  ),
)

const tlPercentageTotal = computed(() =>
  tlTermNames.reduce((sum, name) => sum + (Number(editablePercentages.value[name]) || 0), 0),
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

const submitting = ref(false)

function submit() {
  if (!selectedStudent.value) return
  if (hasRemainingBalance.value) return
  if (submitting.value) return

  submitting.value = true

  form.user_id          = selectedStudent.value.id
  form.year_level       = computedYearLevel.value || selectedStudent.value.year_level
  form.nstp_lec_units   = nstpLecUnits.value
  form.term_percentages = { ...editablePercentages.value }

  form.post(route('student-fees.store'), {
    onError:  (errors) => console.error('[submit] validation errors:', errors),
    onSuccess: ()      => console.log('[submit] success'),
    onFinish: ()      => {
      submitting.value = false
    },
  })
}

// ─── Paid History Helpers ─────────────────────────────────────────────────────

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
                    <span class="font-medium text-blue-500 dark:text-blue-400 text-xs uppercase tracking-wide mr-0.5">Acct. Id.</span>
                    {{ selectedStudent.account_id }}
                    &nbsp;·&nbsp;{{ selectedStudent.course }}
                    &nbsp;·&nbsp;{{ computedYearLevel || selectedStudent.year_level }}
                    <span v-if="selectedStudent.is_irregular"
                      class="ml-2 inline-flex items-center gap-1 text-xs font-medium text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">
                      <AlertTriangle class="h-3 w-3" /> Irregular
                    </span>
                    <span v-else
                      class="ml-2 inline-flex items-center gap-1 text-xs font-medium text-green-700 bg-green-100 px-2 py-0.5 rounded-full">
                      ✓ Regular
                    </span>
                  </p>
                  <p
                    v-if="computedYearLevel && computedYearLevel !== selectedStudent.year_level"
                    class="mt-1 text-xs text-amber-700 font-medium flex items-center gap-1"
                  >
                    <Info class="h-3 w-3" />
                    Year level auto-advanced to
                    <span class="font-bold">{{ computedYearLevel }}</span>
                    for this assessment.
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
                    <p class="text-xs text-muted-foreground">
                      <span class="font-medium">Acct. Id.</span> {{ s.account_id }}
                      &nbsp;·&nbsp;{{ s.course }}
                      &nbsp;·&nbsp;{{ s.year_level }}
                    </p>
                  </button>
                </div>
                <p v-if="form.errors.user_id" class="text-sm text-destructive mt-1">
                  {{ form.errors.user_id }}
                </p>
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

          <!-- ── Paid Semester History ── -->
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

          <!-- ── Curriculum / Unit Breakdown ──────────────────────── -->

          <!-- Loading skeleton -->
          <div v-if="curriculumLoading"
               class="rounded-xl border border-gray-200 bg-white overflow-hidden">
            <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 flex items-center gap-2">
              <Loader2 class="h-4 w-4 animate-spin text-blue-500" />
              <span class="text-sm font-semibold text-gray-600">Loading curriculum subjects…</span>
            </div>
            <div class="p-5 space-y-2">
              <div v-for="i in 5" :key="i"
                   class="h-8 rounded bg-gray-100 animate-pulse"
                   :style="{ width: (75 + i * 4) + '%' }" />
            </div>
          </div>

          <!-- Subject Table — shown when subjects array is populated -->
          <div v-else-if="selectedStudent && curriculumSubjects.length > 0"
               class="rounded-xl border border-gray-200 bg-white overflow-hidden">

            <!-- Header -->
            <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
              <div>
                <h3 class="text-sm font-semibold text-gray-700 flex items-center gap-2">
                  <BookOpen class="h-4 w-4 text-blue-500" />
                  Curriculum Subjects
                </h3>
                <p class="text-xs text-gray-400 mt-0.5">
                  {{ selectedStudent.course }}
                  &middot;
                  <span :class="computedYearLevel !== selectedStudent.year_level ? 'text-amber-600 font-medium' : ''">
                    {{ computedYearLevel || selectedStudent.year_level }}
                  </span>
                  &middot; {{ semLabel(form.semester) }}
                  &middot; {{ form.school_year }}
                </p>
              </div>
              <div class="text-right">
                <p class="text-xs text-gray-500">
                  {{ curriculumSubjects.length }} subject{{ curriculumSubjects.length !== 1 ? 's' : '' }}
                </p>
                <p class="text-xs font-semibold text-blue-700">
                  {{ totalLecUnits }} total units
                </p>
              </div>
            </div>

            <!-- Subject rows -->
            <table class="w-full text-sm">
              <thead class="text-xs uppercase tracking-wide text-gray-500 bg-gray-50 border-b border-gray-200">
                <tr>
                  <th class="text-left px-5 py-2 w-24">Code</th>
                  <th class="text-left px-5 py-2">Subject</th>
                  <th class="text-center px-4 py-2 w-20">Lec</th>
                  <th class="text-center px-4 py-2 w-20">Lab</th>
                  <th class="text-center px-4 py-2 w-24">Total</th>
                  <th class="text-center px-4 py-2 w-28">Status</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr
                  v-for="subj in curriculumSubjects"
                  :key="subj.id"
                  :class="[
                    'transition-colors',
                    subj.is_nstp    ? 'bg-amber-50/60' :
                    subj.is_pathfit ? 'bg-purple-50/60' :
                    subj.is_billable ? 'bg-white hover:bg-gray-50/60' :
                    'bg-gray-50/40'
                  ]"
                >
                  <td class="px-5 py-2.5 font-mono text-xs font-semibold text-gray-700">
                    {{ subj.code }}
                  </td>
                  <td class="px-5 py-2.5 text-gray-800">{{ subj.name }}</td>
                  <td class="px-4 py-2.5 text-center font-mono text-gray-700">
                    {{ subj.lec_units || '—' }}
                  </td>
                  <td class="px-4 py-2.5 text-center font-mono text-gray-700">
                    {{ subj.lab_units || '—' }}
                  </td>
                  <td class="px-4 py-2.5 text-center font-mono font-semibold text-gray-900">
                    {{ subj.total_units }}
                  </td>
                  <td class="px-4 py-2.5 text-center">
                    <span v-if="subj.is_nstp"
                      class="inline-flex items-center gap-1 rounded-full bg-amber-100 border border-amber-300 px-2 py-0.5 text-xs font-semibold text-amber-800">
                      NSTP
                    </span>
                    <span v-else-if="subj.is_pathfit"
                      class="inline-flex items-center gap-1 rounded-full bg-purple-100 border border-purple-300 px-2 py-0.5 text-xs font-semibold text-purple-800">
                      PATHFIT
                    </span>
                    <span v-else-if="subj.is_billable"
                      class="inline-flex items-center gap-1 rounded-full bg-green-100 border border-green-300 px-2 py-0.5 text-xs font-semibold text-green-800">
                      <CheckCircle2 class="h-3 w-3" /> Billable
                    </span>
                    <span v-else
                      class="inline-flex rounded-full bg-gray-100 border border-gray-200 px-2 py-0.5 text-xs text-gray-500">
                      Non-billable
                    </span>
                  </td>
                </tr>
              </tbody>

              <!-- Totals footer -->
              <tfoot class="bg-gray-50 border-t-2 border-gray-200">
                <tr>
                  <td colspan="2" class="px-5 py-2.5 text-xs font-semibold text-gray-600 uppercase tracking-wide">
                    Billing Summary
                  </td>
                  <td class="px-4 py-2.5 text-center font-mono font-bold text-blue-700">
                    {{ totalLecUnits }}
                    <span v-if="hasNstp" class="block text-xs font-normal text-amber-600">
                      {{ form.lec_units }} + 1.5 NSTP
                    </span>
                  </td>
                  <td class="px-4 py-2.5 text-center font-mono font-bold text-gray-700">
                    {{ form.lab_units }}
                  </td>
                  <td class="px-4 py-2.5 text-center font-mono font-bold text-blue-700">
                    {{ totalLecUnits + Number(form.lab_units) }}
                  </td>
                  <td class="px-4 py-2.5 text-center text-xs text-gray-500">
                    {{ form.lab_units }} lab subj
                  </td>
                </tr>
                <tr class="border-t border-gray-200">
                  <td colspan="6" class="px-5 py-2 text-xs text-gray-500">
                    <span class="inline-flex items-center gap-3 flex-wrap">
                      <span class="flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full bg-green-400 inline-block"></span>
                        Billable lec units: <strong class="text-gray-700">{{ form.lec_units }}</strong>
                      </span>
                      <span v-if="hasNstp" class="flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full bg-amber-400 inline-block"></span>
                        NSTP: <strong class="text-amber-700">1.5 units (fixed)</strong>
                      </span>
                      <span class="flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full bg-orange-400 inline-block"></span>
                        Lab subjects: <strong class="text-gray-700">{{ form.lab_units }}</strong>
                      </span>
                      <span v-if="curriculumSubjects.some(s => s.is_pathfit)" class="flex items-center gap-1">
                        <span class="w-2 h-2 rounded-full bg-purple-400 inline-block"></span>
                        PATHFIT: <strong class="text-purple-700">excluded from billing</strong>
                      </span>
                    </span>
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>

          <!-- Preset fallback — subjects exist as aggregate only, no row data -->
          <div v-else-if="selectedStudent && !curriculumLoading && (form.lec_units > 0 || hasNstp)"
               class="rounded-xl border border-gray-200 bg-white overflow-hidden">
            <div class="px-5 py-3 bg-gray-50 border-b border-gray-200">
              <h3 class="text-sm font-semibold text-gray-700">Unit Breakdown</h3>
              <p class="text-xs text-gray-400 mt-0.5">
                {{ selectedStudent.course }} &middot;
                <span :class="computedYearLevel !== selectedStudent.year_level ? 'text-amber-600 font-medium' : ''">
                  {{ computedYearLevel || selectedStudent.year_level }}
                </span>
                &middot; {{ semLabel(form.semester) }} &middot; {{ form.school_year }}
              </p>
            </div>

            <!-- Aggregate row (preset / no subject data) -->
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
                  <td class="px-5 py-3 text-gray-700">
                    <span :class="computedYearLevel !== selectedStudent.year_level ? 'text-amber-700 font-semibold' : ''">
                      {{ computedYearLevel || selectedStudent.year_level }}
                    </span>
                    <span
                      v-if="computedYearLevel && computedYearLevel !== selectedStudent.year_level"
                      class="block text-xs text-amber-600 font-normal">
                      (was {{ selectedStudent.year_level }})
                    </span>
                  </td>
                  <td class="px-5 py-3 text-gray-700">{{ semLabel(form.semester) }}</td>
                  <td class="px-4 py-3 text-center font-mono font-semibold text-gray-900">
                    {{ totalLecUnits }}
                    <span v-if="hasNstp" class="block text-xs font-normal text-amber-600">
                      {{ form.lec_units }} + 1.5 NSTP
                    </span>
                  </td>
                  <td class="px-4 py-3 text-center font-mono text-gray-900">{{ form.lab_units }}</td>
                  <td class="px-4 py-3 text-center font-mono text-gray-900">{{ form.lab_units }}</td>
                  <td class="px-4 py-3 text-center font-mono font-bold text-blue-700">
                    {{ totalLecUnits + Number(form.lab_units) }}
                  </td>
                </tr>
              </tbody>
            </table>

            <!-- Preset notice -->
            <div v-if="curriculumMessage"
                 class="flex items-start gap-2 px-5 py-3 bg-blue-50 border-t border-blue-100 text-xs text-blue-800">
              <Info class="h-3.5 w-3.5 mt-0.5 shrink-0 text-blue-500" />
              {{ curriculumMessage }}
            </div>
          </div>

          <!-- No curriculum data message -->
          <div v-else-if="selectedStudent && !curriculumLoading && !selectedStudent.is_irregular && curriculumMessage"
               class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
            <AlertTriangle class="h-5 w-5 shrink-0 text-amber-600 mt-0.5" />
            <div>
              <p class="font-semibold">No Curriculum Data Found</p>
              <p class="text-xs text-amber-800 mt-0.5">{{ curriculumMessage }}</p>
              <p class="text-xs text-amber-700 mt-1">
                You can still enter units manually below.
                To auto-populate, seed subjects for this course/year/semester in Fee Settings or add a Course Unit Preset.
              </p>
            </div>
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
                <div class="space-y-1.5">
                  <Label for="lec_units" class="flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-blue-500 inline-block"></span>
                    Lecture Units
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

              <div
                v-if="pct > 0"
                class="rounded-md bg-green-50 border border-green-200 p-3 space-y-1.5 text-sm"
              >
                <p class="font-semibold text-xs uppercase tracking-wide text-green-700 mb-2">
                  Effective Fees After Discount
                </p>

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

          <!-- Submit -->
          <div class="flex gap-3 justify-end">
            <Button variant="outline" @click="router.visit(route('student-fees.index'))">Cancel</Button>
            <button
              type="button"
              :disabled="form.processing || !selectedStudent || totalAssessment === 0 || hasRemainingBalance || isSemesterPaid(form.semester) || tlPercentageTotal !== 100"
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