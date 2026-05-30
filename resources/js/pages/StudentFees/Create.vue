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
  Plus, Trash2, X,
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
  has_existing_assessment: boolean
  existing_assessment_term: string | null
}

interface SubjectRow {
  id: number
  code: string
  name: string
  lec_units: number
  lab_units: number
  total_units: number
  year_level: string
  semester: string
  course: string
  is_nstp: boolean
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

const _currentYear = new Date().getFullYear()
const _defaultYear = `${_currentYear}-${_currentYear + 1}`

const _initial = props.preselectedStudent
  ? computeNextSemesterAndYear(
      props.preselectedStudent.paid_semesters ?? [],
      props.preselectedStudent.year_level,
    )
  : { semester: '1st' as const, school_year: _defaultYear, year_level: '' }

// ─── Scholarship preset options ───────────────────────────────────────────────

const SCHOLARSHIP_PRESETS = [
  { label: 'No Discount',                value: '',                          pct: 0   },
  { label: 'CHED Full Scholar',          value: 'CHED Full Scholar',         pct: 100 },
  { label: 'CHED Half Scholar',          value: 'CHED Half Scholar',         pct: 50  },
  { label: 'CHED Partial Scholar',       value: 'CHED Partial Scholar',      pct: 25  },
  { label: 'CCDI Institutional Grant',   value: 'CCDI Institutional Grant',  pct: 100 },
  { label: 'Academic Excellence Award',  value: 'Academic Excellence Award', pct: 50  },
  { label: 'Faculty / Staff Dependent',  value: 'Faculty / Staff Dependent', pct: 100 },
  { label: 'Sibling Discount',           value: 'Sibling Discount',          pct: 10  },
  { label: 'Other / Custom',             value: '__custom__',                pct: null },
]

const selectedScholarshipPreset = ref<string>('')
const customScholarshipName      = ref<string>('')

const scholarshipName = computed(() => {
  if (!selectedScholarshipPreset.value) return ''
  if (selectedScholarshipPreset.value === '__custom__') return customScholarshipName.value.trim()
  return selectedScholarshipPreset.value
})

function onScholarshipPresetChange(value: string) {
  selectedScholarshipPreset.value = value
  const preset = SCHOLARSHIP_PRESETS.find(p => p.value === value)
  if (preset && preset.pct !== null) {
    form.discount_percentage = preset.pct
  }
}

// ─── Form ─────────────────────────────────────────────────────────────────────

const form = useForm({
  user_id:             props.preselectedStudent?.id ?? 0,
  semester:            _initial.semester,
  school_year:         _initial.school_year,
  year_level:          _initial.year_level,
  lec_units:           0,
  lab_units:           0,
  nstp_lec_units:      0 as number,
  discount_percentage: 0 as number,
  discount_name:       '' as string,
  term_percentages:    {} as Record<string, number>,
  manual_subject_ids:  [] as number[],
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

// ─── Existing-assessment confirmation modal ───────────────────────────────────

const pendingStudentSelection  = ref<PreselectedStudent | null>(null)
const showExistingAssessmentModal = ref(false)

function commitStudentSelection(student: PreselectedStudent) {
  selectedStudent.value   = student
  paidSemesters.value     = student.paid_semesters ?? []
  searchResults.value     = []
  studentSearch.value     = ''
  selectedSubjects.value  = []
  curriculumMessage.value = ''

  const next              = computeNextSemesterAndYear(paidSemesters.value, student.year_level)
  form.user_id            = student.id
  form.semester           = next.semester
  form.school_year        = next.school_year
  form.year_level         = next.year_level
  computedYearLevel.value = next.year_level
}

// ─── Paid Semester Helpers ────────────────────────────────────────────────────

function isSemesterPaid(semester: string): boolean {
  return paidSemesters.value.some(
    (ps) => ps.semester === semester && ps.school_year === form.school_year,
  )
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
    finally  { searchLoading.value = false }
  }, 300)
}

/**
 * Returns true when the student's latest existing assessment term
 * is already present in paid_semesters — meaning it is fully settled.
 *
 * Green badge case  → paid, no real risk, just informational history.
 * Amber badge case  → unpaid active assessment; creating another is a real collision.
 */
function existingTermIsPaid(student: PreselectedStudent): boolean {
  if (!student.existing_assessment_term || !student.paid_semesters?.length) return false

  return student.paid_semesters.some((ps) => {
    // DB semester format: '1st Sem', '2nd Sem', 'Summer'
    // existing_assessment_term is built as: semester + ' ' + school_year
    // e.g. '1st Sem 2026-2027'
    const expected = ps.semester + ' ' + ps.school_year
    return student.existing_assessment_term === expected
  })
}

function selectStudent(student: PreselectedStudent) {
  // Only intercept when the existing assessment is NOT fully paid.
  // existingTermIsPaid() cross-references paid_semesters — a paid assessment
  // is just history and should never trigger a duplicate-billing warning.
  if (student.has_existing_assessment && !existingTermIsPaid(student)) {
    pendingStudentSelection.value      = student
    showExistingAssessmentModal.value  = true
    searchResults.value                = []
    return
  }
  commitStudentSelection(student)
}

function confirmExistingAssessment() {
  if (pendingStudentSelection.value) {
    commitStudentSelection(pendingStudentSelection.value)
  }
  showExistingAssessmentModal.value = false
  pendingStudentSelection.value     = null
}

function cancelExistingAssessment() {
  showExistingAssessmentModal.value = false
  pendingStudentSelection.value     = null
  studentSearch.value               = ''
}

function clearStudent() {
  selectedStudent.value   = null
  paidSemesters.value     = []
  computedYearLevel.value = ''
  form.user_id            = 0
  form.year_level         = ''
  form.lec_units          = 0
  form.lab_units          = 0
  selectedSubjects.value  = []
  curriculumMessage.value = ''
}

// ─── Subject State ────────────────────────────────────────────────────────────
// selectedSubjects is the single source of truth for everything:
// which subjects are enrolled, whether NSTP is present, billing unit counts.
// There is no separate NSTP toggle — NSTP presence is purely derived from this list.

const selectedSubjects  = ref<SubjectRow[]>([])

// Subject search (for adding subjects)
const subjectSearchQuery   = ref('')
const subjectSearchResults = ref<SubjectRow[]>([])
const subjectSearchLoading = ref(false)
const showSubjectSearch    = ref(false)

let subjectSearchTimeout: ReturnType<typeof setTimeout>

async function searchSubjects() {
  const q = subjectSearchQuery.value.trim()
  if (q.length < 2) { subjectSearchResults.value = []; return }
  subjectSearchLoading.value = true
  clearTimeout(subjectSearchTimeout)
  subjectSearchTimeout = setTimeout(async () => {
    try {
      const params = new URLSearchParams({ q })
      const res    = await fetch(route('student-fees.subject-search') + '?' + params.toString())
      const data   = await res.json()
      const existingIds = new Set(selectedSubjects.value.map(s => s.id))
      subjectSearchResults.value = (data.subjects ?? []).filter((s: SubjectRow) => !existingIds.has(s.id))
    } catch { subjectSearchResults.value = [] }
    finally  { subjectSearchLoading.value = false }
  }, 300)
}

function addSubject(subject: SubjectRow) {
  if (selectedSubjects.value.some(s => s.id === subject.id)) return
  selectedSubjects.value.push(subject)
  subjectSearchQuery.value   = ''
  subjectSearchResults.value = []
  showSubjectSearch.value    = false
}

function removeSubject(subjectId: number) {
  selectedSubjects.value = selectedSubjects.value.filter(s => s.id !== subjectId)
}

// ─── Curriculum Auto-Populate ─────────────────────────────────────────────────

const curriculumLoading = ref(false)
const curriculumMessage = ref('')

async function loadCurriculum() {
  const student = selectedStudent.value
  if (!student) return
  if (student.is_irregular) {
    selectedSubjects.value  = []
    curriculumMessage.value = ''
    return
  }
  if (!form.semester) return

  curriculumLoading.value = true
  selectedSubjects.value  = []
  curriculumMessage.value = ''

  try {
    const url = route('student-fees.curriculum-units')
      + '?student_id=' + student.id
      + '&semester='   + encodeURIComponent(form.semester)
      + '&year_level=' + encodeURIComponent(computedYearLevel.value || student.year_level)

    const res  = await fetch(url)
    const data = await res.json()

    if (data.found) {
      if (data.source === 'subjects' && data.subjects?.length) {
        selectedSubjects.value  = data.subjects
        curriculumMessage.value = ''
      } else {
        // Preset-only aggregate response — no subject rows to populate
        curriculumMessage.value = data.message ?? 'Units auto-filled from preset — add subjects manually if needed.'
      }
    } else {
      curriculumMessage.value = data.message ?? 'No curriculum data found. Add subjects manually.'
    }
  } catch {
    curriculumMessage.value = 'Could not load curriculum. Add subjects manually.'
  } finally {
    curriculumLoading.value = false
  }
}

watch([selectedStudent, () => form.semester], () => {
  if (selectedStudent.value) loadCurriculum()
}, { immediate: true })

// ─── NSTP — derived entirely from subject list ────────────────────────────────
//
// hasNstp is a COMPUTED, not a ref. It becomes true the moment an NSTP subject
// appears in selectedSubjects[]. No checkbox, no manual toggle, no split state.
// Removing the NSTP subject from the list is how Accounting opts out of NSTP billing.
//
// NSTP billing rule:
//   - NSTP subjects accumulate their actual lec_units into nstpLecUnits (currently 1.5 in DB).
//   - subjects.lec_units is the source of truth — no hardcoded constant.
//   - nstpLecUnits feeds directly into totalLecUnits alongside derivedLecUnits.
//   - NSTP subjects have is_billable=false so they are excluded from derivedLecUnits.
//   - At 100% discount: billable units → ₱0; NSTP units are charged at full price.
//   - At partial discount: discount applies to the full totalLecUnits (incl. NSTP units).

const hasNstp = computed(() =>
  selectedSubjects.value.some(s => s.is_nstp),
)

const nstpSubject = computed(() =>
  selectedSubjects.value.find(s => s.is_nstp) ?? null,
)

// Sum actual lec_units from all NSTP subjects in the selection.
// subjects.lec_units is the source of truth (currently 1.5 for all CCDI NSTP subjects).
// No hardcoded constant — if the unit value changes in the DB, billing follows automatically.
const nstpLecUnits = computed(() =>
  selectedSubjects.value
    .filter(s => s.is_nstp)
    .reduce((sum, s) => sum + (s.lec_units ?? 0), 0)
)


// ─── Derived billing counts from subject list ─────────────────────────────────

const isIrregular = computed(() => selectedStudent.value?.is_irregular ?? false)

// Lec units: sum of all billable subject lec_units.
// is_billable=true for every subject EXCEPT NSTP (which uses a fixed 1.5-unit override).
// PATHFIT is fully billable — its lec units count toward tuition like any other subject.
const derivedLecUnits = computed(() =>
  selectedSubjects.value
    .filter(s => s.is_billable)
    .reduce((sum, s) => sum + (s.lec_units || 0), 0),
)

// Lab count: number of billable subjects that carry at least one lab unit.
// PATHFIT subjects with lab units are included here correctly.
const derivedLabUnits = computed(() =>
  selectedSubjects.value.filter(s => s.is_billable && (s.lab_units || 0) > 0).length,
)

// Sync form fields from computed subject list for regular students.
// Irregular students use the Manual Override card below.
watch([derivedLecUnits, derivedLabUnits, nstpLecUnits], () => {
  if (!isIrregular.value) {
    form.lec_units      = derivedLecUnits.value
    form.lab_units      = derivedLabUnits.value
    form.nstp_lec_units = nstpLecUnits.value
  }
}, { immediate: true })

// Sync manual_subject_ids on every change to selectedSubjects
watch(selectedSubjects, (subjects) => {
  form.manual_subject_ids = subjects.map(s => s.id)
}, { deep: true })

// ─── Live Fee Computation ─────────────────────────────────────────────────────

const rate = computed(() => props.feeRates.tuition_per_unit)

// Total billed lec units = regular billable units + NSTP fixed 1.5 (when present)
const totalLecUnits = computed(() => Number(form.lec_units) + nstpLecUnits.value)

const rawTotalTuition    = computed(() => totalLecUnits.value * rate.value)
const rawBillableTuition = computed(() => Number(form.lec_units) * rate.value)
const nstpTuition        = computed(() => nstpLecUnits.value * rate.value)

const pct = computed(() => Number(form.discount_percentage) || 0)

const discountSaving = computed(() => {
  if (pct.value === 100) return rawBillableTuition.value        // NSTP excluded from full waiver
  if (pct.value > 0) return Math.round(rawTotalTuition.value * (pct.value / 100) * 100) / 100
  return 0
})

const tuitionFee = computed(() => {
  if (pct.value === 100) return nstpTuition.value               // Only NSTP remains at 100%
  return Math.round((rawTotalTuition.value - discountSaving.value) * 100) / 100
})

const entrepreneurFee = computed(() =>
  Number(form.lab_units) > 0 ? (props.feeRates.entrepreneurship_fee ?? 600) : 0,
)
const labFee      = computed(() => Number(form.lab_units) * props.feeRates.lab_fee_per_subject)
const labFeeTotal = computed(() => labFee.value + entrepreneurFee.value)
const miscFee     = computed(() => props.feeRates.misc_total)

const totalAssessment = computed(() =>
  tuitionFee.value + labFee.value + entrepreneurFee.value + miscFee.value,
)

const tuitionAndLab = computed(() =>
  tuitionFee.value + labFee.value + entrepreneurFee.value,
)

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

