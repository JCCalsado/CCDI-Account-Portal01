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
  Search, User, BookOpen, Calculator, Plus, Trash2,
  CheckCircle2, Loader2, AlertTriangle, Info, History,
  FlaskConical, GraduationCap, X,
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

/**
 * One subject row from the getCurriculumUnits API.
 * Contains both academic data and per-subject fee preview (from AssessmentService).
 */
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
  nstp_billing_units?: number
  // Per-subject fee preview (populated by updated AssessmentService)
  tuition_fee?: number
  lab_fee?: number
  total_fee?: number
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

// ─── Student Search ───────────────────────────────────────────────────────────

const studentSearch   = ref('')
const searchResults   = ref<PreselectedStudent[]>([])
const searchLoading   = ref(false)
const selectedStudent = ref<PreselectedStudent | null>(props.preselectedStudent ?? null)
const paidSemesters   = ref<PaidSemester[]>(props.preselectedStudent?.paid_semesters ?? [])

const computedYearLevel = ref<string>(
  props.preselectedStudent
    ? computeNextSemesterAndYear(
        props.preselectedStudent.paid_semesters ?? [],
        props.preselectedStudent.year_level,
      ).year_level
    : ''
)

const hasRemainingBalance = computed(
  () => (selectedStudent.value?.remaining_balance ?? 0) > 0
)

function isSemesterPaid(semester: string): boolean {
  return paidSemesters.value.some(
    (ps) => ps.semester === semester && ps.school_year === form.school_year
  )
}

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
  selectedStudent.value   = student
  paidSemesters.value     = student.paid_semesters ?? []
  searchResults.value     = []
  studentSearch.value     = ''
  form.user_id            = student.id
  selectedSubjects.value  = []
  curriculumMessage.value = ''

  const next              = computeNextSemesterAndYear(paidSemesters.value, student.year_level)
  form.semester           = next.semester
  form.school_year        = next.school_year
  computedYearLevel.value = next.year_level
  form.year_level         = next.year_level
}

function clearStudent() {
  selectedStudent.value   = null
  paidSemesters.value     = []
  computedYearLevel.value = ''
  form.user_id            = 0
  form.year_level         = ''
  selectedSubjects.value  = []
  curriculumMessage.value = ''
}

// ─── Curriculum Loading ───────────────────────────────────────────────────────

const NSTP_UNITS = 1.5

const curriculumLoading   = ref(false)
const curriculumSubjects  = ref<CurriculumSubject[]>([])   // full list from API
const curriculumMessage   = ref('')

/**
 * selectedSubjects is the cart — subjects the student is actually enrolled in.
 * Pre-populated from curriculum but fully editable before saving.
 */
const selectedSubjects = ref<CurriculumSubject[]>([])

async function loadCurriculum() {
  const student = selectedStudent.value
  if (!student) return
  if (student.is_irregular) {
    curriculumSubjects.value  = []
    curriculumMessage.value   = ''
    selectedSubjects.value    = []
    return
  }
  if (!form.semester) return

  curriculumLoading.value  = true
  curriculumSubjects.value = []
  curriculumMessage.value  = ''

  try {
    const url = route('student-fees.curriculum-units')
      + '?student_id=' + student.id
      + '&semester='   + encodeURIComponent(form.semester)
      + '&year_level=' + encodeURIComponent(computedYearLevel.value || student.year_level)

    const res  = await fetch(url)
    const data = await res.json()

    if (data.found) {
      curriculumSubjects.value = data.subjects ?? []
      // Pre-select all active curriculum subjects into the cart
      selectedSubjects.value   = [...curriculumSubjects.value]

      if (data.source === 'preset') {
        curriculumMessage.value = data.message ?? 'Units from preset — no subject breakdown available.'
        // Preset mode: no subject rows but units are populated
        // Synthesise a dummy NSTP entry so the NSTP logic still works
        if (data.has_nstp) {
          selectedSubjects.value = [{
            id: -1, code: 'NSTP', name: 'National Service Training Program',
            lec_units: 3, lab_units: 0, total_units: 3,
            is_nstp: true, is_pathfit: false, is_billable: false,
            nstp_billing_units: NSTP_UNITS,
          }]
        }
      } else {
        curriculumMessage.value = ''
      }
    } else {
      curriculumMessage.value = data.message ?? 'No curriculum data found.'
      selectedSubjects.value  = []
    }
  } catch {
    curriculumMessage.value = 'Could not load curriculum — enter subjects manually or use override mode.'
    selectedSubjects.value  = []
  } finally {
    curriculumLoading.value = false
  }
}

watch([selectedStudent, () => form.semester], () => {
  if (selectedStudent.value) loadCurriculum()
}, { immediate: true })

// ─── Cart Operations ──────────────────────────────────────────────────────────

/** Subjects in the curriculum but NOT yet in the cart (available to add) */
const availableToAdd = computed(() => {
  const selectedIds = new Set(selectedSubjects.value.map((s) => s.id))
  return curriculumSubjects.value.filter((s) => !selectedIds.has(s.id))
})

