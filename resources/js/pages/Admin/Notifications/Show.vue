<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowLeft,
    Bell,
    Calendar,
    CalendarClock,
    CheckCircle2,
    Edit2,
    Megaphone,
    Users,
    Zap,
} from 'lucide-vue-next';
import { computed } from 'vue';

interface Notification {
    id: number;
    title: string;
    message: string | null;
    type: string | null;
    priority: string | null;
    notification_status: string | null;
    target_role: string;
    start_date: string | null;
    end_date: string | null;
    due_date: string | null;
    payment_term_id: number | null;
    user_id: number | null;
    user_ids: number[] | null;
    is_active: boolean;
    is_complete: boolean;
    term_ids: number[] | null;
    target_term_name: string | null;
    trigger_days_before_due: number | null;
    course_filter: string[] | null;
    year_level_filter: string[] | null;
    balance_filter: string | null;
    dismissed_at: string | null;
    read_at: string | null;
    created_at: string;
    updated_at: string;
}

interface Props {
    notification: Notification;
}

const props = defineProps<Props>();

const page       = usePage();
const isAccounting = computed(() => (page.props.auth as any)?.user?.role === 'accounting');

const breadcrumbs = computed(() => {
    if (isAccounting.value) {
        return [
            { title: 'Accounting', href: route('accounting.dashboard') },
            { title: 'Notifications', href: route('accounting.notifications.index') },
            { title: props.notification.title, href: route('accounting.notifications.show', props.notification.id) },
        ];
    }
    return [
        { title: 'Admin', href: route('admin.dashboard') },
        { title: 'Notifications', href: route('admin.notifications.index') },
        { title: props.notification.title, href: route('admin.notifications.show', props.notification.id) },
    ];
});

const backHref = computed(() =>
    isAccounting.value
        ? route('accounting.notifications.index')
        : route('admin.notifications.index'),
);

// ── Formatters ────────────────────────────────────────────────────────────

const formatDate = (dateStr: string | null | undefined): string => {
    if (!dateStr) return '—';
    return new Date(dateStr.split('T')[0] + 'T12:00:00').toLocaleDateString('en-US', {
        year: 'numeric', month: 'long', day: 'numeric',
    });
};

const formatDateTime = (dateStr: string | null | undefined): string => {
    if (!dateStr) return '—';
    return new Date(dateStr).toLocaleString('en-US', {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit',
    });
};

// ── Status derivation ─────────────────────────────────────────────────────

const derivedStatus = computed((): string => {
    const n   = props.notification;
    const ns  = n.notification_status;
    if (ns)   return ns;
    // fallback: derive from booleans for old records without notification_status
    if (!n.is_active)   return 'draft';
    if (n.is_complete)  return 'expired';
    const today    = new Date().toLocaleDateString('en-CA');
    const startStr = n.start_date?.split('T')[0] ?? '';
    if (startStr && startStr > today) return 'scheduled';
    return 'active';
});

const statusConfig = computed(() => {
    const configs: Record<string, { label: string; cls: string; dot: string }> = {
        draft:     { label: 'Draft',     cls: 'bg-gray-100 text-gray-700 ring-gray-200',    dot: 'bg-gray-400' },
        scheduled: { label: 'Scheduled', cls: 'bg-blue-100 text-blue-800 ring-blue-200',   dot: 'bg-blue-500' },
        active:    { label: 'Active',    cls: 'bg-green-100 text-green-800 ring-green-200', dot: 'bg-green-500' },
        expired:   { label: 'Expired',   cls: 'bg-red-100 text-red-700 ring-red-200',       dot: 'bg-red-400' },
    };
    return configs[derivedStatus.value] ?? configs.draft;
});

// ── Priority ──────────────────────────────────────────────────────────────

const priorityConfig = computed(() => {
    const configs: Record<string, { label: string; cls: string; border: string }> = {
        low:    { label: 'Low',    cls: 'bg-gray-100 text-gray-600',          border: 'border-l-gray-300' },
        medium: { label: 'Medium', cls: 'bg-blue-100 text-blue-700',          border: 'border-l-blue-400' },
        high:   { label: 'High',   cls: 'bg-amber-100 text-amber-800',        border: 'border-l-amber-500' },
        urgent: { label: 'Urgent', cls: 'bg-red-100 text-red-800 font-bold',  border: 'border-l-red-600' },
    };
    return configs[props.notification.priority ?? 'medium'] ?? configs.medium;
});

