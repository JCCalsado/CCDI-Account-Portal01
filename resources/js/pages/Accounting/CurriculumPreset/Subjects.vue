<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useDataFormatting } from '@/composables/useDataFormatting'
import {
    AlertTriangle, BookOpen, Check, Info,
    Loader2, Plus, RefreshCw, Sparkles, Trash2,
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
    lec_units: number
    lab_units: number
    is_nstp: boolean
}

interface Rates {
    tuition_per_unit: number
    lab_fee_per_subject: number
    entrep_fee: number
}

// ─── Props ────────────────────────────────────────────────────────────────────

const props = defineProps<{
    preset: Preset
    linkedSubjects: LinkedSubject[]
    availableSubjects: AvailableSubject[]
    rates: Rates
    justCreated: boolean
}>()

const { formatCurrency } = useDataFormatting()

// ─── Breadcrumbs ──────────────────────────────────────────────────────────────

const breadcrumbs = [
    { title: 'Dashboard',          href: route('accounting.dashboard') },
    { title: 'Curriculum Presets', href: route('accounting.curriculum-presets.index') },
    {
        title: `${props.preset.course} — ${props.preset.year_level} — ${props.preset.semester}`,
        href: '#',
    },
]

// ─── Stale fee detection ──────────────────────────────────────────────────────

const hasStaleSubjects = computed(() =>
    props.linkedSubjects.some((s) => s.fees_are_stale)
)

// ─── Add subject form ─────────────────────────────────────────────────────────

const showAddForm   = ref(false)
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
        route('accounting.curriculum-presets.subjects.store', props.preset.id),
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
        route('accounting.curriculum-presets.subjects.destroy', [props.preset.id, ps.id]),
        {
            onFinish: () => { removingId.value = null },
        }
    )
}

// ─── Sync fees ────────────────────────────────────────────────────────────────

const syncing = ref(false)

function syncFees() {
    if (syncing.value) return
    syncing.value = true
    router.post(
        route('accounting.curriculum-presets.subjects.sync', props.preset.id),
        {},
        { onFinish: () => { syncing.value = false } }
    )
}

// ─── Computed totals ──────────────────────────────────────────────────────────

const totalTuition = computed(() =>
    props.linkedSubjects.reduce((s, r) => s + r.tuition_fee, 0)
)

const totalLab = computed(() =>
    props.linkedSubjects.reduce((s, r) => s + r.lab_fee, 0)
)

const hasLabSubjects = computed(() => totalLab.value > 0)

const effectiveLabTotal = computed(() =>
    hasLabSubjects.value ? totalLab.value + props.rates.entrep_fee : 0
)

const billableSubjects = computed(() =>
    props.linkedSubjects.filter((s) => !s.is_nstp)
)
</script>

