<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, Clock, Copy, Mail, XCircle, AlertTriangle } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    registration: {
        id: number;
        tracking_token: string;
        full_name: string;
        email: string;
        course: string;
        year_level: string;
        student_type: string;
        submitted_at: string;
        reviewed_at: string | null;
        status: string;
        status_label: string;
        status_color: string;
        rejection_reason: string | null;
        revision_notes: string | null;
    };
}>();

const copied = ref(false);

const copyToken = async () => {
    await navigator.clipboard.writeText(props.registration.tracking_token);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
};

const statusConfig = {
    pending: {
        icon: Clock,
        iconClass: 'text-yellow-500',
        bgClass: 'bg-yellow-50 border-yellow-200',
        headline: 'Your registration is under review.',
        body: 'The Accounting Department will review your submission. You will be notified by email once a decision is made.',
    },
    approved: {
        icon: CheckCircle2,
        iconClass: 'text-green-600',
        bgClass: 'bg-green-50 border-green-200',
        headline: 'Your registration has been approved!',
        body: 'Your account is now active. You can log in using the email and password you provided during registration.',
    },
    rejected: {
        icon: XCircle,
        iconClass: 'text-red-600',
        bgClass: 'bg-red-50 border-red-200',
        headline: 'Your registration was not approved.',
        body: 'Please see the reason below. You may submit a new registration after addressing the issue.',
    },
    needs_revision: {
        icon: AlertTriangle,
        iconClass: 'text-orange-500',
        bgClass: 'bg-orange-50 border-orange-200',
        headline: 'Your registration needs correction.',
        body: 'The Accounting Department has requested some revisions. Please check your email for the revision link.',
    },
};

const config = statusConfig[props.registration.status as keyof typeof statusConfig] ?? statusConfig.pending;

const steps = [
    { label: 'Submitted', done: true },
    {
        label: 'Under Review',
        done: ['approved', 'rejected', 'needs_revision'].includes(props.registration.status),
        active: props.registration.status === 'pending',
    },
    {
        label: 'Decision',
        done: ['approved', 'rejected'].includes(props.registration.status),
        active: props.registration.status === 'needs_revision',
    },
];
</script>