// ── Type ──────────────────────────────────────────────────────────────────

const typeLabels: Record<string, string> = {
    general:            '📢 General',
    reminder:           '🔔 Reminder',
    warning:            '⚠️ Warning',
    deadline:           '🗓 Deadline',
    announcement:       '📣 Announcement',
    payment_due:        '💳 Payment Due',
    payment_due_notice: '💳 Payment Due Notice',
    payment_approved:   '✅ Payment Approved',
    payment_rejected:   '❌ Payment Rejected',
};

const typeLabel = computed(() => typeLabels[props.notification.type ?? ''] ?? props.notification.type ?? '—');

const typeColorClass = computed(() => {
    const colors: Record<string, string> = {
        general:            'bg-blue-100 text-blue-800',
        reminder:           'bg-indigo-100 text-indigo-800',
        warning:            'bg-amber-100 text-amber-800',
        deadline:           'bg-orange-100 text-orange-800',
        announcement:       'bg-purple-100 text-purple-800',
        payment_due:        'bg-amber-100 text-amber-800',
        payment_due_notice: 'bg-amber-100 text-amber-800',
        payment_approved:   'bg-emerald-100 text-emerald-800',
        payment_rejected:   'bg-red-100 text-red-800',
    };
    return colors[props.notification.type ?? ''] ?? 'bg-gray-100 text-gray-800';
});

// ── Audience summary ──────────────────────────────────────────────────────

const roleLabel = (role: string): string => {
    const labels: Record<string, string> = {
        student:    'All Students',
        accounting: 'Accounting Staff',
        admin:      'Admins',
        all:        'Everyone',
    };
    return labels[role] ?? role;
};

const balanceFilterLabel = (bf: string | null): string => {
    const labels: Record<string, string> = {
        any:          'Any Balance',
        with_balance: 'With Outstanding Balance',
        overdue:      'Overdue Balance Only',
    };
    return labels[bf ?? 'any'] ?? bf ?? '—';
};

const dueDateClass = computed((): string => {
    if (!props.notification.due_date) return 'text-gray-600';
    const diff = Math.ceil((new Date(props.notification.due_date).getTime() - Date.now()) / 86_400_000);
    if (diff < 0)  return 'text-red-700 font-semibold';
    if (diff <= 7) return 'text-red-600 font-medium';
    if (diff <= 14) return 'text-amber-600 font-medium';
    return 'text-gray-700';
});

const hasCourseFilter      = computed(() => (props.notification.course_filter?.length ?? 0) > 0);
const hasYearLevelFilter   = computed(() => (props.notification.year_level_filter?.length ?? 0) > 0);
const hasSpecificStudents  = computed(() => (props.notification.user_ids?.length ?? 0) > 0 || props.notification.user_id);
const hasTermFilter        = computed(() => props.notification.target_term_name || (props.notification.term_ids?.length ?? 0) > 0);
</script>