function removeSubject(id: number) {
  selectedSubjects.value = selectedSubjects.value.filter((s) => s.id !== id)
}

function addSubject(subject: CurriculumSubject) {
  if (!selectedSubjects.value.find((s) => s.id === subject.id)) {
    selectedSubjects.value = [...selectedSubjects.value, subject]
  }
  showAddPanel.value = false
}

function resetToFullCurriculum() {
  selectedSubjects.value = [...curriculumSubjects.value]
}

const showAddPanel = ref(false)

// ─── Irregular Manual Subject Entry ──────────────────────────────────────────

const showManualEntry = ref(false)
const manualSubject   = ref({ code: '', name: '', lec_units: 3, lab_units: 0 })

function addManualSubject() {
  if (!manualSubject.value.code || !manualSubject.value.name) return
  const lec = Number(manualSubject.value.lec_units) || 0
  const lab = Number(manualSubject.value.lab_units) || 0
  const rate = props.feeRates.tuition_per_unit
  const labRate = props.feeRates.lab_fee_per_subject

  selectedSubjects.value = [
    ...selectedSubjects.value,
    {
      id:           -(Date.now()),          // negative ID = manual entry sentinel
      code:          manualSubject.value.code.toUpperCase().trim(),
      name:          manualSubject.value.name.trim(),
      lec_units:     lec,
      lab_units:     lab,
      total_units:   lec + lab,
      is_nstp:       manualSubject.value.code.toUpperCase().includes('NSTP'),
      is_pathfit:    false,
      is_billable:   !manualSubject.value.code.toUpperCase().includes('NSTP'),
      nstp_billing_units: manualSubject.value.code.toUpperCase().includes('NSTP') ? NSTP_UNITS : 0,
      tuition_fee:   lec * rate,
      lab_fee:       lab > 0 ? labRate : 0,
      total_fee:     lec * rate + (lab > 0 ? labRate : 0),
    },
  ]
  manualSubject.value = { code: '', name: '', lec_units: 3, lab_units: 0 }
  showManualEntry.value = false
}

// ─── Derived billing from selectedSubjects (the cart) ─────────────────────────

const cartBillable = computed(() =>
  selectedSubjects.value.filter((s) => s.is_billable)
)
const cartNstp = computed(() =>
  selectedSubjects.value.filter((s) => s.is_nstp)
)
const cartPathfit = computed(() =>
  selectedSubjects.value.filter((s) => s.is_pathfit)
)

const billableLecUnits = computed(() =>
  cartBillable.value.reduce((sum, s) => sum + s.lec_units, 0)
)
const labSubjectCount = computed(() =>
  cartBillable.value.filter((s) => s.lab_units > 0).length
)
const hasNstp = computed(() => cartNstp.value.length > 0)
const nstpLecUnits = computed(() => hasNstp.value ? NSTP_UNITS : 0)

const totalLecUnits = computed(() => billableLecUnits.value + nstpLecUnits.value)

// Sync form fields from cart so the backend receives correct totals
watch(
  [billableLecUnits, labSubjectCount, nstpLecUnits],
  ([lec, lab, nstp]) => {
    form.lec_units       = lec
    form.lab_units       = lab
    form.nstp_lec_units  = nstp
  },
  { immediate: true }
)

// ─── Fee Computation (mirrors AssessmentService::compute exactly) ─────────────

const rate = computed(() => props.feeRates.tuition_per_unit)

const rawTotalTuition    = computed(() => totalLecUnits.value * rate.value)
const rawBillableTuition = computed(() => billableLecUnits.value * rate.value)
const nstpTuition        = computed(() => nstpLecUnits.value * rate.value)

const pct = computed(() => Number(form.discount_percentage) || 0)

const discountSaving = computed(() => {
  if (pct.value === 100) return rawBillableTuition.value
  if (pct.value > 0)    return Math.round(rawTotalTuition.value * (pct.value / 100) * 100) / 100
  return 0
})

const tuitionFee = computed(() => {
  if (pct.value === 100) return nstpTuition.value
  return Math.round((rawTotalTuition.value - discountSaving.value) * 100) / 100
})

const entrepreneurFee = computed(() =>
  labSubjectCount.value > 0 ? (props.feeRates.entrepreneurship_fee ?? 600) : 0
)
const labFee  = computed(() => labSubjectCount.value * props.feeRates.lab_fee_per_subject)
const miscFee = computed(() => props.feeRates.misc_total)

const totalAssessment = computed(() =>
  tuitionFee.value + labFee.value + entrepreneurFee.value + miscFee.value
)

const tuitionAndLab = computed(() =>
  tuitionFee.value + labFee.value + entrepreneurFee.value
)

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

// ─── Form ─────────────────────────────────────────────────────────────────────