<template>
    <div class="min-h-screen bg-gray-50 flex flex-col items-center justify-center p-4">
        <Head title="Registration Status" />

        <div class="w-full max-w-lg bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">

            <!-- Header -->
            <div class="bg-primary px-6 py-5">
                <h1 class="text-lg font-bold text-primary-foreground">CCDI Account Portal</h1>
                <p class="text-sm text-primary-foreground/80 mt-0.5">Registration Status Tracker</p>
            </div>

            <div class="p-6 space-y-5">

                <!-- Status card -->
                <div :class="['rounded-lg border p-4 flex items-start gap-3', config.bgClass]">
                    <component :is="config.icon" :class="['h-5 w-5 flex-shrink-0 mt-0.5', config.iconClass]" />
                    <div>
                        <p class="font-semibold text-sm text-gray-900">{{ config.headline }}</p>
                        <p class="text-xs text-gray-600 mt-0.5">{{ config.body }}</p>
                    </div>
                </div>

                <!-- Progress tracker -->
                <div>
                    <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-3">Progress</p>
                    <ol class="relative border-l border-gray-200 ml-3 space-y-4">
                        <li v-for="(step, idx) in steps" :key="idx" class="ml-6">
                            <span
                                :class="[
                                    'absolute -left-3 flex h-6 w-6 items-center justify-center rounded-full border-2',
                                    step.done
                                        ? 'bg-green-500 border-green-500 text-white'
                                        : step.active
                                            ? 'bg-yellow-400 border-yellow-400 text-white'
                                            : 'bg-white border-gray-300 text-gray-400',
                                ]"
                            >
                                <CheckCircle2 v-if="step.done" class="h-3.5 w-3.5" />
                                <Clock v-else-if="step.active" class="h-3.5 w-3.5" />
                                <span v-else class="h-2 w-2 rounded-full bg-current" />
                            </span>
                            <p
                                :class="[
                                    'text-sm font-medium leading-none',
                                    step.done ? 'text-green-700' : step.active ? 'text-yellow-700' : 'text-gray-400',
                                ]"
                            >
                                {{ step.label }}
                            </p>
                        </li>
                    </ol>
                </div>

                <!-- Rejection reason -->
                <div
                    v-if="registration.status === 'rejected' && registration.rejection_reason"
                    class="rounded-md border border-red-200 bg-red-50 p-3"
                >
                    <p class="text-xs font-semibold text-red-700 mb-1">Reason for rejection:</p>
                    <p class="text-sm text-red-800">{{ registration.rejection_reason }}</p>
                </div>

                <!-- Revision notes -->
                <div
                    v-if="registration.status === 'needs_revision' && registration.revision_notes"
                    class="rounded-md border border-orange-200 bg-orange-50 p-3"
                >
                    <p class="text-xs font-semibold text-orange-700 mb-1">Revision requested:</p>
                    <p class="text-sm text-orange-800">{{ registration.revision_notes }}</p>
                    <p class="text-xs text-orange-600 mt-2">Check your email for a link to update your registration.</p>
                </div>

                <!-- Registration details -->
                <div class="rounded-md border border-gray-100 bg-gray-50 p-4 space-y-2">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">Registration Details</p>
                    <div class="grid grid-cols-2 gap-1 text-sm">
                        <span class="text-muted-foreground">Name</span>
                        <span class="font-medium text-gray-900">{{ registration.full_name }}</span>
                        <span class="text-muted-foreground">Email</span>
                        <span class="font-medium text-gray-900">{{ registration.email }}</span>
                        <span class="text-muted-foreground">Course</span>
                        <span class="font-medium text-gray-900">{{ registration.course }}</span>
                        <span class="text-muted-foreground">Year Level</span>
                        <span class="font-medium text-gray-900">{{ registration.year_level }}</span>
                        <span class="text-muted-foreground">Submitted</span>
                        <span class="font-medium text-gray-900">{{ registration.submitted_at }}</span>
                        <template v-if="registration.reviewed_at">
                            <span class="text-muted-foreground">Reviewed</span>
                            <span class="font-medium text-gray-900">{{ registration.reviewed_at }}</span>
                        </template>
                    </div>
                </div>

                <!-- Tracking token -->
                <div class="rounded-md border border-dashed border-gray-300 bg-white p-3">
                    <p class="text-xs text-muted-foreground mb-1">Your tracking token — save this to check your status later.</p>
                    <div class="flex items-center gap-2">
                        <code class="flex-1 text-sm font-mono font-bold text-gray-900 tracking-widest">
                            {{ registration.tracking_token }}
                        </code>
                        <button
                            type="button"
                            @click="copyToken"
                            class="flex items-center gap-1 text-xs text-primary hover:text-primary/80 transition-colors"
                        >
                            <Copy class="h-3.5 w-3.5" />
                            {{ copied ? 'Copied!' : 'Copy' }}
                        </button>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex flex-col gap-2 pt-1">
                    <a
                        v-if="registration.status === 'approved'"
                        :href="route('login')"
                        class="flex items-center justify-center gap-2 rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors"
                    >
                        Log in to the Portal
                    </a>

                    <a
                        v-if="registration.status === 'rejected'"
                        :href="route('register')"
                        class="flex items-center justify-center gap-2 rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors"
                    >
                        Submit a New Registration
                    </a>

                    <a
                        :href="`mailto:${registration.email}`"
                        class="flex items-center justify-center gap-2 rounded-md border border-input bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
                    >
                        <Mail class="h-4 w-4" />
                        Contact Accounting
                    </a>
                </div>
            </div>
        </div>

        <p class="mt-4 text-xs text-gray-400">
            © {{ new Date().getFullYear() }} Computer Communication Development Institute
        </p>
    </div>
</template>