<template>
    <Head :title="`Notification: ${notification.title}`" />
    <AppLayout>
        <div class="w-full p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Page header -->
            <div class="mb-6 flex items-start justify-between">
                <div class="flex-1">
                    <!-- Priority border indicator on title block -->
                    <div
                        :class="[
                            'inline-block border-l-4 pl-3',
                            priorityConfig.border,
                        ]"
                    >
                        <h1 class="text-2xl font-bold text-gray-900 leading-tight">
                            {{ notification.title }}
                        </h1>
                        <p class="mt-1 text-sm text-gray-500">Notification detail</p>
                    </div>
                </div>

                <div class="ml-4 flex shrink-0 gap-2">
                    <Link :href="backHref">
                        <Button variant="outline">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Back
                        </Button>
                    </Link>
                    <Link
                        v-if="isAccounting"
                        :href="route('accounting.notifications.edit', notification.id)"
                    >
                        <Button>
                            <Edit2 class="mr-2 h-4 w-4" />
                            Edit
                        </Button>
                    </Link>
                    <span
                        v-else
                        class="inline-flex items-center rounded-full bg-purple-100 px-3 py-1 text-xs font-medium text-purple-700"
                    >
                        Read-only
                    </span>
                </div>
            </div>

            <!-- Content grid -->
            <div class="grid max-w-5xl grid-cols-1 gap-6 lg:grid-cols-3">

                <!-- Left: main details (2/3 width) -->
                <div class="space-y-6 lg:col-span-2">

                    <!-- Status & Priority badges row -->
                    <div class="flex flex-wrap gap-2">
                        <!-- Status badge -->
                        <span
                            :class="[
                                'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1',
                                statusConfig.cls,
                            ]"
                        >
                            <span :class="['h-1.5 w-1.5 rounded-full', statusConfig.dot]" />
                            {{ statusConfig.label }}
                        </span>

                        <!-- Priority badge -->
                        <span
                            :class="[
                                'inline-flex items-center rounded-full px-3 py-1 text-xs font-medium',
                                priorityConfig.cls,
                            ]"
                        >
                            Priority: {{ priorityConfig.label }}
                        </span>

                        <!-- Type badge -->
                        <span
                            v-if="notification.type"
                            :class="['rounded-full px-3 py-1 text-xs font-medium', typeColorClass]"
                        >
                            {{ typeLabel }}
                        </span>

                        <!-- Completed badge -->
                        <span
                            v-if="notification.is_complete"
                            class="rounded-full bg-gray-200 px-3 py-1 text-xs font-medium text-gray-600"
                        >
                            ✓ Completed
                        </span>
                    </div>

                    <!-- Notification Content card -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2 text-base">
                                <Bell class="h-4 w-4 text-gray-500" />
                                Notification Content
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-4 text-sm">
                            <div>
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                    Title
                                </p>
                                <p class="font-semibold text-gray-900">{{ notification.title }}</p>
                            </div>

                            <div v-if="notification.message">
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                    Message
                                </p>
                                <pre class="whitespace-pre-wrap rounded-lg bg-gray-50 p-4 text-sm leading-relaxed text-gray-800 font-sans">{{ notification.message }}</pre>
                            </div>
                            <div v-else class="rounded-lg bg-gray-50 p-3 text-sm text-gray-400 italic">
                                No message body.
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Schedule card -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2 text-base">
                                <Calendar class="h-4 w-4 text-gray-500" />
                                Schedule
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-3 text-sm">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                        Start Date
                                    </p>
                                    <p class="text-gray-900">{{ formatDate(notification.start_date) }}</p>
                                </div>
                                <div>
                                    <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                        End Date
                                    </p>
                                    <p class="text-gray-900">{{ formatDate(notification.end_date) }}</p>
                                </div>
                            </div>

                            <div v-if="notification.due_date">
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                    Due Date
                                </p>
                                <p :class="['flex items-center gap-1.5', dueDateClass]">
                                    <CalendarClock class="h-4 w-4" />
                                    {{ formatDate(notification.due_date) }}
                                </p>
                            </div>

                            <div v-if="notification.trigger_days_before_due !== null && notification.trigger_days_before_due !== undefined">
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                    Trigger Window
                                </p>
                                <p class="text-gray-700">
                                    ⏱ Shows <strong>{{ notification.trigger_days_before_due }} day{{ notification.trigger_days_before_due !== 1 ? 's' : '' }}</strong> before due date
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Term Targeting card (only if relevant) -->
                    <Card v-if="hasTermFilter">
                        <CardHeader>
                            <CardTitle class="text-base">🎓 Term Targeting</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-3 text-sm">
                            <div v-if="notification.target_term_name">
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                    Target Term Name
                                </p>
                                <span class="inline-flex rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-800">
                                    {{ notification.target_term_name }}
                                </span>
                            </div>

                            <div v-if="notification.term_ids && notification.term_ids.length > 0">
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                    Specific Term IDs
                                </p>
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="tid in notification.term_ids"
                                        :key="tid"
                                        class="inline-flex rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-700 ring-1 ring-indigo-200"
                                    >
                                        ID {{ tid }}
                                    </span>
                                </div>
                            </div>

                            <div v-if="notification.payment_term_id">
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                    Linked Payment Term ID
                                </p>
                                <p class="text-gray-700">{{ notification.payment_term_id }}</p>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Metadata card -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-base">📋 Metadata</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-2 text-sm">
                            <div class="flex justify-between border-b py-2">
                                <span class="text-gray-500">Created</span>
                                <span class="text-gray-800">{{ formatDateTime(notification.created_at) }}</span>
                            </div>
                            <div class="flex justify-between border-b py-2">
                                <span class="text-gray-500">Last Updated</span>
                                <span class="text-gray-800">{{ formatDateTime(notification.updated_at) }}</span>
                            </div>
                            <div v-if="notification.read_at" class="flex justify-between border-b py-2">
                                <span class="text-gray-500">Read At</span>
                                <span class="text-gray-800">{{ formatDateTime(notification.read_at) }}</span>
                            </div>
                            <div v-if="notification.dismissed_at" class="flex justify-between py-2">
                                <span class="text-gray-500">Dismissed At</span>
                                <span class="text-gray-800">{{ formatDateTime(notification.dismissed_at) }}</span>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- Right: audience sidebar (1/3 width) -->
                <div class="space-y-6">

                    <!-- Target Audience card -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2 text-base">
                                <Users class="h-4 w-4 text-gray-500" />
                                Target Audience
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-4 text-sm">
                            <!-- Role -->
                            <div>
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Role</p>
                                <span class="inline-flex rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-800">
                                    {{ roleLabel(notification.target_role) }}
                                </span>
                            </div>

                            <!-- Specific student(s) -->
                            <div v-if="hasSpecificStudents">
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                    Specific Recipients
                                </p>
                                <p v-if="notification.user_ids && notification.user_ids.length > 0" class="text-gray-700">
                                    👥 {{ notification.user_ids.length }} specific student{{ notification.user_ids.length !== 1 ? 's' : '' }}
                                </p>
                                <p v-else-if="notification.user_id" class="text-gray-700">
                                    👤 Single student (ID: {{ notification.user_id }})
                                </p>
                            </div>

                            <!-- Course filter -->
                            <div v-if="hasCourseFilter">
                                <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                    Course Filter
                                </p>
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="course in notification.course_filter"
                                        :key="course"
                                        class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800"
                                    >
                                        🎓 {{ course }}
                                    </span>
                                </div>
                            </div>
                            <div v-else>
                                <p class="text-xs text-gray-400">Course filter: All courses</p>
                            </div>

                            <!-- Year level filter -->
                            <div v-if="hasYearLevelFilter">
                                <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                    Year Level Filter
                                </p>
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="yl in notification.year_level_filter"
                                        :key="yl"
                                        class="rounded-full bg-violet-100 px-2.5 py-0.5 text-xs font-medium text-violet-800"
                                    >
                                        {{ yl }}
                                    </span>
                                </div>
                            </div>
                            <div v-else>
                                <p class="text-xs text-gray-400">Year level: All year levels</p>
                            </div>

                            <!-- Balance filter -->
                            <div>
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">
                                    Balance Filter
                                </p>
                                <p class="text-gray-700 text-xs">{{ balanceFilterLabel(notification.balance_filter) }}</p>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Quick status card -->
                    <Card :class="['border-l-4', priorityConfig.border]">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2 text-sm">
                                <Zap class="h-4 w-4" />
                                Quick Status
                            </CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-2 text-xs">
                            <div class="flex items-center justify-between">
                                <span class="text-gray-500">Active</span>
                                <span :class="notification.is_active ? 'text-green-700 font-semibold' : 'text-gray-400'">
                                    {{ notification.is_active ? '✓ Yes' : '✗ No' }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-gray-500">Complete</span>
                                <span :class="notification.is_complete ? 'text-blue-700 font-semibold' : 'text-gray-400'">
                                    {{ notification.is_complete ? '✓ Yes' : '✗ No' }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-gray-500">Dismissed</span>
                                <span :class="notification.dismissed_at ? 'text-amber-700 font-semibold' : 'text-gray-400'">
                                    {{ notification.dismissed_at ? '✓ Yes' : '✗ No' }}
                                </span>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Actions -->
                    <div class="flex flex-col gap-2">
                        <Link :href="backHref">
                            <Button variant="outline" class="w-full">
                                <ArrowLeft class="mr-2 h-4 w-4" />
                                Back to Notifications
                            </Button>
                        </Link>
                        <Link
                            v-if="isAccounting"
                            :href="route('accounting.notifications.edit', notification.id)"
                        >
                            <Button class="w-full">
                                <Edit2 class="mr-2 h-4 w-4" />
                                Edit Notification
                            </Button>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>