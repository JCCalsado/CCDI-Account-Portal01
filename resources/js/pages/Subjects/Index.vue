<script setup lang="ts">
import { ref } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import Breadcrumbs from '@/components/Breadcrumbs.vue'
import { Input } from '@/components/ui/input'
import { Loader2, Save, X, Pencil, Plus, Trash2, ExternalLink } from 'lucide-vue-next'

// ─── Types ───────────────────────────────────────────────────────────────────

interface Subject {
    id: number
    code: string
    name: string
    lec_units: number
    lab_units: number
    year_level: string
    semester: string
    course: string
    is_active: boolean
    is_nstp: boolean
}

interface PaginatedSubjects {
    data: Subject[]
    links: Array<{ url: string | null; label: string; active: boolean }>
    meta: { current_page: number; last_page: number; total: number }
}

// ─── Props ───────────────────────────────────────────────────────────────────

const props = defineProps<{
    subjects: PaginatedSubjects
    filters: { course?: string; year_level?: string; semester?: string; search?: string }
    courses: string[]
    yearLevels: string[]
    semesters: string[]
    canEditNstp: boolean
    // Both accounting and admin can create subjects; admin-only for NSTP flag edits.
    canCreate: boolean
}>()

// ─── Breadcrumbs ─────────────────────────────────────────────────────────────

const breadcrumbs = [
    { title: 'Dashboard', href: route('accounting.dashboard') },
    { title: 'Subjects',  href: route('accounting.subjects.index') },
]

// ─── Filter state ────────────────────────────────────────────────────────────

const filters = ref({ ...props.filters })

function applyFilters() {
    router.get(route('accounting.subjects.index'), filters.value, { preserveScroll: true, replace: true })
}

function resetFilters() {
    filters.value = {}
    router.get(route('accounting.subjects.index'), {}, { preserveScroll: true, replace: true })
}

// ─── Inline edit state ───────────────────────────────────────────────────────

const editingId  = ref<number | null>(null)
const editLec    = ref(0)
const editLab    = ref(0)
const editSaving = ref(false)
const flashMsg   = ref('')
const flashType  = ref<'success' | 'error'>('success')

function startEdit(subject: Subject) {
    editingId.value = subject.id
    editLec.value   = subject.lec_units
    editLab.value   = subject.lab_units
}

function cancelEdit() {
    editingId.value = null
}

async function saveInline(subject: Subject) {
    editSaving.value = true

    try {
        const res = await fetch(route('accounting.subjects.inline-update', subject.id), {
            method:  'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':  (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
                'Accept':        'application/json',
            },
            body: JSON.stringify({
                lec_units: editLec.value,
                lab_units: editLab.value,
            }),
        })

        const data = await res.json()

        if (data.success) {
            subject.lec_units = data.lec_units
            subject.lab_units = data.lab_units
            editingId.value   = null
            flashMsg.value    = `${subject.name} updated.`
            flashType.value   = 'success'
        } else {
            flashMsg.value  = 'Update failed.'
            flashType.value = 'error'
        }
    } catch {
        flashMsg.value  = 'Network error — try again.'
        flashType.value = 'error'
    } finally {
        editSaving.value = false
        setTimeout(() => flashMsg.value = '', 3000)
    }
}

// ─── Destroy ─────────────────────────────────────────────────────────────────