  form.user_id            = selectedStudent.value.id
  form.year_level         = computedYearLevel.value || selectedStudent.value.year_level
  form.nstp_lec_units     = nstpLecUnits.value
  form.term_percentages   = { ...editablePercentages.value }
  form.manual_subject_ids = selectedSubjects.value.map(s => s.id)
  form.discount_name      = scholarshipName.value

  form.post(route('student-fees.store'), {
    onError:  (errors) => console.error('[submit] validation errors:', errors),
    onSuccess: ()      => console.log('[submit] success'),
    onFinish: ()       => { submitting.value = false },
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
                    type="button"
                    class="w-full text-left px-4 py-3 hover:bg-accent transition-colors border-b last:border-0"
                    @click="selectStudent(s)"
                  >
                    <p class="font-medium text-sm flex items-center gap-2 flex-wrap">
                      {{ s.name }}
                      <span v-if="s.is_irregular" class="text-xs text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded">Irregular</span>
                      <span v-if="s.paid_semesters?.length"
                        class="text-xs text-green-700 bg-green-100 px-1.5 py-0.5 rounded flex items-center gap-1">
                        <CheckCircle2 class="h-3 w-3" />
                        {{ s.paid_semesters.length }} sem{{ s.paid_semesters.length > 1 ? 's' : '' }} paid
                      </span>
                      <template v-if="s.has_existing_assessment">
                        <!-- Green: existing assessment is fully paid — informational only -->
                        <span
                          v-if="existingTermIsPaid(s)"
                          class="inline-flex items-center gap-1 text-[10px] font-semibold bg-green-100 text-green-800 border border-green-300 px-1.5 py-0.5 rounded"
                        >
                          <CheckCircle2 class="h-3 w-3" />
                          Paid: {{ s.existing_assessment_term }}
                        </span>
                        <!-- Amber: existing assessment is NOT fully paid — real warning -->
                        <span
                          v-else
                          class="inline-flex items-center gap-1 text-[10px] font-semibold bg-amber-100 text-amber-800 border border-amber-300 px-1.5 py-0.5 rounded"
                        >
                          <AlertTriangle class="h-3 w-3" />
                          Unpaid: {{ s.existing_assessment_term }}
                        </span>
                      </template>
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

          <!-- Paid Semester History -->
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

          <!-- ── Enrolled Subjects ───────────────────────────────────────── -->
          <Card>
            <CardHeader>
              <CardTitle class="flex items-center gap-2 text-base">
                <BookOpen class="h-4 w-4" />
                Enrolled Subjects
                <span class="ml-auto text-xs font-normal text-muted-foreground">
                  Auto-loaded from curriculum · add or remove as needed
                </span>
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">

              <!-- Loading skeleton -->
              <div v-if="curriculumLoading" class="space-y-2">
                <div v-for="i in 5" :key="i"
                     class="h-8 rounded bg-gray-100 animate-pulse"
                     :style="{ width: (75 + i * 4) + '%' }" />
                <p class="text-xs text-muted-foreground flex items-center gap-1.5">
                  <Loader2 class="h-3.5 w-3.5 animate-spin" />
                  Loading curriculum subjects…
                </p>
              </div>

              <!-- Irregular student notice -->
              <div v-else-if="selectedStudent?.is_irregular && !curriculumLoading"
                class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <AlertTriangle class="h-5 w-5 shrink-0 text-amber-600 mt-0.5" />
                <div>
                  <p class="font-semibold">Irregular Student</p>
                  <p class="text-amber-800 text-xs mt-0.5">
                    Curriculum auto-populate is disabled. Use the search below to add subjects individually.
                    If this student takes NSTP, search for and add the NSTP subject — billing will apply automatically.
                  </p>
                </div>
              </div>

              <!-- No student selected -->
              <div v-else-if="!selectedStudent" class="text-center py-6 text-muted-foreground text-sm">
                Select a student to load subjects.
              </div>

              <!-- Curriculum info message (preset fallback or no data) -->
              <div v-if="curriculumMessage && !curriculumLoading && selectedStudent"
                   class="flex items-start gap-2 rounded-md bg-blue-50 border border-blue-100 px-3 py-2 text-xs text-blue-800">
                <Info class="h-3.5 w-3.5 mt-0.5 shrink-0 text-blue-500" />
                {{ curriculumMessage }}
              </div>

              <!-- Subject table -->
              <div v-if="selectedSubjects.length > 0 && !curriculumLoading"
                   class="rounded-xl border border-gray-200 bg-white overflow-hidden">

                <!-- Header bar -->
                <div class="px-5 py-3 bg-gray-50 border-b border-gray-200 flex items-center justify-between">
                  <div>
                    <h3 class="text-sm font-semibold text-gray-700 flex items-center gap-2">
                      <BookOpen class="h-4 w-4 text-blue-500" />
                      {{ selectedSubjects.length }} subject{{ selectedSubjects.length !== 1 ? 's' : '' }} selected
                    </h3>
                    <p class="text-xs text-gray-400 mt-0.5">
                      <span v-if="selectedStudent">
                        {{ selectedStudent.course }}
                        &middot;
                        <span :class="computedYearLevel !== selectedStudent.year_level ? 'text-amber-600 font-medium' : ''">
                          {{ computedYearLevel || selectedStudent.year_level }}
                        </span>
                        &middot; {{ semLabel(form.semester) }}
                        &middot; {{ form.school_year }}
                      </span>
                    </p>
                  </div>
                  <div class="text-right">
                    <p class="text-xs font-semibold text-blue-700">
                      {{ totalLecUnits }} billing units
                    </p>
                    <p v-if="hasNstp" class="text-xs text-amber-600">
                      {{ derivedLecUnits }} regular + {{ nstpLecUnits }} NSTP
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
                      <th class="text-center px-4 py-2 w-24">Status</th>
                      <th class="text-center px-4 py-2 w-16">Remove</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-100">
                    <tr
                      v-for="subj in selectedSubjects"
                      :key="subj.id"
                      :class="[
                        'transition-colors',
                        subj.is_nstp ? 'bg-amber-50/70' : 'bg-white hover:bg-gray-50/60'
                      ]"
                    >
                      <td class="px-5 py-2.5 font-mono text-xs font-semibold text-gray-700">
                        {{ subj.code }}
                      </td>
                      <td class="px-5 py-2.5">
                        <p class="text-gray-800">{{ subj.name }}</p>
                        <p v-if="subj.course !== selectedStudent?.course"
                           class="text-xs text-indigo-600 font-medium">
                          {{ subj.course }} · {{ subj.year_level }}
                        </p>
                      </td>
                      <td class="px-4 py-2.5 text-center font-mono">
                        <!-- Lec units — same source for NSTP and regular: subject.lec_units from DB -->
                        <template v-if="subj.is_nstp">
                          <span class="font-semibold text-amber-700">{{ subj.lec_units }}</span>
                        </template>
                        <template v-else>
                          <span class="text-gray-700">{{ subj.lec_units || '—' }}</span>
                        </template>
                      </td>
                      <td class="px-4 py-2.5 text-center font-mono text-gray-700">
                        {{ subj.lab_units || '—' }}
                      </td>
                      <td class="px-4 py-2.5 text-center">
                        <!-- NSTP: billed at subject.lec_units (DB is source of truth) -->
                        <!-- All other subjects (including PATHFIT) are fully billable -->
                        <template v-if="subj.is_nstp">
                          <span class="inline-flex flex-col items-center gap-0.5 rounded-lg bg-amber-100 border border-amber-300 px-2.5 py-1 text-amber-800">
                            <span class="text-xs font-bold leading-tight">NSTP</span>
                            <span class="text-xs font-normal text-amber-600 leading-tight whitespace-nowrap">billed: {{ subj.lec_units }} units</span>
                          </span>
                        </template>
                        <template v-else>
                          <span class="inline-flex items-center gap-1 rounded-full bg-green-100 border border-green-300 px-2 py-0.5 text-xs font-semibold text-green-800">
                            <CheckCircle2 class="h-3 w-3" />
                            Billable
                          </span>
                        </template>
                      </td>
                      <td class="px-4 py-2.5 text-center">
                        <button
                          type="button"
                          class="inline-flex items-center justify-center h-7 w-7 rounded-md text-red-400 hover:text-red-600 hover:bg-red-50 transition-colors"
                          :title="subj.is_nstp ? 'Remove NSTP subject (disables NSTP billing)' : 'Remove subject'"
                          @click="removeSubject(subj.id)"
                        >
                          <Trash2 class="h-4 w-4" />
                        </button>
                      </td>
                    </tr>
                  </tbody>

                  <!-- Billing summary footer -->
                  <tfoot class="bg-gray-50 border-t-2 border-gray-200">
                    <tr>
                      <td colspan="2" class="px-5 py-2.5 text-xs font-semibold text-gray-600 uppercase tracking-wide">
                        Billing Summary
                      </td>
                      <!-- Billing units column -->
                      <td class="px-4 py-2.5 text-center font-mono font-bold text-blue-700">
                        {{ totalLecUnits }}
                        <span v-if="hasNstp" class="block text-xs font-normal text-amber-600 whitespace-nowrap">
                          {{ derivedLecUnits }} + {{ nstpLecUnits }}
                        </span>
                      </td>
                      <td class="px-4 py-2.5 text-center font-mono font-bold text-gray-700">
                        {{ form.lab_units }}
                      </td>
                      <td colspan="2" class="px-4 py-2.5 text-xs text-gray-500">
                        <span class="inline-flex items-center gap-3 flex-wrap">
                          <span class="flex items-center gap-1">
                            <span class="w-2 h-2 rounded-full bg-green-400 inline-block"></span>
                            Billable lec: <strong class="text-gray-700">{{ derivedLecUnits }}</strong>
                          </span>
                          <span v-if="hasNstp" class="flex items-center gap-1">
                            <span class="w-2 h-2 rounded-full bg-amber-400 inline-block"></span>
                            NSTP: <strong class="text-amber-700">{{ nstpLecUnits }} billed</strong>
                          </span>
                          <span class="flex items-center gap-1">
                            <span class="w-2 h-2 rounded-full bg-orange-400 inline-block"></span>
                            Lab subjects: <strong class="text-gray-700">{{ form.lab_units }}</strong>
                          </span>
                        </span>
                      </td>
                    </tr>
                  </tfoot>
                </table>

                <!-- NSTP billing info banner — shown inline within the table card when NSTP is present -->
                <!-- This replaces the old standalone checkbox. It's purely informational. -->
                <div v-if="hasNstp"
                     class="border-t border-amber-200 bg-amber-50/80 px-5 py-3 flex items-start gap-3">
                  <div class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-amber-400 text-white text-xs font-bold">
                    N
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="text-xs font-semibold text-amber-900">
                      NSTP billing active — {{ formatCurrency(nstpTuition) }}
                    </p>
                    <p class="text-xs text-amber-700 mt-0.5 leading-relaxed">
                      <strong>{{ nstpSubject?.code }}</strong> is billed at
                      <strong>{{ nstpLecUnits }} units ({{ formatCurrency(nstpTuition) }})</strong>
                      — the value in the subjects table is the authoritative billing unit count.
                      <template v-if="pct === 100">
                        At <strong>100% discount</strong>, NSTP is excluded from the full waiver
                        and charged at full price.
                      </template>
                      <template v-else-if="pct > 0">
                        The <strong>{{ pct }}% discount</strong> applies to all {{ totalLecUnits }} billing units including NSTP.
                      </template>
                      To opt out of NSTP billing, remove this subject using the
                      <Trash2 class="inline h-3 w-3 text-red-400" /> button above.
                    </p>
                  </div>
                  <div class="shrink-0 text-right">
                    <p class="text-xs font-mono font-bold text-amber-800">{{ formatCurrency(nstpTuition) }}</p>
                    <p class="text-xs text-amber-600">{{ nstpLecUnits }} units</p>
                  </div>
                </div>
              </div>

              <!-- Empty state -->
              <div v-else-if="selectedStudent && !curriculumLoading && !selectedSubjects.length && !selectedStudent.is_irregular"
                   class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-900">
                <AlertTriangle class="h-5 w-5 shrink-0 text-amber-600 mt-0.5" />
                <div>
                  <p class="font-semibold">No Curriculum Subjects Found</p>
                  <p class="text-xs text-amber-800 mt-0.5">
                    No subjects were found for {{ selectedStudent.course }} —
                    {{ computedYearLevel || selectedStudent.year_level }} — {{ semLabel(form.semester) }}.
                    Add subjects manually using the search below.
                  </p>
                </div>
              </div>

              <!-- ── Add Subject Search ──────────────────────────────────── -->
              <div v-if="selectedStudent && !curriculumLoading" class="pt-2">

                <div v-if="!showSubjectSearch">
                  <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-md border border-dashed border-blue-300 bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 hover:bg-blue-100 transition-colors"
                    @click="showSubjectSearch = true"
                  >
                    <Plus class="h-4 w-4" />
                    Add Subject
                  </button>
                </div>

                <div v-else class="rounded-xl border border-blue-200 bg-blue-50/60 p-4 space-y-3">
                  <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold text-blue-800">Search &amp; Add Subject</p>
                    <button
                      type="button"
                      class="text-blue-400 hover:text-blue-600"
                      @click="showSubjectSearch = false; subjectSearchQuery = ''; subjectSearchResults = []"
                    >
                      <X class="h-4 w-4" />
                    </button>
                  </div>

                  <div class="relative">
                    <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                    <Input
                      v-model="subjectSearchQuery"
                      class="pl-9 bg-white"
                      placeholder="Search by subject code or name… (any course)"
                      @input="searchSubjects"
                      autofocus
                    />
                    <Loader2 v-if="subjectSearchLoading"
                      class="absolute right-3 top-1/2 -translate-y-1/2 h-4 w-4 animate-spin text-muted-foreground" />
                  </div>

                  <p class="text-xs text-blue-600">
                    Subjects from any course can be added — for cross-course enrollees or irregular students.
                    Adding an NSTP subject activates NSTP billing automatically.
                  </p>

                  <div v-if="subjectSearchResults.length > 0"
                       class="rounded-md border border-blue-200 bg-white divide-y divide-gray-100 max-h-72 overflow-y-auto shadow-sm">
                    <button
                      v-for="s in subjectSearchResults"
                      :key="s.id"
                      type="button"
                      class="w-full text-left px-4 py-2.5 hover:bg-blue-50 transition-colors"
                      @click="addSubject(s)"
                    >
                      <div class="flex items-start justify-between gap-2">
                        <div>
                          <p class="text-sm font-semibold text-gray-800">
                            <span class="font-mono text-xs text-gray-500 mr-1.5">{{ s.code }}</span>
                            {{ s.name }}
                          </p>
                          <p class="text-xs text-muted-foreground">
                            {{ s.course }} · {{ s.year_level }} · {{ s.semester }}
                            · Lec: {{ s.lec_units }} · Lab: {{ s.lab_units }}
                          </p>
                        </div>
                        <div class="shrink-0 flex flex-col items-end gap-0.5">
                          <span v-if="s.is_nstp"
                                class="text-xs font-semibold text-amber-700 bg-amber-100 border border-amber-200 px-1.5 py-0.5 rounded">
                            NSTP · billed {{ s.lec_units }} units
                          </span>
                          <span v-else
                                class="text-xs font-semibold text-green-700 bg-green-100 border border-green-200 px-1.5 py-0.5 rounded">
                            Billable
                          </span>
                          <Plus class="h-3.5 w-3.5 text-blue-500 mt-1" />
                        </div>
                      </div>
                    </button>
                  </div>

                  <p v-else-if="subjectSearchQuery.length >= 2 && !subjectSearchLoading"
                     class="text-sm text-muted-foreground text-center py-2">
                    No subjects found for "{{ subjectSearchQuery }}"
                  </p>
                </div>
              </div>

            </CardContent>
          </Card>

          <!-- Irregular manual overrides -->
          <Card v-if="isIrregular && selectedStudent">
            <CardHeader>
              <CardTitle class="flex items-center gap-2 text-base">
                <BookOpen class="h-4 w-4" />
                Manual Unit Override
                <span class="ml-auto text-xs font-normal text-muted-foreground">Irregular students only</span>
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
              <div class="grid grid-cols-2 gap-6">
                <div class="space-y-1.5">
                  <Label for="lec_units" class="flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-blue-500 inline-block"></span>
                    Lecture Units (billable)
                  </Label>
                  <Input id="lec_units" type="number"
                    v-model.number="form.lec_units"
                    min="0" max="50" step="0.5" class="text-center text-lg font-semibold" />
                  <p class="text-xs text-muted-foreground text-center">
                    {{ form.lec_units }} units × {{ formatCurrency(feeRates.tuition_per_unit) }} / unit
                  </p>
                  <p v-if="form.errors.lec_units" class="text-sm text-destructive">{{ form.errors.lec_units }}</p>
                </div>

                <div class="space-y-1.5">
                  <Label for="lab_units" class="flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-orange-500 inline-block"></span>
                    Lab Subjects (count)
                  </Label>
                  <Input id="lab_units" type="number" v-model.number="form.lab_units"
                    min="0" max="20" class="text-center text-lg font-semibold" />
                  <p class="text-xs text-muted-foreground text-center">× {{ formatCurrency(feeRates.lab_fee_per_subject) }} / subject</p>
                  <p v-if="form.errors.lab_units" class="text-sm text-destructive">{{ form.errors.lab_units }}</p>
                </div>
              </div>
              <p class="text-xs text-muted-foreground flex items-center gap-1.5">
                <Info class="h-3.5 w-3.5 text-blue-400" />
                NSTP billing is controlled by the subject list above.
                If this student takes NSTP, add the NSTP subject via the search — billing applies automatically.
              </p>
            </CardContent>
          </Card>

          <!-- ── Scholarship / Discount ─────────────────────────────────── -->
          <Card>
            <CardHeader>
              <CardTitle class="text-base flex items-center gap-2">
                <span class="text-amber-600">🎓</span>
                Scholarship / Discount
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">

              <!-- 100% + NSTP warning -->
              <div
                v-if="hasNstp && pct === 100"
                class="flex items-start gap-2 rounded-md bg-amber-50 border border-amber-300 p-3 text-sm text-amber-900"
              >
                <AlertTriangle class="h-4 w-4 mt-0.5 shrink-0 text-amber-600" />
                <div>
                  <p class="font-semibold">100% Discount — NSTP Exception</p>
                  <p class="text-xs text-amber-800 mt-0.5">
                    All billable lecture units ({{ derivedLecUnits }}) are fully discounted to ₱0.
                    NSTP ({{ nstpLecUnits }} units, {{ formatCurrency(nstpTuition) }}) is excluded from the 100% discount
                    and charged at full price.
                  </p>
                </div>
              </div>

              <!-- Scholarship type selector -->
              <div class="space-y-2">
                <Label>Scholarship / Discount Type</Label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                  <button
                    v-for="preset in SCHOLARSHIP_PRESETS"
                    :key="preset.value"
                    type="button"
                    @click="onScholarshipPresetChange(preset.value)"
                    :class="[
                      'text-left rounded-lg border px-3 py-2.5 text-sm transition-colors',
                      selectedScholarshipPreset === preset.value
                        ? 'border-amber-500 bg-amber-50 text-amber-900 shadow-sm'
                        : 'border-input bg-background text-muted-foreground hover:bg-muted',
                    ]"
                  >
                    <p class="font-medium leading-tight">{{ preset.label }}</p>
                    <p v-if="preset.pct !== null && preset.pct > 0" class="text-xs mt-0.5 opacity-75">
                      {{ preset.pct }}% off tuition
                    </p>
                    <p v-else-if="preset.value === ''" class="text-xs mt-0.5 opacity-75">
                      Full tuition applies
                    </p>
                    <p v-else class="text-xs mt-0.5 opacity-75">
                      Enter custom %
                    </p>
                  </button>
                </div>
              </div>

