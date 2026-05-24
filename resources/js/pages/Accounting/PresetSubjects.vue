<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useDataFormatting } from '@/composables/useDataFormatting'
import {
    AlertTriangle, BookOpen, Check, FlaskConical,
    Info, Loader2, Plus, RefreshCw, Trash2,
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
    has_nstp: boolean
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
    is_pathfit: boolean
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
}

// ─── Props ────────────────────────────────────────────────────────────────────

const props = defineProps<{
    preset: Preset
    linkedSubjects: LinkedSubject[]
    availableSubjects: AvailableSubject[]
    rates: Rates
    backUrl: string
}>()

const { formatCurrency } = useDataFormatting()

// ─── Breadcrumbs ──────────────────────────────────────────────────────────────

const breadcrumbs = [
    { title: 'Dashboard',    href: route('accounting.dashboard') },
    { title: 'Fee Settings', href: route('accounting.fee-settings.index') },
    { title: `${props.preset.course} — ${props.preset.year_level} — ${props.preset.semester}`, href: '#' },
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

// Preview the fee that would be saved for the selected subject
const addPreviewFee = computed(() => {
    const s = selectedAvailableSubject.value
    if (!s) return null
    if (s.is_nstp) {
        const tuition = 1.5 * props.rates.tuition_per_unit
        return { tuition_fee: tuition, lab_fee: 0, total_fee: tuition }
    }
    const tuition = s.lec_units * props.rates.tuition_per_unit
    const lab     = s.lab_units > 0 ? props.rates.lab_fee_per_subject : 0
    return { tuition_fee: tuition, lab_fee: lab, total_fee: tuition + lab }
})

function addSubject() {
    if (!selectedSubId.value || addSaving.value) return
    addSaving.value = true
    router.post(
        route('accounting.fee-settings.preset-subjects.store', props.preset.id),
        { subject_id: selectedSubId.value },
        {
            onFinish: () => {
                addSaving.value   = false
                selectedSubId.value = null
                showAddForm.value  = false
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
        route('accounting.fee-settings.preset-subjects.destroy', [props.preset.id, ps.id]),
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
        route('accounting.fee-settings.preset-subjects.sync', props.preset.id),
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
const grandTotal = computed(() =>
    props.linkedSubjects.reduce((s, r) => s + r.total_fee, 0)
)
const billableSubjects = computed(() =>
    props.linkedSubjects.filter((s) => !s.is_nstp && !s.is_pathfit)
)
</script>

<template>
    <AppLayout>
        <div class="w-full p-6 space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

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
                    <Button variant="outline" size="sm" @click="router.visit(backUrl)">
                        ← Back to Fee Settings
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
                                <span>Tuition ({{ selectedAvailableSubject?.is_nstp ? '1.5 NSTP units' : `${selectedAvailableSubject?.lec_units} units` }} × {{ formatCurrency(rates.tuition_per_unit) }})</span>
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
                                    <th class="text-right px-4 py-2.5">Subject Total</th>
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
                                            <span
                                                v-if="ps.is_pathfit"
                                                class="inline-flex items-center text-xs font-medium text-purple-700 bg-purple-100 px-1.5 py-0.5 rounded"
                                            >PE</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-gray-800">{{ ps.name }}</td>
                                    <td class="px-3 py-3 text-center font-mono">
                                        {{ ps.is_nstp ? '1.5*' : ps.lec_units }}
                                    </td>
                                    <td class="px-3 py-3 text-center font-mono">{{ ps.lab_units }}</td>

                                    <!-- Stored fees with stale indicator -->
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
                                    <td class="px-4 py-3 text-right font-mono font-semibold">
                                        <div class="flex flex-col items-end gap-0.5">
                                            <span :class="ps.fees_are_stale ? 'text-amber-800' : 'text-gray-900'">
                                                {{ formatCurrency(ps.total_fee) }}
                                            </span>
                                            <span
                                                v-if="ps.fees_are_stale"
                                                class="text-xs text-green-700 font-medium"
                                            >→ {{ formatCurrency(ps.current_total_fee) }}</span>
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
                            <tfoot class="border-t-2 bg-gray-50">
                                <tr>
                                    <td colspan="4" class="px-4 py-3 text-sm font-semibold text-gray-700">Totals</td>
                                    <td class="px-4 py-3 text-right font-mono font-semibold text-gray-900">
                                        {{ formatCurrency(totalTuition) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono font-semibold text-gray-900">
                                        {{ formatCurrency(totalLab) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono font-bold text-blue-700 text-base">
                                        {{ formatCurrency(grandTotal) }}
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <!-- Rate reference + NSTP footnote -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <Card class="bg-muted/50">
                    <CardContent class="pt-4 space-y-1.5 text-xs text-muted-foreground">
                        <p class="font-semibold text-foreground text-sm mb-2">Current Billing Rates</p>
                        <div class="flex justify-between">
                            <span>Per lecture unit:</span>
                            <span class="font-mono">{{ formatCurrency(rates.tuition_per_unit) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Per lab subject:</span>
                            <span class="font-mono">{{ formatCurrency(rates.lab_fee_per_subject) }}</span>
                        </div>
                        <p class="pt-1 text-xs opacity-70">Rates sourced from Fee Settings. Use "Sync Fees" to update stored subject fees.</p>
                    </CardContent>
                </Card>

                <Card class="border-amber-200 bg-amber-50/30">
                    <CardContent class="pt-4 text-xs text-amber-800 space-y-1.5">
                        <div class="flex items-start gap-2">
                            <Info class="h-4 w-4 shrink-0 mt-0.5 text-amber-600" />
                            <div>
                                <p class="font-semibold">NSTP Billing Rule</p>
                                <p class="mt-0.5 text-amber-700">
                                    NSTP subjects are always billed at <strong>1.5 units</strong> (₱{{ (1.5 * rates.tuition_per_unit).toFixed(2) }})
                                    regardless of the lec_units stored in the subjects table. The table above marks NSTP as
                                    "1.5*" in the LEC column to reflect this.
                                </p>
                            </div>
                        </div>
                        <div class="flex items-start gap-2 mt-2">
                            <Info class="h-4 w-4 shrink-0 mt-0.5 text-amber-600" />
                            <div>
                                <p class="font-semibold">Subject totals here exclude misc & entrepreneurship fees</p>
                                <p class="mt-0.5 text-amber-700">
                                    The Grand Total above covers tuition + lab only.
                                    Miscellaneous (₱4,700) and Entrepreneurship (₱600) are fixed charges
                                    added at the assessment level, not per subject.
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>