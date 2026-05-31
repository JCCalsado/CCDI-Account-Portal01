<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
    LayoutTemplate, BookOpen, Plus, Trash2, ToggleLeft,
    ToggleRight, Users, ChevronRight, AlertTriangle,
    CheckCircle2, Loader2, X,
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
    subject_count: number
    assessment_count: number
}

// ─── Props ────────────────────────────────────────────────────────────────────

const props = defineProps<{
    courses: string[]
    selectedCourse: string | null
    presets: Preset[]
}>()

// ─── Breadcrumbs ──────────────────────────────────────────────────────────────

const breadcrumbs = [
    { title: 'Dashboard',          href: route('accounting.dashboard') },
    { title: 'Curriculum Presets', href: route('accounting.curriculum-presets.index') },
]

// ─── Course Filter ────────────────────────────────────────────────────────────

function selectCourse(course: string | null) {
    router.get(
        route('accounting.curriculum-presets.index'),
        course ? { course } : {},
        { preserveState: true, replace: true },
    )
}

// ─── Grid Layout ──────────────────────────────────────────────────────────────

const YEAR_LEVELS = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year']
const SEMESTERS   = ['1st Sem', '2nd Sem', 'Summer']

const activeYearLevels = computed(() => {
    const present = new Set(props.presets.map(p => p.year_level))
    return YEAR_LEVELS.filter(yl => present.has(yl))
})

function presetAt(yearLevel: string, semester: string): Preset | null {
    return props.presets.find(p => p.year_level === yearLevel && p.semester === semester) ?? null
}

// ─── Create Preset Form ───────────────────────────────────────────────────────

const showCreateForm   = ref(false)
const createTargetSlot = ref<{ year_level: string; semester: string } | null>(null)
const createFormRef    = ref<InstanceType<typeof Card> | null>(null)

// Separate flag to track "enter new course" mode.
// Bug fix: using v-if="createForm.course === '__new__'" causes the input to
// disappear on the first keystroke because typing changes createForm.course
// away from '__new__', collapsing the v-if. The flag breaks that coupling.
const isNewCourse = ref(false)

const createForm = useForm({
    course:     props.selectedCourse ?? '',
    year_level: '',
    semester:   '',
})

function openCreateForm(yearLevel: string, semester: string) {
    createTargetSlot.value = { year_level: yearLevel, semester: semester }
    createForm.year_level  = yearLevel
    createForm.semester    = semester
    createForm.course      = props.selectedCourse ?? ''
    isNewCourse.value      = false
    showCreateForm.value   = true
    scrollToForm()
}

function openCreateFormFromHeader() {
    createTargetSlot.value = null
    createForm.year_level  = ''
    createForm.semester    = ''
    createForm.course      = props.selectedCourse ?? ''
    isNewCourse.value      = false
    showCreateForm.value   = true
    scrollToForm()
}

function scrollToForm() {
    nextTick(() => {
        // createFormRef is a Card component instance; access $el for the DOM node
        const el = (createFormRef.value as any)?.$el as HTMLElement | undefined
        el?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    })
}

function closeCreateForm() {
    showCreateForm.value   = false
    createTargetSlot.value = null
    isNewCourse.value      = false
    createForm.reset()
    createForm.clearErrors()
}

/**
 * Handle the course dropdown change.
 *
 * '__new__' is a sentinel value that triggers the text input.
 * We store it in `isNewCourse` and clear createForm.course so the
 * Input field starts empty and v-model works without the disappearing bug.
 */
function onCourseSelectChange(e: Event) {
    const val = (e.target as HTMLSelectElement).value
    if (val === '__new__') {
        isNewCourse.value  = true
        createForm.course  = ''
    } else {
        isNewCourse.value  = false
        createForm.course  = val
    }
}

function submitCreate() {
    createForm.post(route('accounting.curriculum-presets.store'), {
        onSuccess: () => closeCreateForm(),
    })
}

// ─── Toggle Active ────────────────────────────────────────────────────────────

function toggleActive(preset: Preset) {
    router.patch(
        route('accounting.curriculum-presets.update', preset.id),
        { is_active: !preset.is_active },
        { preserveScroll: true },
    )
}

// ─── Delete Preset ────────────────────────────────────────────────────────────

const deleteTarget = ref<Preset | null>(null)

function confirmDelete(preset: Preset) {
    deleteTarget.value = preset
}

function cancelDelete() {
    deleteTarget.value = null
}

function executeDelete() {
    if (!deleteTarget.value) return
    router.delete(
        route('accounting.curriculum-presets.destroy', deleteTarget.value.id),
        {
            onSuccess: () => { deleteTarget.value = null },
            onError:   () => { deleteTarget.value = null },
        },
    )
}

