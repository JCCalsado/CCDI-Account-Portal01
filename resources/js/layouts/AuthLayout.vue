<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import { Link } from '@inertiajs/vue3';

defineProps<{
    title: string;
    description?: string;
    wide?: boolean;
}>();
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center justify-center px-4 py-12 sm:px-6 lg:px-8"
        style="
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            background-image: url('/images/welcome.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
        "
    >
        <div :class="['w-full mx-auto', wide ? 'max-w-3xl' : 'max-w-md']">
            <!-- Logo -->
            <Link href="/" class="mb-8 flex justify-center">
                <AppLogo class="h-12 w-auto" />
            </Link>

            <div :class="['rounded-lg border border-border bg-card shadow-lg', wide ? 'p-10' : 'p-8']">
                <div class="mb-6 text-center">
                    <h1 class="text-2xl font-bold tracking-tight text-foreground">
                        {{ title }}
                    </h1>
                    <p v-if="description" class="mt-2 text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>

                <slot />
            </div>

            <p class="mt-6 text-center text-xs text-muted-foreground">
                © {{ new Date().getFullYear() }} CCDI Account Portal. All rights reserved.
            </p>
        </div>
    </div>
</template>