              <!-- Custom scholarship name -->
              <div v-if="selectedScholarshipPreset === '__custom__'" class="space-y-1.5">
                <Label for="custom_scholarship_name">Scholarship / Grant Name</Label>
                <Input
                  id="custom_scholarship_name"
                  v-model="customScholarshipName"
                  placeholder="e.g. LGU Scholars Program, Athletic Grant, etc."
                  class="w-full"
                />
                <p class="text-xs text-muted-foreground">
                  This label will appear on the student's Statement of Account.
                </p>
              </div>

              <!-- Active scholarship label display -->
              <div
                v-if="scholarshipName && selectedScholarshipPreset !== '__custom__'"
                class="flex items-center gap-2 rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-sm"
              >
                <span class="text-amber-600">🎓</span>
                <span class="font-semibold text-amber-900">{{ scholarshipName }}</span>
                <span v-if="pct > 0" class="text-amber-700 text-xs ml-auto">{{ pct }}% discount</span>
              </div>

              <!-- Discount percentage input -->
              <div class="space-y-2">
                <Label for="discount_percentage">
                  Discount Percentage (%)
                  <span class="ml-1 text-xs font-normal text-muted-foreground">
                    — auto-set by scholarship type, override if needed
                  </span>
                </Label>
                <p class="text-xs text-muted-foreground">
                  <template v-if="hasNstp">
                    For partial discounts (&lt;100%): applies to all {{ totalLecUnits }} billing units (including NSTP {{ nstpLecUnits }}).
                    At exactly 100%: all billable units waived, NSTP ({{ formatCurrency(nstpTuition) }}) charged at full price.
                    Lab and misc fees are never discounted.
                  </template>
                  <template v-else>
                    Applies to all {{ form.lec_units }} lecture units.
                    Lab and miscellaneous fees are never discounted.
                  </template>
                </p>
                <div class="flex items-center gap-3">
                  <Input
                    id="discount_percentage"
                    type="number"
                    v-model.number="form.discount_percentage"
                    min="0" max="100" step="0.01"
                    placeholder="0.00"
                    class="w-28 text-center text-lg font-semibold"
                  />
                  <span class="text-sm text-muted-foreground">% off lecture units</span>
                </div>
                <p v-if="form.errors.discount_percentage" class="text-sm text-destructive">
                  {{ form.errors.discount_percentage }}
                </p>
              </div>