// ─── Manage Subjects ──────────────────────────────────────────────────────────
// Navigate directly to the subjects page — no longer routes through show()
// to avoid the unnecessary server-side redirect hop.

function manageSubjects(preset: Preset) {
    router.get(route('accounting.curriculum-presets.subjects.index', preset.id))
}
</script>

<template>
    <AppLayout>
        <div class="w-full p-6 space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Page header with Add Preset button -->
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div class="flex items-center gap-3">
                    <LayoutTemplate class="h-6 w-6 text-blue-600" />
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight">Curriculum Presets</h1>
                        <p class="text-sm text-muted-foreground mt-0.5">
                            Manage the subject lineup and billing aggregates for each
                            course × year level × semester combination.
                        </p>
                    </div>
                </div>

                <!-- Add Preset in header — always visible, always accessible -->
                <Button
                    v-if="!showCreateForm"
                    @click="openCreateFormFromHeader"
                >
                    <Plus class="h-4 w-4 mr-2" />
                    Add Preset
                </Button>
                <Button v-else variant="outline" @click="scrollToForm">
                    <Plus class="h-4 w-4 mr-2" />
                    Add Preset
                </Button>
            </div>

            <!-- Course selector -->
            <div class="space-y-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Filter by Course</p>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        @click="selectCourse(null)"
                        :class="[
                            'rounded-full border px-4 py-1.5 text-sm font-medium transition-colors',
                            !selectedCourse
                                ? 'border-blue-500 bg-blue-500 text-white shadow-sm'
                                : 'border-input bg-background text-muted-foreground hover:bg-muted',
                        ]"
                    >
                        All Courses
                    </button>
                    <button
                        v-for="c in courses"
                        :key="c"
                        type="button"
                        @click="selectCourse(c)"
                        :class="[
                            'rounded-full border px-4 py-1.5 text-sm font-medium transition-colors',
                            selectedCourse === c
                                ? 'border-blue-500 bg-blue-500 text-white shadow-sm'
                                : 'border-input bg-background text-muted-foreground hover:bg-muted',
                        ]"
                    >
                        {{ c }}
                    </button>
                </div>
            </div>

            <!-- No presets at all -->
            <div v-if="presets.length === 0 && !showCreateForm"
                 class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-200 py-16 text-center gap-4">
                <LayoutTemplate class="h-10 w-10 text-gray-300" />
                <div>
                    <p class="font-semibold text-gray-600">No curriculum presets yet</p>
                    <p class="text-sm text-muted-foreground mt-1">
                        {{ selectedCourse ? `No presets found for "${selectedCourse}".` : 'Create a preset for each course, year level and semester.' }}
                    </p>
                </div>
                <Button variant="outline" @click="openCreateFormFromHeader">
                    <Plus class="h-4 w-4 mr-2" />
                    Create First Preset
                </Button>
            </div>

            <!-- Grid: year levels × semesters -->
            <template v-else>
                <div
                    v-for="course in (selectedCourse ? [selectedCourse] : [...new Set(presets.map(p => p.course))])"
                    :key="course"
                    class="space-y-3"
                >
                    <!-- Course header -->
                    <div class="flex items-center gap-3">
                        <h2 class="text-base font-semibold text-gray-800">{{ course }}</h2>
                        <div class="h-px flex-1 bg-gray-200" />
                    </div>

                    <!-- Grid table -->
                    <div class="rounded-xl border border-gray-200 overflow-hidden">
                        <!-- Header row -->
                        <div class="grid bg-gray-50 border-b border-gray-200 text-xs font-semibold uppercase tracking-wide text-gray-500"
                             :style="`grid-template-columns: 160px repeat(${SEMESTERS.length}, 1fr)`">
                            <div class="px-4 py-3">Year Level</div>
                            <div v-for="sem in SEMESTERS" :key="sem" class="px-4 py-3 text-center border-l border-gray-200">
                                {{ sem }}
                            </div>
                        </div>

                        <!-- Data rows -->
                        <div
                            v-for="yl in YEAR_LEVELS"
                            :key="yl"
                            class="grid border-b border-gray-100 last:border-0"
                            :style="`grid-template-columns: 160px repeat(${SEMESTERS.length}, 1fr)`"
                        >
                            <!-- Year label -->
                            <div class="px-4 py-4 flex items-center">
                                <span class="text-sm font-semibold text-gray-700">{{ yl }}</span>
                            </div>

                            <!-- Semester cells -->
                            <div
                                v-for="sem in SEMESTERS"
                                :key="sem"
                                class="border-l border-gray-100 px-3 py-3"
                            >
                                <template v-if="presetAt(yl, sem) && presetAt(yl, sem)!.course === course">
                                    <!-- Populated cell -->
                                    <div
                                        :class="[
                                            'rounded-lg border p-3 space-y-2 transition-colors',
                                            presetAt(yl, sem)!.is_active
                                                ? 'bg-blue-50/60 border-blue-200'
                                                : 'bg-gray-50 border-gray-200 opacity-60',
                                        ]"
                                    >
                                        <!-- Badges row -->
                                        <div class="flex flex-wrap gap-1.5">
                                            <span class="inline-flex items-center gap-1 rounded-full bg-blue-100 text-blue-800 border border-blue-200 px-2 py-0.5 text-xs font-semibold">
                                                {{ presetAt(yl, sem)!.lec_units }} Lec
                                            </span>
                                            <span class="inline-flex items-center gap-1 rounded-full bg-orange-100 text-orange-800 border border-orange-200 px-2 py-0.5 text-xs font-semibold">
                                                {{ presetAt(yl, sem)!.lab_subject_count }} Lab
                                            </span>
                                            <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 text-gray-700 border border-gray-200 px-2 py-0.5 text-xs font-medium">
                                                <BookOpen class="h-3 w-3" />
                                                {{ presetAt(yl, sem)!.subject_count }} subj.
                                            </span>
                                        </div>

                                        <!-- Assessment guard badge -->
                                        <div
                                            v-if="presetAt(yl, sem)!.assessment_count > 0"
                                            class="inline-flex items-center gap-1 text-[10px] font-medium text-purple-700 bg-purple-50 border border-purple-200 rounded px-1.5 py-0.5"
                                        >
                                            <Users class="h-3 w-3" />
                                            {{ presetAt(yl, sem)!.assessment_count }} assessment{{ presetAt(yl, sem)!.assessment_count !== 1 ? 's' : '' }}
                                        </div>

                                        <!-- Inactive badge -->
                                        <div v-if="!presetAt(yl, sem)!.is_active"
                                             class="inline-flex items-center gap-1 text-[10px] font-semibold text-gray-500 bg-gray-100 border border-gray-200 rounded px-1.5 py-0.5">
                                            Inactive
                                        </div>

                                        <!-- Actions -->
                                        <div class="flex items-center gap-1.5 pt-1">
                                            <button
                                                type="button"
                                                class="flex-1 inline-flex items-center justify-center gap-1 rounded-md bg-blue-600 text-white text-xs font-medium px-2.5 py-1.5 hover:bg-blue-700 transition-colors"
                                                @click="manageSubjects(presetAt(yl, sem)!)"
                                            >
                                                Manage Subjects
                                                <ChevronRight class="h-3 w-3" />
                                            </button>

                                            <!-- Toggle active -->
                                            <button
                                                type="button"
                                                :title="presetAt(yl, sem)!.is_active ? 'Deactivate preset' : 'Activate preset'"
                                                class="inline-flex items-center justify-center h-7 w-7 rounded-md border border-input bg-background text-muted-foreground hover:bg-muted transition-colors"
                                                @click="toggleActive(presetAt(yl, sem)!)"
                                            >
                                                <ToggleRight v-if="presetAt(yl, sem)!.is_active" class="h-4 w-4 text-green-600" />
                                                <ToggleLeft v-else class="h-4 w-4 text-gray-400" />
                                            </button>

                                            <!-- Delete -->
                                            <button
                                                type="button"
                                                title="Delete preset"
                                                class="inline-flex items-center justify-center h-7 w-7 rounded-md border border-red-200 bg-red-50 text-red-400 hover:text-red-600 hover:bg-red-100 transition-colors"
                                                @click="confirmDelete(presetAt(yl, sem)!)"
                                            >
                                                <Trash2 class="h-3.5 w-3.5" />
                                            </button>
                                        </div>
                                    </div>
                                </template>

                                <template v-else>
                                    <!-- Empty slot — create placeholder -->
                                    <button
                                        type="button"
                                        class="w-full h-full min-h-[80px] rounded-lg border-2 border-dashed border-gray-200 flex flex-col items-center justify-center gap-1.5 text-gray-400 hover:border-blue-300 hover:text-blue-500 hover:bg-blue-50/40 transition-colors group"
                                        @click="openCreateForm(yl, sem)"
                                    >
                                        <Plus class="h-5 w-5 group-hover:scale-110 transition-transform" />
                                        <span class="text-xs font-medium">Create Preset</span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Inline create form — ref for scroll-into-view -->
            <Card v-if="showCreateForm" ref="createFormRef" class="border-blue-200 bg-blue-50/40">
                <CardHeader class="pb-3">
                    <div class="flex items-center justify-between">
                        <CardTitle class="text-base flex items-center gap-2">
                            <Plus class="h-4 w-4 text-blue-600" />
                            New Curriculum Preset
                        </CardTitle>
                        <button type="button" class="text-muted-foreground hover:text-foreground" @click="closeCreateForm">
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <!-- Course selector + new-course input -->
                        <div class="space-y-1.5">
                            <Label>Course</Label>
                            <select
                                :value="isNewCourse ? '__new__' : createForm.course"
                                @change="onCourseSelectChange"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring"
                            >
                                <option value="">Select a course…</option>
                                <option v-for="c in courses" :key="c" :value="c">{{ c }}</option>
                                <option value="__new__">+ Enter new course name</option>
                            </select>
                            <!-- Free-text input for new course — uses isNewCourse flag, not course value, so it persists while typing -->
                            <Input
                                v-if="isNewCourse"
                                v-model="createForm.course"
                                placeholder="e.g. BS Computer Engineering"
                                class="mt-1"
                                autofocus
                            />
                            <p v-if="createForm.errors.course" class="text-xs text-destructive">{{ createForm.errors.course }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <Label>Year Level</Label>
                            <select
                                v-model="createForm.year_level"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring"
                            >
                                <option value="">Select year level…</option>
                                <option v-for="yl in YEAR_LEVELS" :key="yl" :value="yl">{{ yl }}</option>
                            </select>
                            <p v-if="createForm.errors.year_level" class="text-xs text-destructive">{{ createForm.errors.year_level }}</p>
                        </div>

                        <div class="space-y-1.5">
                            <Label>Semester</Label>
                            <select
                                v-model="createForm.semester"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring"
                            >
                                <option value="">Select semester…</option>
                                <option v-for="sem in SEMESTERS" :key="sem" :value="sem">{{ sem }}</option>
                            </select>
                            <p v-if="createForm.errors.semester" class="text-xs text-destructive">{{ createForm.errors.semester }}</p>
                        </div>
                    </div>

                    <p v-if="createForm.errors.preset" class="mt-2 text-sm text-destructive">{{ createForm.errors.preset }}</p>

                    <p class="mt-3 text-xs text-muted-foreground">
                        After creating, you'll land directly on the subject management page.
                        Unit aggregates are computed automatically as you add subjects.
                    </p>

                    <div class="flex justify-end gap-2 mt-4">
                        <Button variant="outline" size="sm" @click="closeCreateForm">Cancel</Button>
                        <Button
                            size="sm"
                            :disabled="createForm.processing || !createForm.course.trim() || !createForm.year_level || !createForm.semester"
                            @click="submitCreate"
                        >
                            <Loader2 v-if="createForm.processing" class="h-4 w-4 mr-1.5 animate-spin" />
                            <CheckCircle2 v-else class="h-4 w-4 mr-1.5" />
                            Create Preset
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- ─── Delete Confirmation Modal ───────────────────────────────────── -->
        <Teleport to="body">
            <div v-if="deleteTarget" class="fixed inset-0 z-50 flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/50" @click="cancelDelete" />
                <div class="relative z-10 w-full max-w-md rounded-lg border bg-white shadow-xl p-6 space-y-4">
                    <div class="flex items-start gap-3">
                        <div class="shrink-0 flex h-10 w-10 items-center justify-center rounded-full bg-red-100">
                            <AlertTriangle class="h-5 w-5 text-red-600" />
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Delete Preset?</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                <strong>{{ deleteTarget.course }}</strong> —
                                {{ deleteTarget.year_level }} — {{ deleteTarget.semester }}
                            </p>
                            <p v-if="deleteTarget.subject_count > 0"
                               class="mt-2 text-sm text-red-700 bg-red-50 border border-red-200 rounded px-3 py-2">
                                This preset has <strong>{{ deleteTarget.subject_count }} linked subjects</strong>.
                                Remove all subjects first, then delete.
                            </p>
                            <p v-else class="mt-2 text-sm text-gray-500">
                                This preset has no subjects. It can be safely deleted.
                            </p>
                            <p v-if="deleteTarget.assessment_count > 0"
                               class="mt-2 text-xs text-purple-700 flex items-center gap-1.5">
                                <Users class="h-3.5 w-3.5" />
                                {{ deleteTarget.assessment_count }} student assessment{{ deleteTarget.assessment_count !== 1 ? 's' : '' }}
                                used this preset. Assessments are unaffected (snapshot data).
                            </p>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 pt-2">
                        <Button variant="outline" size="sm" @click="cancelDelete">Cancel</Button>
                        <Button
                            size="sm"
                            :disabled="deleteTarget.subject_count > 0"
                            class="bg-red-600 hover:bg-red-700 text-white disabled:opacity-40"
                            @click="executeDelete"
                        >
                            Delete
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>