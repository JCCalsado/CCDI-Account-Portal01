<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useDataFormatting } from '@/composables/useDataFormatting'
import {
    AlertTriangle, BookOpen, Check, ChevronLeft,
    Info, Loader2, Plus, RefreshCw, Sparkles, Trash2,
} from 'lucide-vue-next'

// ─── Types ────────────────────────────────────────────────────────────────────

interface Preset {
    id: number
    course: string
    year_level: string
    semester: string
    lec_units: number
    lab_units: number
    lab_subject_count: number
    is_active: boolean
}

interface LinkedSubject {
    id: number
    subject_id: number | null
    code: string
    name: string
    semester: string | null      // '1st Sem' or '2nd Sem' — visible in Summer presets
    lec_units: number
    lab_units: number
    is_nstp: boolean
    sort_order: number
    tuition_fee: number
    lab_fee: number
    total_fee: number
    current_tuition: number
    current_lab_fee: number
    current_total_fee: number
    fees_are_stale: boolean
}

interface AvailableSubject {
    id: number
    code: string
    name: string
    semester: string            // '1st Sem' or '2nd Sem' — used for optgroup in Summer presets
    lec_units: number
    lab_units: number
    is_nstp: boolean
}

interface Rates {
    tuition_per_unit: number
    lab_fee_per_subject: number
    entrepreneurship_fee: number
}

// ─── Props ────────────────────────────────────────────────────────────────────

const props = defineProps<{
    preset: Preset
    linkedSubjects: LinkedSubject[]
    availableSubjects: AvailableSubject[]
    rates: Rates
    backUrl: string
    isNew: boolean
    storeRoute: string
    destroyRoute: string
    syncRoute: string
}>()

const { formatCurrency } = useDataFormatting()

// ─── Breadcrumbs ──────────────────────────────────────────────────────────────

const breadcrumbs = [
    { title: 'Dashboard',          href: route('accounting.dashboard') },
    { title: 'Curriculum Presets', href: route('accounting.curriculum-presets.index') },
    { title: `${props.preset.course} — ${props.preset.year_level} — ${props.preset.semester}`, href: '#' },
]

// ─── Flash ────────────────────────────────────────────────────────────────────

const page = usePage()
const flashSuccess = computed(() => (page.props.flash as any)?.success ?? '')

// ─── Stale fee detection ──────────────────────────────────────────────────────

const hasStaleSubjects = computed(() =>
    props.linkedSubjects.some((s) => s.fees_are_stale)
)

// ─── Summer preset detection ──────────────────────────────────────────────────
//
// Summer presets draw subjects from '1st Sem' and '2nd Sem' of the same year
// level — no subject is ever classified as 'Summer'. This flag drives:
//   1. The "Add Subject" optgroup grouping (1st Sem / 2nd Sem sections)
//   2. The empty-state / tooltip create links (semester not pre-filled for Summer)
//   3. The linked subjects table showing each subject's semester column

const isSummerPreset = computed(() => props.preset.semester === 'Summer')

// ─── Context create URLs ──────────────────────────────────────────────────────
//
// These link to accounting.subjects.create with context query params pre-filled.
// For Summer presets, semester is intentionally omitted from the URL because
// subjects cannot be tagged 'Summer' — the user must pick 1st or 2nd Sem.
// Two separate links are shown for Summer so the user knows which semester
// classification to choose.

function buildCreateUrl(semesterOverride?: string): string {
    const base   = route('accounting.subjects.create')
    const params: Record<string, string> = {
        course:     props.preset.course,
        year_level: props.preset.year_level,
    }
    if (semesterOverride) {
        params.semester = semesterOverride
    }
    return `${base}?${new URLSearchParams(params).toString()}`
}

// For non-Summer presets: single link pre-filled with the preset's exact semester
const createSubjectUrl = computed(() =>
    isSummerPreset.value
        ? buildCreateUrl()                  // no semester — user picks in the form
        : buildCreateUrl(props.preset.semester)
)

// For Summer: two convenience links, one per semester
const createUrl1stSem = computed(() => buildCreateUrl('1st Sem'))
const createUrl2ndSem = computed(() => buildCreateUrl('2nd Sem'))

// ─── Grouped available subjects (Summer only) ─────────────────────────────────

const available1stSem = computed(() =>
    props.availableSubjects.filter((s) => s.semester === '1st Sem')
)
const available2ndSem = computed(() =>
    props.availableSubjects.filter((s) => s.semester === '2nd Sem')
)