              <!-- Live discount breakdown -->
              <div v-if="pct > 0"
                   class="rounded-md bg-green-50 border border-green-200 p-3 space-y-1.5 text-sm">
                <p class="font-semibold text-xs uppercase tracking-wide text-green-700 mb-2">
                  Effective Fees After Discount
                  <span v-if="scholarshipName" class="ml-1 font-normal normal-case text-green-600">
                    ({{ scholarshipName }})
                  </span>
                </p>

                <template v-if="pct < 100">
                  <template v-if="hasNstp">
                    <div class="flex justify-between text-green-800 text-xs">
                      <span>Billable tuition ({{ derivedLecUnits }} units × {{ formatCurrency(rate) }})</span>
                      <span>{{ formatCurrency(rawBillableTuition) }}</span>
                    </div>
                    <div class="flex justify-between text-amber-700 text-xs">
                      <span>NSTP tuition ({{ nstpLecUnits }} units × {{ formatCurrency(rate) }})</span>
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
                    <span>
                      − {{ pct }}% discount
                      ({{ hasNstp ? `applied to all ${totalLecUnits} units incl. NSTP` : `applied to ${form.lec_units} units` }})
                    </span>
                    <span>− {{ formatCurrency(discountSaving) }}</span>
                  </div>
                  <div class="flex justify-between text-green-900 font-medium pt-1 border-t border-green-200">
                    <span>Total Tuition</span>
                    <span>{{ formatCurrency(tuitionFee) }}</span>
                  </div>
                </template>