const form = useForm({
  user_id:             props.preselectedStudent?.id ?? 0,
  semester:            '1st' as '1st' | '2nd' | 'Summer',
  school_year:         '',
  year_level:          '',
  lec_units:           0,
  lab_units:           0,
  nstp_lec_units:      0,
  discount_percentage: 0 as number,
  term_percentages:    {} as Record<string, number>,
})

if (props.preselectedStudent) {
  const next              = computeNextSemesterAndYear(
    props.preselectedStudent.paid_semesters ?? [],
    props.preselectedStudent.year_level,
  )
  form.semester           = next.semester
  form.school_year        = next.school_year
  form.year_level         = next.year_level
  computedYearLevel.value = next.year_level
} else {
  const currentYear = new Date().getFullYear()
  form.school_year  = `${currentYear}-${currentYear + 1}`
}

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
    onFinish: ()      => { submitting.value = false },
  })
}

// ─── Misc helpers ─────────────────────────────────────────────────────────────

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

function subjectTypeBadge(s: CurriculumSubject): { label: string; cls: string } {
  if (s.is_nstp)    return { label: 'NSTP',    cls: 'bg-amber-100 text-amber-700' }
  if (s.is_pathfit) return { label: 'PATHFIT', cls: 'bg-purple-100 text-purple-700' }
  if (s.lab_units > 0) return { label: 'LAB',  cls: 'bg-orange-100 text-orange-700' }
  return { label: 'LEC', cls: 'bg-blue-100 text-blue-700' }
}

/** Compute the live per-subject fee for the fee column in the cart */
function liveSubjectFee(s: CurriculumSubject): number {
  if (s.is_pathfit) return 0
  if (s.is_nstp)    return nstpLecUnits.value * rate.value
  const tuition = s.lec_units * rate.value
  const lab     = s.lab_units > 0 ? props.feeRates.lab_fee_per_subject : 0
  return tuition + lab
}
</script>