<template>
    <AppLayout>
        <div class="w-full p-6 space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Just-created banner -->
            <div
                v-if="justCreated"
                class="flex items-start gap-3 rounded-lg border border-blue-300 bg-blue-50 px-4 py-3 text-sm text-blue-900"
            >
                <Sparkles class="h-5 w-5 shrink-0 text-blue-500 mt-0.5" />
                <div>
                    <p class="font-semibold">Preset created — add your subjects to get started.</p>
                    <p class="text-blue-700 text-xs mt-0.5">
                        Use the <strong>Add Subject</strong> button below to populate this preset.
                        Unit aggregates (LEC, LAB) are computed automatically as subjects are added.
                    </p>
                </div>
            </div>

            <!-- Header -->
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div class="flex items-center gap-3">
                    <BookOpen class="h-6 w-6 text-blue-600" />
                    <div>
                        <h1 class="text-2xl font-bold">Preset Subjects</h1>
                        <p class="text-sm text-muted-foreground">
                            {{ preset.course }} &middot; {{ preset.year_level }} &middot; {{ preset.semester }}
                        </p>
                    </div>
                </div>
                <div class="flex gap-2 flex-wrap">
                    <Button
                        variant="outline"
                        size="sm"
                        @click="router.visit(route('accounting.curriculum-presets.index'))"
                    >
                        ← Back to Curriculum Presets
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
                    <Button size="sm" @click="showAddForm = !showAddForm" :disabled="availableSubjects.length === 0">
                        <Plus class="h-4 w-4 mr-1.5" />
                        Add Subject
                    </Button>
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
                        This does not affect existing student assessments — those are immutable.
                    </p>
                </div>
            </div>

            <!-- Add subject form -->
            <Card v-if="showAddForm" class="border-blue-200 bg-blue-50/30">
                <CardHeader class="pb-3">
                    <CardTitle class="text-base text-blue-800 flex items-center gap-2">
                        <Plus class="h-4 w-4" /> Add Subject to Preset
                    </CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div v-if="availableSubjects.length === 0" class="text-sm text-muted-foreground">
                        All subjects for this course/year/semester are already linked.
                    </div>
                    <template v-else>
                        <div class="space-y-1.5">
                            <label class="text-sm font-medium">Select Subject</label>
                            <select
                                v-model="selectedSubId"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                            >
                                <option :value="null" disabled>— choose a subject —</option>
                                <option
                                    v-for="s in availableSubjects"
                                    :key="s.id"
                                    :value="s.id"
                                >
                                    {{ s.code }} — {{ s.name }}
                                    ({{ s.lec_units }} LEC{{ s.lab_units > 0 ? ` + ${s.lab_units} LAB` : '' }})
                                    {{ s.is_nstp ? '· NSTP' : '' }}
                                </option>
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
                    </template>
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
                    <div v-if="linkedSubjects.length === 0" class="text-center py-10 text-muted-foreground text-sm">
                        <BookOpen class="h-8 w-8 mx-auto mb-3 opacity-30" />
                        <p>No subjects linked yet.</p>
                        <p class="text-xs mt-1">Click "Add Subject" to populate this preset.</p>
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 border-b text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="text-left px-4 py-2.5">Code</th>
                                    <th class="text-left px-4 py-2.5">Subject Name</th>
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
                                    <td class="px-3 py-3 text-center font-mono">{{ ps.lec_units }}</td>
                                    <td class="px-3 py-3 text-center font-mono">{{ ps.lab_units }}</td>

                                    <!-- Tuition with stale indicator -->
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

                                    <!-- Lab Fee with stale indicator -->
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

                            <!-- ── tfoot: Entrep Fee Breakdown ─────────────────────── -->
                            <tfoot class="border-t-2 bg-gray-50">

                                <!-- Row 1: Subject subtotals (always shown) -->
                                <tr>
                                    <td colspan="4" class="px-4 py-2.5 text-xs font-medium text-gray-500 uppercase tracking-wide">
                                        Subject Subtotals
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono font-semibold text-gray-900">
                                        {{ formatCurrency(totalTuition) }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono font-semibold text-gray-900">
                                        {{ hasLabSubjects ? formatCurrency(totalLab) : '—' }}
                                    </td>
                                    <td></td>
                                </tr>

                                <!-- Row 2: Entrepreneurship fee (only when lab subjects exist) -->
                                <tr v-if="hasLabSubjects" class="border-t border-gray-200">
                                    <td colspan="4" class="px-4 py-2 text-xs text-gray-500 italic">
                                        + Entrepreneurship Fee (flat, once per semester)
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono text-gray-400 text-xs">—</td>
                                    <td class="px-4 py-2 text-right font-mono text-amber-700 font-semibold">
                                        + {{ formatCurrency(rates.entrep_fee) }}
                                    </td>
                                    <td></td>
                                </tr>

                                <!-- Row 3: Effective billing total (only when lab subjects exist) -->
                                <tr v-if="hasLabSubjects" class="border-t-2 border-blue-200 bg-blue-50/50">
                                    <td colspan="4" class="px-4 py-3 text-sm font-bold text-gray-800">
                                        Effective Billing Total
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono font-bold text-blue-700 text-base">
                                        {{ formatCurrency(totalTuition) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono font-bold text-blue-700 text-base">
                                        = {{ formatCurrency(effectiveLabTotal) }}
                                    </td>
                                    <td></td>
                                </tr>

                                <!-- Simple totals row (only when NO lab subjects) -->
                                <tr v-if="!hasLabSubjects">
                                    <td colspan="4" class="px-4 py-3 text-sm font-semibold text-gray-700">Totals</td>
                                    <td class="px-4 py-3 text-right font-mono font-semibold text-blue-700">
                                        {{ formatCurrency(totalTuition) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono text-gray-400">—</td>
                                    <td></td>
                                </tr>

                            </tfoot>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <!-- Slim rate reference strip -->
            <div class="flex flex-wrap items-center gap-x-5 gap-y-1.5 rounded-lg border border-border bg-muted/40 px-4 py-2.5 text-xs text-muted-foreground">
                <div class="flex items-center gap-1.5">
                    <Info class="h-3.5 w-3.5 shrink-0 text-gray-400" />
                    <span class="font-medium text-foreground">Current Billing Rates</span>
                </div>
                <span>
                    Tuition:
                    <strong class="font-mono text-foreground">{{ formatCurrency(rates.tuition_per_unit) }}</strong>
                    / unit
                </span>
                <span>
                    Lab:
                    <strong class="font-mono text-foreground">{{ formatCurrency(rates.lab_fee_per_subject) }}</strong>
                    / subject
                </span>
                <span>
                    Entrep:
                    <strong class="font-mono text-foreground">{{ formatCurrency(rates.entrep_fee) }}</strong>
                    (flat, if lab subjects)
                </span>
                <span class="opacity-60">
                    Use "Sync Fees to Current Rates" to update stored fees after a rate change.
                </span>
            </div>
        </div>
    </AppLayout>
</template>