                <template v-else>
                  <div class="flex justify-between text-green-800 text-xs">
                    <span>Billable tuition ({{ derivedLecUnits }} units × {{ formatCurrency(rate) }})</span>
                    <span>{{ formatCurrency(rawBillableTuition) }}</span>
                  </div>
                  <div class="flex justify-between text-green-600 text-xs">
                    <span>− 100% discount (full waiver on {{ derivedLecUnits }} billable units)</span>
                    <span>− {{ formatCurrency(discountSaving) }}</span>
                  </div>
                  <template v-if="hasNstp">
                    <div class="flex justify-between text-amber-800 text-xs font-medium">
                      <span>NSTP ({{ nstpLecUnits }} units — excluded from 100% discount)</span>
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
                    Tuition Fee
                    <span class="block text-xs">
                      {{ totalLecUnits }} lec × {{ formatCurrency(feeRates.tuition_per_unit) }}
                      <span v-if="hasNstp" class="text-amber-600">
                        ({{ derivedLecUnits }} + {{ nstpLecUnits }} NSTP)
                      </span>
                    </span>
                  </span>
                  <span class="font-medium">{{ formatCurrency(tuitionFee) }}</span>
                </div>

                <!-- Scholarship discount pill -->
                <div
                  v-if="discountSaving > 0"
                  class="ml-2 flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="mt-px h-3.5 w-3.5 shrink-0 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <span class="text-emerald-700 leading-snug">
                    <span class="font-semibold">{{ scholarshipName || pct + '%' }} discount applied</span>
                    <span class="mx-1 text-emerald-400">—</span>
                    <span class="font-bold text-emerald-800">−{{ formatCurrency(discountSaving) }} saved</span>
                    <span v-if="pct === 100 && hasNstp" class="ml-1 font-medium text-amber-600">
                      · NSTP billed at full price
                    </span>
                  </span>
                </div>