<template>
  <AppLayout>
    <div class="w-full p-6 space-y-6">
      <Breadcrumbs :items="breadcrumbs" />

      <div class="flex items-center gap-3">
        <Calculator class="h-6 w-6 text-blue-600" />
        <div>
          <h1 class="text-2xl font-bold">New Assessment</h1>
          <p class="text-sm text-muted-foreground mt-0.5">Select a student, review their academic load, then create the assessment.</p>
        </div>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

        <!-- ── LEFT COLUMN ─────────────────────────────────────────────────── -->
        <div class="xl:col-span-2 space-y-5">

          <!-- ① Student Selector ─────────────────────────────────────────── -->
          <Card>
            <CardHeader>
              <CardTitle class="flex items-center gap-2 text-base">
                <User class="h-4 w-4" /> Student
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
              <!-- Selected state -->
              <div v-if="selectedStudent"
                class="flex items-center justify-between rounded-lg border bg-blue-50 dark:bg-blue-950 p-4">
                <div class="space-y-1">
                  <p class="font-semibold text-blue-900 dark:text-blue-100">{{ selectedStudent.name }}</p>
                  <p class="text-sm text-blue-700 dark:text-blue-300 flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span class="font-mono text-xs bg-blue-100 dark:bg-blue-900 px-1.5 py-0.5 rounded">{{ selectedStudent.account_id }}</span>
                    <span>{{ selectedStudent.course }}</span>
                    <span>·</span>
                    <span :class="computedYearLevel !== selectedStudent.year_level ? 'text-amber-700 font-semibold' : ''">
                      {{ computedYearLevel || selectedStudent.year_level }}
                    </span>
                    <span v-if="selectedStudent.is_irregular"
                      class="inline-flex items-center gap-1 text-xs font-medium text-amber-700 bg-amber-100 px-2 py-0.5 rounded-full">
                      <AlertTriangle class="h-3 w-3" /> Irregular
                    </span>
                  </p>
                  <p v-if="computedYearLevel && computedYearLevel !== selectedStudent.year_level"
                    class="text-xs text-amber-700 font-medium flex items-center gap-1">
                    <Info class="h-3 w-3" />
                    Year level auto-advanced to <strong>{{ computedYearLevel }}</strong>
                  </p>
                </div>
                <Button variant="outline" size="sm" @click="clearStudent">Change</Button>
              </div>

              <!-- Search -->
              <div v-else class="relative">
                <div class="relative">
                  <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                  <Input v-model="studentSearch" class="pl-9" placeholder="Search student name or account ID…" @input="searchStudents" />
                  <Loader2 v-if="searchLoading" class="absolute right-3 top-1/2 -translate-y-1/2 h-4 w-4 animate-spin text-muted-foreground" />
                </div>
                <div v-if="searchResults.length > 0"
                  class="absolute z-20 mt-1 w-full rounded-md border bg-white dark:bg-zinc-900 shadow-lg">
                  <button v-for="s in searchResults" :key="s.id"
                    class="w-full text-left px-4 py-3 hover:bg-accent transition-colors border-b last:border-0"
                    @click="selectStudent(s)">
                    <p class="font-medium text-sm flex items-center gap-2">
                      {{ s.name }}
                      <span v-if="s.is_irregular" class="text-xs text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded">Irregular</span>
                      <span v-if="s.paid_semesters?.length" class="text-xs text-green-700 bg-green-100 px-1.5 py-0.5 rounded flex items-center gap-1">
                        <CheckCircle2 class="h-3 w-3" />{{ s.paid_semesters.length }} sem{{ s.paid_semesters.length > 1 ? 's' : '' }} paid
                      </span>
                    </p>
                    <p class="text-xs text-muted-foreground mt-0.5">
                      <span class="font-medium">Acct.</span> {{ s.account_id }} · {{ s.course }} · {{ s.year_level }}
                    </p>
                  </button>
                </div>
                <p v-if="form.errors.user_id" class="text-sm text-destructive mt-1">{{ form.errors.user_id }}</p>
              </div>
            </CardContent>
          </Card>

          <!-- Balance block -->
          <div v-if="selectedStudent && hasRemainingBalance"
               class="flex items-start gap-3 rounded-lg border-2 border-red-400 bg-red-50 px-4 py-4 text-sm">
            <AlertTriangle class="h-5 w-5 shrink-0 text-red-600 mt-0.5" />
            <div class="flex-1">
              <p class="font-bold text-red-800">Cannot Create Assessment — Unsettled Balance</p>
              <p class="text-red-700 mt-1">Outstanding balance of <span class="font-bold">{{ formatCurrency(selectedStudent.remaining_balance) }}</span> must be settled first.</p>
              <div class="mt-3">
                <Button variant="outline" size="sm" class="border-red-400 text-red-700 hover:bg-red-100"
                        @click="router.visit(route('student-fees.show', selectedStudent.id))">
                  View Student Profile &amp; Record Payment
                </Button>
              </div>
            </div>
          </div>

          <!-- ② Paid History ──────────────────────────────────────────────── -->
          <Card v-if="paidSemesters.length > 0" class="border-green-200 bg-green-50/40">
            <CardHeader class="pb-3">
              <CardTitle class="flex items-center gap-2 text-base text-green-800">
                <History class="h-4 w-4 text-green-600" /> Completed Semesters
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
              <div v-for="year in paidSchoolYears" :key="year" class="space-y-1.5">
                <p class="text-xs font-semibold text-green-700 uppercase tracking-wide">SY {{ year }}</p>
                <div class="flex flex-wrap gap-2">
                  <template v-for="sem in SEMESTERS" :key="sem">
                    <div v-if="paidSemesters.some(ps => ps.semester === sem && ps.school_year === year)"
                      class="inline-flex items-center gap-1.5 rounded-full bg-green-100 border border-green-300 px-3 py-1 text-xs font-semibold text-green-800">
                      <CheckCircle2 class="h-3.5 w-3.5 text-green-600" />
                      {{ semLabel(sem) }}
                      <span class="text-green-600 font-normal">
                        · {{ formatCurrency(paidSemesters.find(ps => ps.semester === sem && ps.school_year === year)!.total_assessment) }}
                      </span>
                    </div>
                  </template>
                </div>
              </div>
              <p class="text-xs text-green-700/70">
                New assessment auto-advanced to <strong>{{ semLabel(form.semester) }} · SY {{ form.school_year }}</strong>.
              </p>
            </CardContent>
          </Card>

          <!-- ③ Enrollment Period ─────────────────────────────────────────── -->
          <Card>
            <CardHeader>
              <CardTitle class="text-base">Enrollment Period</CardTitle>
            </CardHeader>
            <CardContent class="grid grid-cols-2 gap-4">
              <div class="space-y-1.5">
                <Label>Semester</Label>
                <div class="flex gap-2">
                  <template v-for="sem in SEMESTERS" :key="sem">
                    <button type="button"
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
                      ]">
                      <span v-if="isSemesterPaid(sem)"
                        class="absolute -top-1.5 -right-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-green-500 text-white">
                        <CheckCircle2 class="h-3 w-3" />
                      </span>
                      {{ sem === 'Summer' ? 'Summer' : sem + ' Sem' }}
                      <span v-if="isSemesterPaid(sem)" class="ml-1 text-xs font-normal opacity-75">Paid</span>
                    </button>
                  </template>
                </div>
                <p v-if="form.errors.semester" class="text-sm text-destructive">{{ form.errors.semester }}</p>
              </div>
              <div class="space-y-1.5">
                <Label for="school_year">School Year</Label>
                <Input id="school_year" v-model="form.school_year" placeholder="e.g. 2025-2026" />
                <p v-if="form.errors.school_year" class="text-sm text-destructive">{{ form.errors.school_year }}</p>
              </div>
            </CardContent>
          </Card>

          <!-- ④ ACADEMIC LOAD — THE CART ─────────────────────────────────── -->
          <div v-if="selectedStudent">

            <!-- Loading skeleton -->
            <div v-if="curriculumLoading"
              class="rounded-xl border border-border bg-card p-8 flex items-center justify-center gap-3 text-muted-foreground">
              <Loader2 class="h-5 w-5 animate-spin text-blue-500" />
              <span class="text-sm">Loading curriculum for {{ computedYearLevel || selectedStudent.year_level }}…</span>
            </div>

            <!-- Message only (preset mode or no subjects) -->
            <div v-else-if="!curriculumLoading && curriculumMessage && selectedSubjects.length === 0 && !selectedStudent.is_irregular"
              class="rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900 flex items-start gap-3">
              <AlertTriangle class="h-5 w-5 shrink-0 text-amber-500 mt-0.5" />
              <div>
                <p class="font-semibold">Curriculum Not Found</p>
                <p class="text-amber-800 mt-0.5">{{ curriculumMessage }}</p>
                <p class="text-xs text-amber-600 mt-1">Use the override inputs below to enter units manually.</p>
              </div>
            </div>

            <!-- ── The Subject Cart ────────────────────────────────────────── -->
            <div v-else-if="!curriculumLoading" class="rounded-xl border border-border overflow-hidden">

              <!-- Cart header -->
              <div class="px-5 py-3.5 bg-muted/50 border-b border-border flex items-center justify-between">
                <div class="flex items-center gap-2">
                  <GraduationCap class="h-4 w-4 text-primary" />
                  <div>
                    <h3 class="text-sm font-semibold">
                      Academic Load
                      <span v-if="curriculumMessage" class="ml-2 text-xs font-normal text-amber-600">({{ curriculumMessage }})</span>
                    </h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                      {{ selectedStudent.course }}
                      · <span :class="computedYearLevel !== selectedStudent.year_level ? 'text-amber-600 font-semibold' : ''">{{ computedYearLevel || selectedStudent.year_level }}</span>
                      · {{ semLabel(form.semester) }}
                      · SY {{ form.school_year }}
                    </p>
                  </div>
                </div>
                <div class="flex items-center gap-2">
                  <!-- Add from curriculum dropdown -->
                  <div v-if="availableToAdd.length > 0" class="relative">
                    <Button variant="outline" size="sm" @click="showAddPanel = !showAddPanel"
                      class="text-xs flex items-center gap-1.5">
                      <Plus class="h-3.5 w-3.5" /> Add Subject
                    </Button>
                    <!-- Dropdown -->
                    <div v-if="showAddPanel"
                      class="absolute right-0 top-full mt-1 z-20 w-72 rounded-xl border border-border bg-background shadow-xl overflow-hidden">
                      <div class="px-3 py-2 border-b border-border bg-muted/40 flex items-center justify-between">
                        <span class="text-xs font-semibold text-muted-foreground">Available Subjects</span>
                        <button @click="showAddPanel = false"><X class="h-3.5 w-3.5 text-muted-foreground hover:text-foreground" /></button>
                      </div>
                      <div class="max-h-64 overflow-y-auto">
                        <button v-for="s in availableToAdd" :key="s.id"
                          @click="addSubject(s)"
                          class="w-full text-left px-3 py-2.5 hover:bg-accent border-b border-border/50 last:border-0 transition-colors">
                          <div class="flex items-start justify-between gap-2">
                            <div>
                              <span class="text-xs font-mono font-semibold text-primary">{{ s.code }}</span>
                              <span class="ml-1.5 text-xs text-foreground">{{ s.name }}</span>
                            </div>
                            <span class="text-xs rounded-full px-1.5 py-0.5 flex-shrink-0"
                              :class="subjectTypeBadge(s).cls">{{ subjectTypeBadge(s).label }}</span>
                          </div>
                          <span class="text-xs text-muted-foreground">{{ s.lec_units }} LEC · {{ s.lab_units }} LAB</span>
                        </button>
                      </div>
                    </div>
                  </div>

                  <!-- Reset to full curriculum -->
                  <Button v-if="selectedSubjects.length !== curriculumSubjects.length && curriculumSubjects.length > 0"
                    variant="ghost" size="sm" @click="resetToFullCurriculum"
                    class="text-xs text-muted-foreground hover:text-foreground">
                    Reset
                  </Button>

                  <!-- Manual entry (irregular / override) -->
                  <Button variant="outline" size="sm" @click="showManualEntry = !showManualEntry"
                    class="text-xs flex items-center gap-1.5">
                    <Plus class="h-3.5 w-3.5" /> Manual
                  </Button>
                </div>
              </div>

              <!-- Manual entry form -->
              <div v-if="showManualEntry" class="px-5 py-3 bg-amber-50 border-b border-amber-200">
                <p class="text-xs font-semibold text-amber-800 mb-2">Add Subject Manually</p>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                  <Input v-model="manualSubject.code" placeholder="Code (e.g. IT101)" class="uppercase text-sm" />
                  <Input v-model="manualSubject.name" placeholder="Subject Name" class="sm:col-span-1 text-sm" />
                  <Input v-model.number="manualSubject.lec_units" type="number" min="0" max="10" placeholder="LEC units" class="text-sm" />
                  <Input v-model.number="manualSubject.lab_units" type="number" min="0" max="5" placeholder="LAB units" class="text-sm" />
                </div>
                <div class="flex gap-2 mt-2">
                  <Button size="sm" @click="addManualSubject" :disabled="!manualSubject.code || !manualSubject.name"
                    class="text-xs">Add to Load</Button>
                  <Button variant="ghost" size="sm" @click="showManualEntry = false" class="text-xs">Cancel</Button>
                </div>
              </div>

              <!-- Empty cart state -->
              <div v-if="selectedSubjects.length === 0"
                class="px-5 py-10 text-center text-muted-foreground text-sm">
                <BookOpen class="h-8 w-8 mx-auto mb-2 opacity-30" />
                <p class="font-medium">No subjects in academic load.</p>
                <p class="text-xs mt-1">Add from the curriculum above or enter manually.</p>
              </div>

              <!-- Subject rows table -->
              <table v-else class="w-full text-sm">
                <thead class="text-xs uppercase tracking-wide text-muted-foreground bg-muted/30 border-b border-border">
                  <tr>
                    <th class="text-left px-5 py-2.5">Code</th>
                    <th class="text-left px-5 py-2.5">Subject</th>
                    <th class="text-center px-3 py-2.5">Type</th>
                    <th class="text-center px-3 py-2.5">LEC</th>
                    <th class="text-center px-3 py-2.5">LAB</th>
                    <th class="text-right px-4 py-2.5">Fee</th>
                    <th class="px-3 py-2.5"></th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-border">
                  <tr v-for="s in selectedSubjects" :key="s.id"
                    :class="[
                      'transition-colors group',
                      s.is_nstp    ? 'bg-amber-50/40 hover:bg-amber-50' :
                      s.is_pathfit ? 'bg-purple-50/30 hover:bg-purple-50' :
                                     'hover:bg-muted/30',
                    ]">
                    <!-- Code -->
                    <td class="px-5 py-3">
                      <span class="font-mono text-xs font-semibold text-primary bg-primary/5 px-1.5 py-0.5 rounded">{{ s.code }}</span>
                    </td>
                    <!-- Name -->
                    <td class="px-5 py-3">
                      <div class="flex items-center gap-1.5">
                        <span class="font-medium text-sm">{{ s.name }}</span>
                        <FlaskConical v-if="s.lab_units > 0 && !s.is_nstp && !s.is_pathfit"
                          class="h-3.5 w-3.5 text-orange-500 flex-shrink-0" title="Has lab component" />
                      </div>
                      <span v-if="s.is_nstp" class="text-xs text-amber-600">Billed at 1.5 units fixed</span>
                      <span v-if="s.is_pathfit" class="text-xs text-purple-500">Excluded from tuition billing</span>
                    </td>
                    <!-- Type badge -->
                    <td class="px-3 py-3 text-center">
                      <span class="text-xs rounded-full px-2 py-0.5 font-medium"
                        :class="subjectTypeBadge(s).cls">{{ subjectTypeBadge(s).label }}</span>
                    </td>
                    <!-- LEC -->
                    <td class="px-3 py-3 text-center">
                      <span class="font-mono font-semibold text-blue-700"
                        :class="s.is_nstp ? 'text-amber-600' : ''">
                        {{ s.is_nstp ? '1.5*' : s.lec_units }}
                      </span>
                    </td>
                    <!-- LAB -->
                    <td class="px-3 py-3 text-center">
                      <span v-if="s.lab_units > 0" class="font-mono font-semibold text-orange-600">{{ s.lab_units }}</span>
                      <span v-else class="text-muted-foreground text-xs">—</span>
                    </td>
                    <!-- Fee -->
                    <td class="px-4 py-3 text-right font-medium tabular-nums">
                      <span v-if="s.is_pathfit" class="text-muted-foreground text-xs">—</span>
                      <span v-else :class="s.is_nstp ? 'text-amber-700' : 'text-foreground'">
                        {{ formatCurrency(liveSubjectFee(s)) }}
                      </span>
                    </td>
                    <!-- Remove -->
                    <td class="px-3 py-3 text-center">
                      <button @click="removeSubject(s.id)"
                        class="opacity-0 group-hover:opacity-100 transition-opacity text-muted-foreground hover:text-red-500 p-1 rounded"
                        title="Remove from load">
                        <Trash2 class="h-3.5 w-3.5" />
                      </button>
                    </td>
                  </tr>
                </tbody>

                <!-- Totals footer -->
                <tfoot class="border-t-2 border-border bg-muted/40 text-sm font-semibold">
                  <tr>
                    <td colspan="2" class="px-5 py-3 text-muted-foreground">
                      {{ selectedSubjects.length }} subject{{ selectedSubjects.length !== 1 ? 's' : '' }}
                      <span v-if="cartPathfit.length > 0" class="ml-2 text-xs font-normal text-purple-600">
                        ({{ cartPathfit.length }} PATHFIT excluded from billing)
                      </span>
                    </td>
                    <td class="px-3 py-3 text-center"></td>
                    <td class="px-3 py-3 text-center text-blue-700">
                      {{ totalLecUnits }}
                      <span v-if="hasNstp" class="block text-xs font-normal text-amber-600">{{ billableLecUnits }}+1.5</span>
                    </td>
                    <td class="px-3 py-3 text-center text-orange-600">
                      {{ cartBillable.filter(s => s.lab_units > 0).length > 0
                        ? cartBillable.filter(s => s.lab_units > 0).length + ' subj'
                        : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right text-primary">
                      {{ formatCurrency(selectedSubjects.reduce((sum, s) => sum + liveSubjectFee(s), 0)) }}
                    </td>
                    <td class="px-3 py-3"></td>
                  </tr>
                </tfoot>
              </table>
            </div>

            <!-- Irregular student notice -->
            <div v-if="selectedStudent.is_irregular"
              class="mt-3 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              <AlertTriangle class="h-5 w-5 shrink-0 text-amber-600 mt-0.5" />
              <div>
                <p class="font-semibold">Irregular Student</p>
                <p class="text-amber-800 text-xs mt-0.5">No default curriculum. Add subjects above using <strong>Manual</strong>. NSTP is automatically detected from the subject code.</p>
              </div>
            </div>

          </div>

          <!-- ⑤ NSTP Summary (shown when NSTP detected, for clarity) ──────── -->
          <div v-if="hasNstp && selectedStudent"
            class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 flex items-center justify-between text-sm">
            <div class="flex items-center gap-2 text-amber-800">
              <Info class="h-4 w-4 text-amber-500 flex-shrink-0" />
              <span><strong>NSTP detected</strong> — billed at <strong>1.5 units fixed</strong> ({{ formatCurrency(nstpTuition) }}), regardless of stored unit count.</span>
            </div>
            <span class="font-semibold text-amber-700 tabular-nums">{{ formatCurrency(nstpTuition) }}</span>
          </div>

          <!-- ⑥ Discount / Scholarship ─────────────────────────────────────── -->
          <Card>
            <CardHeader>
              <CardTitle class="text-base flex items-center gap-2">
                <span class="text-amber-600">🎓</span> Scholarship / Discount
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
              <div v-if="hasNstp && pct === 100"
                class="flex items-start gap-2 rounded-md bg-amber-50 border border-amber-300 p-3 text-sm text-amber-900">
                <AlertTriangle class="h-4 w-4 mt-0.5 shrink-0 text-amber-600" />
                <div>
                  <p class="font-semibold">100% Discount — NSTP Exception</p>
                  <p class="text-xs text-amber-800 mt-0.5">
                    All billable units ({{ billableLecUnits }}) → ₱0. NSTP (1.5 units, {{ formatCurrency(nstpTuition) }}) charged at full price.
                  </p>
                </div>
              </div>

              <div class="space-y-3">
                <Label for="discount_percentage">Discount Percentage (%)</Label>
                <p class="text-xs text-muted-foreground -mt-2">
                  <template v-if="hasNstp">
                    Partial discount applies to all {{ totalLecUnits }} units including NSTP.
                    At 100%: billable units waived, NSTP ({{ formatCurrency(nstpTuition) }}) charged in full.
                  </template>
                  <template v-else>
                    Applies to {{ billableLecUnits }} billable lecture units. Lab and misc fees are never discounted.
                  </template>
                </p>
                <div class="flex gap-1.5 flex-wrap">
                  <button v-for="preset in [0, 10, 20, 25, 50, 75, 100]" :key="preset" type="button"
                    @click="form.discount_percentage = preset"
                    :class="[
                      'px-3 py-1.5 rounded-md text-xs font-medium border transition-colors',
                      form.discount_percentage === preset
                        ? 'bg-amber-500 text-white border-amber-500 shadow-sm'
                        : 'bg-background border-input text-muted-foreground hover:bg-muted'
                    ]">
                    {{ preset === 0 ? 'No discount' : preset + '%' }}
                  </button>
                </div>
                <div class="flex items-center gap-3">
                  <Input id="discount_percentage" type="number" v-model.number="form.discount_percentage"
                    min="0" max="100" step="0.01" placeholder="0.00" class="w-28 text-center text-lg font-semibold" />
                  <span class="text-sm text-muted-foreground">% off lecture units</span>
                </div>
                <p v-if="form.errors.discount_percentage" class="text-sm text-destructive">{{ form.errors.discount_percentage }}</p>
              </div>

              <!-- Effective fee breakdown after discount -->
              <div v-if="pct > 0" class="rounded-md bg-green-50 border border-green-200 p-3 space-y-1.5 text-sm">
                <p class="font-semibold text-xs uppercase tracking-wide text-green-700 mb-2">Effective Fees After Discount</p>
                <template v-if="pct < 100">
                  <div class="flex justify-between text-green-800 text-xs">
                    <span>Total tuition ({{ totalLecUnits }} units × {{ formatCurrency(rate) }})</span>
                    <span>{{ formatCurrency(rawTotalTuition) }}</span>
                  </div>
                  <div class="flex justify-between text-green-600 text-xs">
                    <span>− {{ pct }}% discount</span>
                    <span>− {{ formatCurrency(discountSaving) }}</span>
                  </div>
                </template>
                <template v-else>
                  <div class="flex justify-between text-green-800 text-xs">
                    <span>Billable tuition ({{ billableLecUnits }} units) — 100% waived</span>
                    <span class="line-through text-green-400">{{ formatCurrency(rawBillableTuition) }}</span>
                  </div>
                  <div v-if="hasNstp" class="flex justify-between text-amber-800 text-xs font-medium">
                    <span>NSTP 1.5 units — excluded from waiver</span>
                    <span>{{ formatCurrency(nstpTuition) }}</span>
                  </div>
                </template>
                <div class="flex justify-between text-green-900 font-medium pt-1 border-t border-green-200">
                  <span>Total Tuition</span>
                  <span>{{ formatCurrency(tuitionFee) }}</span>
                </div>
                <div class="flex justify-between text-green-900">
                  <span>Lab Fee ({{ labSubjectCount }} subjects)</span>
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

          <!-- Submit row -->
          <div class="flex gap-3 justify-end">
            <Button variant="outline" @click="router.visit(route('student-fees.index'))">Cancel</Button>
            <button type="button"
              :disabled="form.processing || !selectedStudent || totalAssessment === 0 || hasRemainingBalance || isSemesterPaid(form.semester) || tlPercentageTotal !== 100"
              class="inline-flex items-center justify-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow-xs transition-all hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
              @click.prevent="submit">
              <Loader2 v-if="form.processing" class="h-4 w-4 animate-spin" />
              <CheckCircle2 v-else class="h-4 w-4" />
              {{ form.processing ? 'Saving…' : 'Create Assessment' }}
            </button>
          </div>
        </div>

        <!-- ── RIGHT COLUMN: Live Fee Preview ─────────────────────────────── -->
        <div class="space-y-4">
          <Card class="sticky top-6">
            <CardHeader>
              <CardTitle class="text-base flex items-center gap-2">
                <Calculator class="h-4 w-4" /> Fee Summary
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-3 text-sm">

              <!-- Unit totals -->
              <div v-if="selectedSubjects.length > 0" class="grid grid-cols-3 gap-2 text-center mb-2">
                <div class="rounded-lg bg-blue-50 p-2">
                  <p class="text-xs text-blue-500 font-medium">LEC</p>
                  <p class="text-lg font-bold text-blue-700">{{ totalLecUnits }}</p>
                  <p class="text-xs text-blue-400">units</p>
                </div>
                <div class="rounded-lg bg-orange-50 p-2">
                  <p class="text-xs text-orange-500 font-medium">LAB</p>
                  <p class="text-lg font-bold text-orange-600">{{ labSubjectCount }}</p>
                  <p class="text-xs text-orange-400">subjects</p>
                </div>
                <div class="rounded-lg bg-muted p-2">
                  <p class="text-xs text-muted-foreground font-medium">TOTAL</p>
                  <p class="text-lg font-bold">{{ selectedSubjects.length }}</p>
                  <p class="text-xs text-muted-foreground">subjects</p>
                </div>
              </div>

              <div class="space-y-2">
                <div class="flex justify-between">
                  <span class="text-muted-foreground">
                    Tuition ({{ totalLecUnits }} LEC × {{ formatCurrency(feeRates.tuition_per_unit) }})
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
                    Lab Fee ({{ labSubjectCount }} subj × {{ formatCurrency(feeRates.lab_fee_per_subject) }})
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

              <!-- Payment schedule -->
              <div v-if="totalAssessment > 0" class="mt-3 border-t pt-3">
                <p class="text-xs font-semibold uppercase text-muted-foreground mb-2">
                  Payment Schedule ({{ feeRates.payment_terms.length }} terms)
                </p>
                <div class="space-y-1.5">
                  <div v-for="term in paymentTermBreakdown" :key="term.term_order"
                    class="flex items-center justify-between text-xs gap-2">
                    <span v-if="term.term_name === 'Upon Registration'" class="text-muted-foreground flex-1">
                      {{ term.term_name }}
                    </span>
                    <template v-else>
                      <span class="text-muted-foreground flex-1">{{ term.term_name }}</span>
                      <input type="number" :value="editablePercentages[term.term_name]"
                        @change="editablePercentages[term.term_name] = Math.max(0, Math.min(100, Number(($event.target as HTMLInputElement).value)))"
                        min="0" max="100" step="0.01"
                        class="w-14 text-right border border-input rounded px-1 py-0.5 text-xs bg-background text-foreground" />
                      <span class="text-muted-foreground">%</span>
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

          <!-- Rate Reference -->
          <Card class="bg-muted/50">
            <CardContent class="pt-4 space-y-1 text-xs text-muted-foreground">
              <p class="font-semibold text-foreground text-sm mb-2">Current Rates</p>
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