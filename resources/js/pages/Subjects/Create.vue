<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { Link, useForm } from '@inertiajs/vue3'

// ─── Canonical constants ──────────────────────────────────────────────────────
//
// These mirror SubjectController::YEAR_LEVELS and SEMESTERS exactly.
// They are NOT sourced from props — they are institution-defined constants.
//
// IMPORTANT: 'Summer' is intentionally absent from SEMESTERS.
// Summer is a preset classification, not a subject classification.
// No subject should ever be tagged 'Summer'. See SubjectController docblock.

const YEAR_LEVELS = [
    '1st Year',
    '2nd Year',
    '3rd Year',
    '4th Year',
    '5th Year',
] as const

const SEMESTERS = [
    '1st Sem',
    '2nd Sem',
] as const

// ─── Props ───────────────────────────────────────────────────────────────────

interface DefaultValues {
    course: string
    year_level: string
    semester: string
}

const props = defineProps<{
    courses: string[]
    canEditNstp: boolean
    // Passed when arriving via the context link from a Preset Subjects empty state:
    //   /accounting/subjects/create?course=BS+IT&year_level=4th+Year&semester=1st+Sem
    // The controller reads the query string and passes these here so the user
    // only has to fill in code, name, and units. Empty strings when direct visit.
    defaultValues: DefaultValues
}>()

// ─── Breadcrumbs ─────────────────────────────────────────────────────────────

const breadcrumbs = [
    { title: 'Dashboard', href: route('accounting.dashboard') },
    { title: 'Subjects',  href: route('accounting.subjects.index') },
    { title: 'Add Subject' },
]

// ─── Form ────────────────────────────────────────────────────────────────────
// defaultValues pre-fill the classification fields when arriving from context link.
// code, name, lec_units, lab_units always start blank/at defaults.

const form = useForm({
    code:       '',
    name:       '',
    lec_units:  3,
    lab_units:  0,
    year_level: props.defaultValues?.year_level ?? '',
    semester:   props.defaultValues?.semester   ?? '',
    course:     props.defaultValues?.course     ?? '',
    is_nstp:    false,
})

function submit() {
    form.post(route('accounting.subjects.store'))
}
</script>

<template>
    <AppLayout title="Add Subject">
        <div class="w-full p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <div class="mx-auto max-w-2xl mt-6">
                <h1 class="text-2xl font-bold mb-6">Add Subject</h1>

                <!--
                    Context banner — shown when arriving from a preset's empty state.
                    Informs the user why the form is pre-filled and what to do next.
                -->
                <div
                    v-if="defaultValues?.course"
                    class="mb-5 flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800"
                >
                    <svg class="h-4 w-4 mt-0.5 shrink-0 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div>
                        <p class="font-semibold">Creating a subject for a preset</p>
                        <p class="mt-0.5 text-blue-700">
                            Course, year level, and semester are pre-filled from the preset.
                            Fill in the subject code, name, and units, then save.
                            After saving you'll be taken to the subjects list —
                            return to the preset to link this subject.
                        </p>
                    </div>
                </div>

                <form @submit.prevent="submit" class="space-y-5 rounded-xl border bg-card p-6 shadow-sm">

                    <!-- Code + Name -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">
                                Subject Code <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.code"
                                type="text"
                                placeholder="e.g. IT-OJT1"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                                required
                                autofocus
                            />
                            <p v-if="form.errors.code" class="mt-1 text-xs text-red-500">{{ form.errors.code }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">
                                Subject Name <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.name"
                                type="text"
                                placeholder="e.g. On-the-Job Training 1"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                                required
                            />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-500">{{ form.errors.name }}</p>
                        </div>
                    </div>

                    <!-- LEC + LAB units -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">
                                LEC Units <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model.number="form.lec_units"
                                type="number" min="0" max="10" step="0.5"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                                required
                            />
                            <p class="mt-1 text-xs text-muted-foreground">Use 1.5 for NSTP subjects</p>
                            <p v-if="form.errors.lec_units" class="mt-1 text-xs text-red-500">{{ form.errors.lec_units }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">
                                LAB Units <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model.number="form.lab_units"
                                type="number" min="0" max="5"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                                required
                            />
                            <p v-if="form.errors.lab_units" class="mt-1 text-xs text-red-500">{{ form.errors.lab_units }}</p>
                        </div>
                    </div>

                    <!-- Year Level + Semester -->
                    <!--
                        Options are HARDCODED — not from props or server data.
                        year_level and semester are canonical institution constants.
                        'Summer' is intentionally absent from semester options —
                        it is a preset type, not a subject classification.
                    -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">
                                Year Level <span class="text-red-500">*</span>
                            </label>
                            <select
                                v-model="form.year_level"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                                required
                            >
                                <option value="">Select year level</option>
                                <option v-for="y in YEAR_LEVELS" :key="y" :value="y">{{ y }}</option>
                            </select>
                            <p v-if="form.errors.year_level" class="mt-1 text-xs text-red-500">{{ form.errors.year_level }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">
                                Semester <span class="text-red-500">*</span>
                            </label>
                            <select
                                v-model="form.semester"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                                required
                            >
                                <option value="">Select semester</option>
                                <option v-for="s in SEMESTERS" :key="s" :value="s">{{ s }}</option>
                            </select>
                            <p v-if="form.errors.semester" class="mt-1 text-xs text-red-500">{{ form.errors.semester }}</p>
                        </div>
                    </div>

                    <!-- Course -->
                    <div>
                        <label class="block text-sm font-medium mb-1">
                            Course <span class="text-red-500">*</span>
                        </label>
                        <select
                            v-model="form.course"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-ring"
                            required
                        >
                            <option value="">Select course</option>
                            <option v-for="c in courses" :key="c" :value="c">{{ c }}</option>
                        </select>
                        <p v-if="form.errors.course" class="mt-1 text-xs text-red-500">{{ form.errors.course }}</p>
                    </div>

                    <!-- NSTP flag — admin only -->
                    <div
                        v-if="canEditNstp"
                        class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3"
                    >
                        <input
                            id="is_nstp"
                            v-model="form.is_nstp"
                            type="checkbox"
                            class="mt-0.5 h-4 w-4 rounded border-amber-400 text-amber-600 focus:ring-amber-500"
                        />
                        <div>
                            <label for="is_nstp" class="text-sm font-medium text-amber-900 cursor-pointer">
                                NSTP Subject
                            </label>
                            <p class="text-xs text-amber-700 mt-0.5">
                                NSTP subjects are billed at lec_units × tuition rate with no lab fee.
                                If the code contains "NSTP", this flag is auto-set on save even without checking here.
                            </p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center justify-between border-t pt-4">
                        <Link
                            :href="route('accounting.subjects.index')"
                            class="rounded-md border border-input px-4 py-2 text-sm hover:bg-muted"
                        >
                            Cancel
                        </Link>
                        <button
                            type="submit"
                            class="rounded-md bg-primary px-5 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                            :disabled="form.processing"
                        >
                            {{ form.processing ? 'Creating…' : 'Create Subject' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>