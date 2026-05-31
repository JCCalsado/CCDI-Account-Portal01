<script setup lang="ts">
/**
 * CurriculumPreset/Show.vue
 *
 * This page is intentionally minimal. The `show()` method in
 * CurriculumPresetController redirects directly to:
 *   accounting.fee-settings.preset-subjects.index
 *
 * So in normal usage this component is never rendered — the server-side
 * redirect happens before Inertia loads this page.
 *
 * This file exists to:
 *   1. Satisfy Inertia's requirement that a component file exists at the
 *      rendered path if the controller ever renders it instead of redirecting.
 *   2. Provide a graceful loading state in case the redirect is slow.
 *   3. Serve as the named entry point if a dedicated Show page is built later
 *      (e.g. with its own breadcrumb trail back to CurriculumPreset/Index).
 */
import { onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import { Loader2 } from 'lucide-vue-next'

const props = defineProps<{
    preset?: {
        id: number
        course: string
        year_level: string
        semester: string
    }
}>()

// If somehow rendered directly, redirect to the correct page
onMounted(() => {
    if (props.preset?.id) {
        router.get(route('accounting.fee-settings.preset-subjects.index', {
            preset: props.preset.id,
        }))
    } else {
        router.get(route('accounting.curriculum-presets.index'))
    }
})
</script>

<template>
    <AppLayout>
        <div class="flex flex-col items-center justify-center min-h-[60vh] gap-4 text-muted-foreground">
            <Loader2 class="h-8 w-8 animate-spin text-blue-400" />
            <p class="text-sm">Loading subject management…</p>
        </div>
    </AppLayout>
</template>