function deactivate(subject: Subject) {
    if (! confirm(`Deactivate "${subject.code} — ${subject.name}"?\n\nThis hides it from new assessments but does not affect existing ones.`)) return
    router.delete(route('accounting.subjects.destroy', subject.id), { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Subjects">
        <div class="w-full p-6 space-y-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">Curriculum Subjects</h1>
                    <p class="text-sm text-muted-foreground mt-0.5">
                        {{ subjects.meta?.total ?? subjects.data.length }} subjects
                    </p>
                </div>
                <Link
                    v-if="canCreate"
                    :href="route('accounting.subjects.create')"
                    class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground shadow-sm hover:bg-primary/90 transition-colors"
                >
                    <Plus class="h-4 w-4" />
                    Add Subject
                </Link>
            </div>

            <!-- Flash -->
            <div v-if="flashMsg"
                :class="flashType === 'success'
                    ? 'bg-green-50 border-green-200 text-green-800'
                    : 'bg-red-50 border-red-200 text-red-800'"
                class="rounded-lg border px-4 py-3 text-sm font-medium">
                {{ flashMsg }}
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap gap-3 items-end">
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Course</label>
                    <select v-model="filters.course" @change="applyFilters"
                        class="h-9 rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-ring">
                        <option value="">All Courses</option>
                        <option v-for="c in courses" :key="c" :value="c">{{ c }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Year Level</label>
                    <select v-model="filters.year_level" @change="applyFilters"
                        class="h-9 rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-ring">
                        <option value="">All Years</option>
                        <option v-for="y in yearLevels" :key="y" :value="y">{{ y }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Semester</label>
                    <select v-model="filters.semester" @change="applyFilters"
                        class="h-9 rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-ring">
                        <option value="">All Semesters</option>
                        <option v-for="s in semesters" :key="s" :value="s">{{ s }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Search</label>
                    <Input v-model="filters.search" placeholder="Code or name…" class="h-9 w-52"
                        @keyup.enter="applyFilters" />
                </div>
                <button @click="resetFilters"
                    class="h-9 px-3 text-sm text-muted-foreground hover:text-foreground underline underline-offset-2">
                    Reset
                </button>
            </div>

            <!-- Table — overflow-x-auto prevents column crush on narrow viewports -->
            <div class="rounded-xl border overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm whitespace-nowrap">
                        <thead class="bg-muted/60 text-muted-foreground text-xs uppercase tracking-wide">
                            <tr>
                                <th class="text-left px-4 py-3 w-32">Code</th>
                                <th class="text-left px-4 py-3">Subject Name</th>
                                <th class="text-left px-4 py-3">Course</th>
                                <th class="text-center px-4 py-3 w-24">Year</th>
                                <th class="text-center px-4 py-3 w-24">Semester</th>
                                <th class="text-center px-4 py-3 w-16">LEC</th>
                                <th class="text-center px-4 py-3 w-16">LAB</th>
                                <!-- min-w keeps actions from collapsing -->
                                <th class="text-center px-4 py-3 min-w-[220px]">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="s in subjects.data" :key="s.id"
                                class="hover:bg-muted/40 transition-colors"
                                :class="s.is_nstp ? 'bg-amber-50/70' : 'bg-white'">

                                <!-- Code -->
                                <td class="px-4 py-3">
                                    <span class="font-mono text-xs font-semibold">{{ s.code }}</span>
                                </td>

                                <!-- Name + NSTP badge -->
                                <td class="px-4 py-3 max-w-xs">
                                    <span class="truncate block">{{ s.name }}</span>
                                    <span v-if="s.is_nstp"
                                        class="mt-0.5 inline-flex items-center text-[10px] font-semibold bg-amber-200 text-amber-800 px-1.5 py-0.5 rounded">
                                        NSTP
                                    </span>
                                </td>

                                <!-- Course -->
                                <td class="px-4 py-3 text-xs text-muted-foreground max-w-[240px] truncate">
                                    {{ s.course }}
                                </td>

                                <!-- Year -->
                                <td class="px-4 py-3 text-center text-xs">{{ s.year_level }}</td>

                                <!-- Semester -->
                                <td class="px-4 py-3 text-center text-xs">{{ s.semester }}</td>

                                <!-- LEC — inline edit input or display -->
                                <td class="px-4 py-3 text-center">
                                    <span v-if="editingId !== s.id"
                                        class="font-bold text-blue-600">{{ s.lec_units }}</span>
                                    <input v-else
                                        type="number" v-model.number="editLec"
                                        min="0" max="10" step="0.5"
                                        class="w-14 border border-blue-400 rounded px-1 py-0.5 text-center text-sm font-mono focus:outline-none focus:ring-2 focus:ring-blue-400"
                                    />
                                </td>

                                <!-- LAB — inline edit input or display -->
                                <td class="px-4 py-3 text-center">
                                    <span v-if="editingId !== s.id"
                                        :class="s.lab_units > 0
                                            ? 'font-bold text-orange-500'
                                            : 'text-muted-foreground'">
                                        {{ s.lab_units }}
                                    </span>
                                    <input v-else
                                        type="number" v-model.number="editLab"
                                        min="0" max="5"
                                        class="w-14 border border-orange-400 rounded px-1 py-0.5 text-center text-sm font-mono focus:outline-none focus:ring-2 focus:ring-orange-400"
                                    />
                                </td>

                                <!-- Actions -->
                                <td class="px-4 py-3">
                                    <!-- Normal mode: action buttons -->
                                    <div v-if="editingId !== s.id"
                                        class="flex items-center justify-center gap-2 flex-nowrap">

                                        <!-- Edit Units (inline) -->
                                        <button
                                            @click="startEdit(s)"
                                            class="inline-flex items-center gap-1.5 rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 hover:bg-blue-100 hover:border-blue-300 transition-colors"
                                        >
                                            <Pencil class="h-3 w-3 shrink-0" />
                                            Edit Units
                                        </button>

                                        <!-- Full Edit (Inertia link) -->
                                        <Link
                                            :href="route('accounting.subjects.edit', s.id)"
                                            class="inline-flex items-center gap-1.5 rounded-md border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-100 hover:border-gray-300 transition-colors"
                                        >
                                            <ExternalLink class="h-3 w-3 shrink-0" />
                                            Full Edit
                                        </Link>

                                        <!-- Deactivate — admin only -->
                                        <button
                                            v-if="canEditNstp"
                                            @click="deactivate(s)"
                                            class="inline-flex items-center gap-1.5 rounded-md border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-100 hover:border-red-300 transition-colors"
                                        >
                                            <Trash2 class="h-3 w-3 shrink-0" />
                                            Deactivate
                                        </button>
                                    </div>

                                    <!-- Edit mode: save / cancel -->
                                    <div v-else class="flex items-center justify-center gap-2">
                                        <button
                                            @click="saveInline(s)"
                                            :disabled="editSaving"
                                            class="inline-flex items-center gap-1.5 rounded-md border border-green-300 bg-green-50 px-2.5 py-1 text-xs font-medium text-green-700 hover:bg-green-100 transition-colors disabled:opacity-40"
                                        >
                                            <Loader2 v-if="editSaving" class="h-3 w-3 animate-spin shrink-0" />
                                            <Save v-else class="h-3 w-3 shrink-0" />
                                            Save
                                        </button>
                                        <button
                                            @click="cancelEdit"
                                            class="inline-flex items-center gap-1.5 rounded-md border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-700 transition-colors"
                                        >
                                            <X class="h-3 w-3 shrink-0" />
                                            Cancel
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Empty state -->
                            <tr v-if="subjects.data.length === 0">
                                <td colspan="8" class="px-4 py-12 text-center text-sm text-muted-foreground">
                                    No subjects found. Try adjusting your filters.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="subjects.links?.length" class="flex justify-center gap-1 flex-wrap">
                <component
                    v-for="link in subjects.links"
                    :key="link.label"
                    :is="link.url ? 'a' : 'span'"
                    :href="link.url ?? undefined"
                    v-html="link.label"
                    :class="[
                        'px-3 py-1.5 rounded-md text-sm border transition-colors',
                        link.active
                            ? 'bg-primary text-primary-foreground border-primary font-medium'
                            : link.url
                                ? 'border-border bg-background hover:bg-muted cursor-pointer'
                                : 'border-border text-muted-foreground cursor-default opacity-40',
                    ]"
                />
            </div>
        </div>
    </AppLayout>
</template>