// ─── Add subject form ─────────────────────────────────────────────────────────

const showAddForm   = ref(props.isNew && props.availableSubjects.length > 0)
const selectedSubId = ref<number | null>(null)
const addSaving     = ref(false)

const selectedAvailableSubject = computed(() =>
    props.availableSubjects.find((s) => s.id === selectedSubId.value) ?? null
)

const addPreviewFee = computed(() => {
    const s = selectedAvailableSubject.value
    if (!s) return null
    const tuition = s.lec_units * props.rates.tuition_per_unit
    const lab     = (!s.is_nstp && s.lab_units > 0) ? props.rates.lab_fee_per_subject : 0
    return { tuition_fee: tuition, lab_fee: lab, total_fee: tuition + lab }
})

function addSubject() {
    if (!selectedSubId.value || addSaving.value) return
    addSaving.value = true
    router.post(
        props.storeRoute,
        { subject_id: selectedSubId.value },
        {
            onFinish: () => {
                addSaving.value     = false
                selectedSubId.value = null
                showAddForm.value   = false
            },
        }
    )
}

// ─── Remove subject ───────────────────────────────────────────────────────────

const removingId = ref<number | null>(null)

function removeSubject(ps: LinkedSubject) {
    if (removingId.value !== null) return
    if (!confirm(`Remove "${ps.code} — ${ps.name}" from this preset?`)) return
    removingId.value = ps.id
    router.delete(
        route(props.destroyRoute, [props.preset.id, ps.id]),
        { onFinish: () => { removingId.value = null } }
    )
}

// ─── Sync fees ────────────────────────────────────────────────────────────────

const syncing = ref(false)

function syncFees() {
    if (syncing.value) return
    syncing.value = true
    router.post(props.syncRoute, {}, { onFinish: () => { syncing.value = false } })
}

// ─── Totals ───────────────────────────────────────────────────────────────────

const totalTuition = computed(() =>
    props.linkedSubjects.reduce((s, r) => s + r.tuition_fee, 0)
)
const totalLab = computed(() =>
    props.linkedSubjects.reduce((s, r) => s + r.lab_fee, 0)
)
const entrepFee = computed(() => props.rates.entrepreneurship_fee)
const totalLabWithEntrep = computed(() => totalLab.value + entrepFee.value)

const billableSubjects = computed(() =>
    props.linkedSubjects.filter((s) => !s.is_nstp)
)

// Rates panel
const ratesExpanded = ref(false)
</script>