                <div class="flex justify-between">
                  <span class="text-muted-foreground">
                    Lab. Fee ({{ form.lab_units }} subj × {{ formatCurrency(feeRates.lab_fee_per_subject) }})
                  </span>
                  <span class="font-medium">{{ formatCurrency(labFeeTotal) }}</span>
                </div>
                <div v-if="entrepreneurFee > 0" class="flex justify-between text-xs text-amber-600 pl-2">
                  <span>incl. Entrepreneurship Fee</span>
                  <span>{{ formatCurrency(entrepreneurFee) }}</span>
                </div>

                <div class="flex justify-between">
                  <span class="text-muted-foreground">Misc. Fee (fixed)</span>
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

    <!-- ─── Existing Assessment Confirmation Modal ───────────────────────── -->
    <Teleport to="body">
      <div
        v-if="showExistingAssessmentModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
      >
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/50" @click="cancelExistingAssessment" />

        <!-- Dialog -->
        <div class="relative z-10 w-full max-w-md rounded-lg border bg-white shadow-xl p-6 space-y-4">
          <div class="flex items-start gap-3">
            <div class="shrink-0 flex h-10 w-10 items-center justify-center rounded-full bg-amber-100">
              <AlertTriangle class="h-5 w-5 text-amber-600" />
            </div>
            <div>
              <h3 class="text-base font-semibold text-gray-900">Unpaid Assessment Exists</h3>
              <p class="mt-1 text-sm text-gray-600">
                <strong>{{ pendingStudentSelection?.name }}</strong> already has an
                <strong>unpaid</strong> assessment for
                <strong>{{ pendingStudentSelection?.existing_assessment_term }}</strong>.
              </p>
              <p class="mt-2 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded px-3 py-2">
                Creating a second assessment for the same term will result in duplicate billing.
                Only continue if this is intentional (e.g. correcting a previous assessment).
              </p>
            </div>
          </div>

          <div class="flex justify-end gap-2 pt-2">
            <Button variant="outline" size="sm" @click="cancelExistingAssessment">
              Cancel
            </Button>
            <Button
              size="sm"
              class="bg-amber-600 hover:bg-amber-700 text-white"
              @click="confirmExistingAssessment"
            >
              Yes, Continue
            </Button>
          </div>
        </div>
      </div>
    </Teleport>

  </AppLayout>
</template>