<template>
    <AppLayout>
        <div class="w-full p-6 space-y-6">

            <Breadcrumbs :items="breadcrumbs" />

            <!-- Flash -->
            <div
                v-if="flashSuccess"
                class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"
            >
                <Check class="h-4 w-4 shrink-0 text-green-600" />
                {{ flashSuccess }}
            </div>

            <!-- "Just Created" onboarding banner -->
            <div
                v-if="isNew"
                class="flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-4"
            >
                <Sparkles class="h-5 w-5 shrink-0 text-blue-600 mt-0.5" />
                <div>
                    <p class="font-semibold text-blue-900">Preset created — now add your subjects</p>
                    <p class="text-sm text-blue-700 mt-0.5">
                        Use the <strong>Add Subject</strong> button to populate this preset.
                        Unit aggregates (Lec, Lab) update automatically as subjects are added.
                    </p>
                </div>
            </div>

            <!--
                Summer preset information banner
                Explains the Summer subject classification rule so accounting staff
                don't try to find subjects tagged 'Summer' (they don't exist).
            -->
            <div
                v-if="isSummerPreset"
                class="flex items-start gap-3 rounded-lg border border-violet-200 bg-violet-50 px-4 py-3 text-sm text-violet-900"
            >
                <Info class="h-5 w-5 shrink-0 text-violet-500 mt-0.5" />
                <div>
                    <p class="font-semibold">Summer preset — subjects drawn from 1st &amp; 2nd Semester</p>
                    <p class="text-violet-800 text-xs mt-0.5">
                        CCDI Summer classes offer subjects from both semesters of
                        <strong>{{ preset.year_level }}</strong> in a compressed schedule.
                        Subjects are tagged as <strong>1st Sem</strong> or <strong>2nd Sem</strong> —
                        never as "Summer". The dropdown below groups them by their semester for clarity.
                    </p>
                </div>
            </div>

            <!-- Header -->
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div class="flex items-center gap-3">
                    <BookOpen class="h-6 w-6 text-blue-600" />
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight">Preset Subjects</h1>
                        <p class="text-sm text-muted-foreground">
                            {{ preset.course }} &middot; {{ preset.year_level }} &middot; {{ preset.semester }}
                        </p>
                    </div>
                </div>
                <div class="flex gap-2 flex-wrap">
                    <Button variant="outline" size="sm" @click="router.visit(backUrl)">
                        <ChevronLeft class="h-4 w-4 mr-1" />
                        Back to Curriculum Presets
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="syncing || linkedSubjects.length === 0"
                        @click="syncFees"
                        :class="hasStaleSubjects ? 'border-amber-400 text-amber-700 hover:bg-amber-50' : ''"
                    >
                        <Loader2 v-if="syncing" class="h-4 w-4 mr-1.5 animate-spin" />
                        <RefreshCw v-else class="h-4 w-4 mr-1.5" />
                        {{ syncing ? 'Syncing…' : 'Sync Fees to Current Rates' }}
                    </Button>

                    <!-- Add Subject button + tooltip when disabled -->
                    <div class="relative group/addbtn">
                        <Button
                            size="sm"
                            :disabled="availableSubjects.length === 0"
                            @click="showAddForm = !showAddForm"
                        >
                            <Plus class="h-4 w-4 mr-1.5" />
                            Add Subject
                        </Button>

                        <!--
                            Hover tooltip — only shown when button is disabled (no available subjects).
                            For Summer presets: shows two create links (1st Sem and 2nd Sem separately)
                            because subjects cannot be tagged 'Summer'.
                            For regular presets: shows one create link with semester pre-filled.
                        -->
                        <div
                            v-if="availableSubjects.length === 0"
                            class="absolute right-0 top-full mt-2 w-80 z-20 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-800 shadow-lg opacity-0 group-hover/addbtn:opacity-100 transition-opacity pointer-events-none"
                        >
                            <p class="font-semibold mb-1">No subjects available</p>
                            <p>
                                The <strong>Subjects</strong> registry has no active entries for
                                <strong>{{ preset.course }}</strong> ·
                                <strong>{{ preset.year_level }}</strong>
                                <template v-if="!isSummerPreset"> · <strong>{{ preset.semester }}</strong></template>.
                            </p>

                            <!-- Summer: two separate create links -->
                            <template v-if="isSummerPreset">
                                <p class="mt-1.5">
                                    Summer subjects must be tagged as 1st Sem or 2nd Sem.
                                    Create them first, then return here.
                                </p>
                                <!-- pointer-events-none on tooltip prevents click — links here are informational only -->
                            </template>

                            <!-- Non-Summer: single create link -->
                            <template v-else>
                                <p class="mt-1.5">
                                    Create subjects for this combination in
                                    <strong>Subjects</strong>, then return here.
                                </p>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stale fee warning -->
            <div
                v-if="hasStaleSubjects"
                class="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900"
            >
                <AlertTriangle class="h-5 w-5 shrink-0 text-amber-600 mt-0.5" />
                <div>
                    <p class="font-semibold">Fee rates have changed since some subjects were linked</p>
                    <p class="text-amber-800 text-xs mt-0.5">
                        Subjects highlighted in amber have stored fees that no longer match the current
                        fee_settings rates. Click <strong>Sync Fees to Current Rates</strong> to update them.
                        This does not affect existing student assessments — those are immutable snapshots.
                    </p>
                </div>
            </div>

            <!-- Add subject form -->
            <Card v-if="showAddForm && availableSubjects.length > 0" class="border-blue-200 bg-blue-50/30">
                <CardHeader class="pb-3">
                    <CardTitle class="text-base text-blue-800 flex items-center gap-2">
                        <Plus class="h-4 w-4" /> Add Subject to Preset
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="space-y-1.5">
                        <label class="text-sm font-medium">Select Subject</label>
                        <select
                            v-model="selectedSubId"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                        >
                            <option :value="null" disabled>— choose a subject —</option>

                            <!--
                                Summer preset: group by semester using <optgroup>.
                                Subjects are drawn from both 1st Sem and 2nd Sem —
                                grouping makes the distinction visible to accounting staff.
                            -->
                            <template v-if="isSummerPreset">
                                <optgroup label="1st Semester" v-if="available1stSem.length > 0">
                                    <option
                                        v-for="s in available1stSem"
                                        :key="s.id"
                                        :value="s.id"
                                    >
                                        {{ s.code }} — {{ s.name }}
                                        ({{ s.lec_units }} LEC{{ s.lab_units > 0 ? ` + ${s.lab_units} LAB` : '' }})
                                        {{ s.is_nstp ? '· NSTP' : '' }}
                                    </option>
                                </optgroup>
                                <optgroup label="2nd Semester" v-if="available2ndSem.length > 0">
                                    <option
                                        v-for="s in available2ndSem"
                                        :key="s.id"
                                        :value="s.id"
                                    >
                                        {{ s.code }} — {{ s.name }}
                                        ({{ s.lec_units }} LEC{{ s.lab_units > 0 ? ` + ${s.lab_units} LAB` : '' }})
                                        {{ s.is_nstp ? '· NSTP' : '' }}
                                    </option>
                                </optgroup>
                            </template>

                            <!-- Non-Summer: flat list -->
                            <template v-else>
                                <option
                                    v-for="s in availableSubjects"
                                    :key="s.id"
                                    :value="s.id"
                                >
                                    {{ s.code }} — {{ s.name }}
                                    ({{ s.lec_units }} LEC{{ s.lab_units > 0 ? ` + ${s.lab_units} LAB` : '' }})
                                    {{ s.is_nstp ? '· NSTP' : '' }}
                                </option>
                            </template>
                        </select>
                    </div>

                    <!-- Fee preview -->
                    <div
                        v-if="addPreviewFee && selectedSubId"
                        class="rounded-md bg-white border border-blue-200 p-3 text-xs space-y-1"
                    >
                        <p class="font-semibold text-blue-800 mb-1.5">Fee Preview (current rates)</p>
                        <div class="flex justify-between text-gray-600">
                            <span>
                                Tuition
                                ({{ selectedAvailableSubject?.lec_units }} units
                                × {{ formatCurrency(rates.tuition_per_unit) }})
                            </span>
                            <span class="font-mono">{{ formatCurrency(addPreviewFee.tuition_fee) }}</span>
                        </div>
                        <div v-if="addPreviewFee.lab_fee > 0" class="flex justify-between text-gray-600">
                            <span>Lab Fee (per subject)</span>
                            <span class="font-mono">{{ formatCurrency(addPreviewFee.lab_fee) }}</span>
                        </div>
                        <div class="flex justify-between font-semibold text-blue-900 border-t border-blue-100 pt-1 mt-1">
                            <span>Subject Total</span>
                            <span class="font-mono">{{ formatCurrency(addPreviewFee.total_fee) }}</span>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <Button size="sm" :disabled="!selectedSubId || addSaving" @click="addSubject">
                            <Loader2 v-if="addSaving" class="h-4 w-4 mr-1.5 animate-spin" />
                            <Check v-else class="h-4 w-4 mr-1.5" />
                            {{ addSaving ? 'Adding…' : 'Add to Preset' }}
                        </Button>
                        <Button size="sm" variant="outline" @click="showAddForm = false; selectedSubId = null">
                            Cancel
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <!-- Subject table -->
            <Card>
                <CardHeader class="pb-3">
                    <CardTitle class="text-base flex items-center gap-2">
                        <BookOpen class="h-4 w-4" />
                        Linked Subjects
                        <span class="ml-auto text-xs font-normal text-muted-foreground">
                            {{ linkedSubjects.length }} subject{{ linkedSubjects.length !== 1 ? 's' : '' }}
                            &middot; {{ billableSubjects.length }} billable
                        </span>
                    </CardTitle>
                </CardHeader>
                <CardContent class="p-0">

                    <!-- Empty state -->
                    <div v-if="linkedSubjects.length === 0" class="text-center py-10 text-muted-foreground text-sm">
                        <BookOpen class="h-8 w-8 mx-auto mb-3 opacity-30" />
                        <p>No subjects linked yet.</p>

                        <!-- Empty state when subjects ARE available to add -->
                        <p v-if="availableSubjects.length > 0" class="text-xs mt-1">
                            Click "Add Subject" to populate this preset.
                        </p>

                        <!--
                            Empty state when NO subjects exist in the registry at all.
                            Shows clickable links to the Subject Create form with context
                            pre-filled so the user doesn't have to re-select course/year/semester.

                            Summer preset: two links (one per semester) because subjects
                            cannot be tagged 'Summer' — user must choose 1st or 2nd Sem.
                            Non-Summer: one link with semester pre-filled.
                        -->
                        <template v-else>
                            <p class="text-xs mt-1 text-amber-600">
                                No subjects exist in the registry for
                                <strong>{{ preset.course }}</strong> ·
                                <strong>{{ preset.year_level }}</strong>
                                <template v-if="!isSummerPreset"> · <strong>{{ preset.semester }}</strong></template>.
                            </p>

                            <!-- Summer: explain the two-semester rule + two links -->
                            <template v-if="isSummerPreset">
                                <p class="text-xs mt-1.5 text-amber-700">
                                    Summer subjects are classified as <strong>1st Sem</strong>
                                    or <strong>2nd Sem</strong> — create them first, then return here.
                                </p>
                                <div class="flex items-center justify-center gap-3 mt-2">
                                    <Link
                                        :href="createUrl1stSem"
                                        class="inline-flex items-center gap-1 rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100 transition-colors"
                                    >
                                        <Plus class="h-3 w-3" />
                                        Create 1st Sem Subject
                                    </Link>
                                    <Link
                                        :href="createUrl2ndSem"
                                        class="inline-flex items-center gap-1 rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100 transition-colors"
                                    >
                                        <Plus class="h-3 w-3" />
                                        Create 2nd Sem Subject
                                    </Link>
                                </div>
                            </template>

                            <!-- Non-Summer: single link with semester pre-filled -->
                            <template v-else>
                                <Link
                                    :href="createSubjectUrl"
                                    class="mt-2 inline-flex items-center gap-1.5 rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 hover:bg-amber-100 transition-colors"
                                >
                                    <Plus class="h-3 w-3" />
                                    Create subjects for this preset
                                </Link>
                            </template>
                        </template>
                    </div>

                    <!-- Subject rows -->
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 border-b text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="text-left px-4 py-2.5">Code</th>
                                    <th class="text-left px-4 py-2.5">Subject Name</th>
                                    <!--
                                        Semester column only shown for Summer presets.
                                        For regular presets it's constant and adds visual noise.
                                    -->
                                    <th v-if="isSummerPreset" class="text-center px-3 py-2.5">Sem</th>
                                    <th class="text-center px-3 py-2.5">LEC</th>
                                    <th class="text-center px-3 py-2.5">LAB</th>
                                    <th class="text-right px-4 py-2.5">Tuition</th>
                                    <th class="text-right px-4 py-2.5">Lab Fee</th>
                                    <th class="text-center px-3 py-2.5">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr
                                    v-for="ps in linkedSubjects"
                                    :key="ps.id"
                                    :class="[
                                        'transition-colors',
                                        ps.fees_are_stale ? 'bg-amber-50/60 hover:bg-amber-50' : 'hover:bg-gray-50',
                                    ]"
                                >
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono font-medium text-gray-900">{{ ps.code }}</span>
                                            <span
                                                v-if="ps.is_nstp"
                                                class="inline-flex items-center text-xs font-medium text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded"
                                            >NSTP</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-800">{{ ps.name }}</td>

                                    <!-- Semester column — Summer presets only -->
                                    <td v-if="isSummerPreset" class="px-3 py-3 text-center">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                            :class="ps.semester === '1st Sem'
                                                ? 'bg-blue-100 text-blue-700'
                                                : 'bg-purple-100 text-purple-700'"
                                        >
                                            {{ ps.semester ?? '—' }}
                                        </span>
                                    </td>

                                    <td class="px-3 py-3 text-center font-mono">{{ ps.lec_units }}</td>
                                    <td class="px-3 py-3 text-center font-mono">{{ ps.lab_units }}</td>
                                    <td class="px-4 py-3 text-right font-mono">
                                        <div class="flex flex-col items-end gap-0.5">
                                            <span :class="ps.fees_are_stale ? 'text-amber-700' : 'text-gray-900'">
                                                {{ formatCurrency(ps.tuition_fee) }}
                                            </span>
                                            <span
                                                v-if="ps.fees_are_stale"
                                                class="text-xs text-green-600"
                                                :title="`Current rate: ${formatCurrency(ps.current_tuition)}`"
                                            >→ {{ formatCurrency(ps.current_tuition) }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono">
                                        <div class="flex flex-col items-end gap-0.5">
                                            <span :class="ps.fees_are_stale ? 'text-amber-700' : 'text-gray-900'">
                                                {{ ps.lab_fee > 0 ? formatCurrency(ps.lab_fee) : '—' }}
                                            </span>
                                            <span
                                                v-if="ps.fees_are_stale && ps.lab_fee !== ps.current_lab_fee"
                                                class="text-xs text-green-600"
                                            >→ {{ ps.current_lab_fee > 0 ? formatCurrency(ps.current_lab_fee) : '—' }}</span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 text-center">
                                        <button
                                            :disabled="removingId === ps.id"
                                            class="inline-flex items-center justify-center h-8 w-8 rounded-md border border-transparent text-red-600 hover:bg-red-50 hover:border-red-200 transition-colors disabled:opacity-40"
                                            title="Remove from preset"
                                            @click="removeSubject(ps)"
                                        >
                                            <Loader2 v-if="removingId === ps.id" class="h-4 w-4 animate-spin" />
                                            <Trash2 v-else class="h-4 w-4" />
                                        </button>
                                    </td>
                                </tr>
                            </tbody>

                            <tfoot v-if="linkedSubjects.length > 0" class="border-t-2 bg-gray-50 text-sm">
                                <tr class="text-gray-700">
                                    <td :colspan="isSummerPreset ? 5 : 4" class="px-4 py-2.5 font-medium text-gray-600">
                                        Subject Subtotal
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono font-semibold text-gray-800">
                                        {{ formatCurrency(totalTuition) }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono font-semibold text-gray-800">
                                        {{ formatCurrency(totalLab) }}
                                    </td>
                                    <td></td>
                                </tr>
                                <tr class="text-gray-500 border-t border-gray-100">
                                    <td :colspan="isSummerPreset ? 5 : 4" class="px-4 py-1.5 text-xs italic text-gray-400">
                                        + Entrepreneurship / Lab Activation Fee
                                        <span class="text-gray-300 ml-1">(flat, billed at assessment level)</span>
                                    </td>
                                    <td class="px-4 py-1.5 text-right font-mono text-xs text-gray-400">—</td>
                                    <td class="px-4 py-1.5 text-right font-mono text-xs text-gray-500">
                                        + {{ formatCurrency(entrepFee) }}
                                    </td>
                                    <td></td>
                                </tr>
                                <tr class="border-t-2 border-gray-300 bg-gray-100">
                                    <td :colspan="isSummerPreset ? 5 : 4" class="px-4 py-3 font-bold text-gray-800 text-sm">
                                        = Totals
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono font-bold text-gray-900">
                                        {{ formatCurrency(totalTuition) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono font-bold text-gray-900">
                                        {{ formatCurrency(totalLabWithEntrep) }}
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <!-- Billing rates panel -->
            <div class="rounded-lg border border-gray-200 overflow-hidden">
                <button
                    type="button"
                    class="w-full flex items-center justify-between px-4 py-3 bg-gray-50 hover:bg-gray-100 transition-colors text-left"
                    @click="ratesExpanded = !ratesExpanded"
                >
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 flex items-center gap-1.5">
                        <Info class="h-3.5 w-3.5" />
                        Current Billing Rates
                    </span>
                    <span class="text-xs text-gray-400">{{ ratesExpanded ? 'Hide ▲' : 'Show ▼' }}</span>
                </button>
                <div
                    v-if="ratesExpanded"
                    class="px-4 py-3 bg-white border-t border-gray-100 space-y-2 text-xs text-muted-foreground"
                >
                    <div class="flex justify-between">
                        <span>Per lecture unit:</span>
                        <span class="font-mono font-medium text-foreground">{{ formatCurrency(rates.tuition_per_unit) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Per lab subject:</span>
                        <span class="font-mono font-medium text-foreground">{{ formatCurrency(rates.lab_fee_per_subject) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Entrepreneurship / Lab Activation (flat):</span>
                        <span class="font-mono font-medium text-foreground">{{ formatCurrency(rates.entrepreneurship_fee) }}</span>
                    </div>
                    <p class="pt-1 text-[11px] opacity-60 border-t border-gray-100 mt-2">
                        All rates sourced from Fee Settings. "Sync Fees" updates stored subject fees when rates change.
                        Miscellaneous fees are added at the assessment level and are not shown here.
                    </p>
                </div>
            </div>

        </div>
    </AppLayout>
</template>