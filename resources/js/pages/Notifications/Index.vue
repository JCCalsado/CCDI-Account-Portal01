<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    AlertCircle, Bell, BellOff, Calendar, CalendarClock, CheckCircle2,
    ChevronDown, ChevronUp, Clock, Megaphone, XCircle, Info,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

// ─── Types ─────────────────────────────────────────────────────────────────

type NotificationType =
    | 'general'
    | 'reminder'
    | 'warning'
    | 'announcement'
    | 'deadline'
    | 'payment_due'
    | 'payment_due_notice'
    | 'payment_approved'
    | 'payment_rejected'
    | null;

type Notification = {
    id: number;
    title: string;
    message: string | null;
    type: NotificationType;
    start_date: string | null;
    end_date: string | null;
    due_date: string | null;
    payment_term_id: number | null;
    target_role: string;
    is_active: boolean;
    is_complete: boolean;
    dismissed_at: string | null;
    created_at: string;
};

// ─── Props ─────────────────────────────────────────────────────────────────

const props = defineProps<{
    active:  Notification[];
    history: Notification[];
}>();

const breadcrumbs = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Notifications' },
];

// ─── Optimistic dismiss ───────────────────────────────────────────────────

const locallyDismissed = ref<Set<number>>(new Set());
const dismissForm       = useForm({});

function dismiss(id: number) {
    locallyDismissed.value = new Set([...locallyDismissed.value, id]);
    dismissForm.post(route('notifications.dismiss', id), {
        preserveScroll: true,
        onError: () => {
            const s = new Set(locallyDismissed.value);
            s.delete(id);
            locallyDismissed.value = s;
        },
    });
}

function dismissAll() {
    visibleActive.value.forEach((n) => dismiss(n.id));
}

function markAllRead() {
    router.post(route('student.notifications.mark-all-read'), {}, { preserveScroll: true });
}

// ─── History expand/collapse ──────────────────────────────────────────────

const expandedHistory = ref<Set<number>>(new Set());

const toggleHistory = (id: number) => {
    const s = new Set(expandedHistory.value);
    if (s.has(id)) s.delete(id);
    else s.add(id);
    expandedHistory.value = s;
};

const isHistoryExpanded = (id: number) => expandedHistory.value.has(id);

// ─── Visible active (filter optimistic dismissals) ────────────────────────

const visibleActive = computed(() =>
    props.active.filter((n) => !locallyDismissed.value.has(n.id)),
);

// ─── Type config ──────────────────────────────────────────────────────────
//
// Covers all 9 types the backend can produce. Previously only 4 were handled;
// payment_approved/rejected/deadline/warning/reminder/announcement all fell
// through to generic blue — losing urgency, colour coding, and due-date banners.

type TypeConfig = {
    label: string;
    icon: any;
    // card
    borderClass: string;
    bgClass: string;
    // icon
    iconBgClass: string;
    iconClass: string;
    // badge
    badgeClass: string;
    // urgency rank (lower = shown first)
    priority: number;
};

const typeConfig: Record<string, TypeConfig> = {
    payment_due: {
        label:       'Payment Due',
        icon:        CalendarClock,
        borderClass: 'border-amber-300',
        bgClass:     'bg-gradient-to-br from-amber-50 to-orange-50',
        iconBgClass: 'bg-amber-100',
        iconClass:   'text-amber-600',
        badgeClass:  'bg-amber-100 text-amber-800 ring-1 ring-amber-200',
        priority:    1,
    },
    payment_due_notice: {
        label:       'Payment Notice',
        icon:        CalendarClock,
        borderClass: 'border-amber-200',
        bgClass:     'bg-amber-50/60',
        iconBgClass: 'bg-amber-100',
        iconClass:   'text-amber-500',
        badgeClass:  'bg-amber-100 text-amber-700 ring-1 ring-amber-200',
        priority:    2,
    },
    deadline: {
        label:       'Deadline',
        icon:        AlertCircle,
        borderClass: 'border-red-300',
        bgClass:     'bg-gradient-to-br from-red-50 to-rose-50',
        iconBgClass: 'bg-red-100',
        iconClass:   'text-red-600',
        badgeClass:  'bg-red-100 text-red-800 ring-1 ring-red-200',
        priority:    1,
    },
    warning: {
        label:       'Warning',
        icon:        AlertCircle,
        borderClass: 'border-orange-300',
        bgClass:     'bg-orange-50/70',
        iconBgClass: 'bg-orange-100',
        iconClass:   'text-orange-600',
        badgeClass:  'bg-orange-100 text-orange-800 ring-1 ring-orange-200',
        priority:    2,
    },
    payment_approved: {
        label:       'Payment Approved',
        icon:        CheckCircle2,
        borderClass: 'border-emerald-300',
        bgClass:     'bg-gradient-to-br from-emerald-50 to-green-50',
        iconBgClass: 'bg-emerald-100',
        iconClass:   'text-emerald-600',
        badgeClass:  'bg-emerald-100 text-emerald-800 ring-1 ring-emerald-200',
        priority:    3,
    },
    payment_rejected: {
        label:       'Payment Rejected',
        icon:        XCircle,
        borderClass: 'border-red-300',
        bgClass:     'bg-gradient-to-br from-red-50 to-rose-50',
        iconBgClass: 'bg-red-100',
        iconClass:   'text-red-600',
        badgeClass:  'bg-red-100 text-red-800 ring-1 ring-red-200',
        priority:    1,
    },
    reminder: {
        label:       'Reminder',
        icon:        Bell,
        borderClass: 'border-blue-200',
        bgClass:     'bg-blue-50/60',
        iconBgClass: 'bg-blue-100',
        iconClass:   'text-blue-600',
        badgeClass:  'bg-blue-100 text-blue-800',
        priority:    4,
    },
    announcement: {
        label:       'Announcement',
        icon:        Megaphone,
        borderClass: 'border-blue-200',
        bgClass:     'bg-blue-50/50',
        iconBgClass: 'bg-blue-100',
        iconClass:   'text-blue-600',
        badgeClass:  'bg-blue-100 text-blue-800',
        priority:    5,
    },
    general: {
        label:       'Announcement',
        icon:        Megaphone,
        borderClass: 'border-gray-200',
        bgClass:     'bg-gray-50/50',
        iconBgClass: 'bg-gray-100',
        iconClass:   'text-gray-500',
        badgeClass:  'bg-gray-100 text-gray-700',
        priority:    6,
    },
};

function cfg(type: NotificationType): TypeConfig {
    return typeConfig[type ?? 'general'] ?? typeConfig.general;
}

// Types that carry a due date — gates the urgency banner and Pay Now button
const DUE_DATE_TYPES: NotificationType[] = ['payment_due', 'payment_due_notice', 'deadline'];

function hasDueDate(type: NotificationType): boolean {
    return DUE_DATE_TYPES.includes(type);
}

// ─── Priority groups for the summary bar ─────────────────────────────────

const urgentCount = computed(() =>
    visibleActive.value.filter((n) =>
        ['payment_due', 'deadline', 'warning', 'payment_rejected'].includes(n.type ?? ''),
    ).length,
);

const approvalCount = computed(() =>
    visibleActive.value.filter((n) => n.type === 'payment_approved').length,
);

const infoCount = computed(() =>
    visibleActive.value.filter((n) =>
        ['reminder', 'announcement', 'general', 'payment_due_notice'].includes(n.type ?? ''),
    ).length,
);

// Sort active notifications: urgent first, then by due_date proximity, then created_at
const sortedActive = computed(() =>
    [...visibleActive.value].sort((a, b) => {
        const pa = cfg(a.type).priority;
        const pb = cfg(b.type).priority;
        if (pa !== pb) return pa - pb;
        // Within same priority: nearest due date first
        if (a.due_date && b.due_date) {
            return new Date(a.due_date).getTime() - new Date(b.due_date).getTime();
        }
        if (a.due_date) return -1;
        if (b.due_date) return 1;
        return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
    }),
);

// ─── Formatting helpers ───────────────────────────────────────────────────

const formatDate = (date: string | null) => {
    if (!date) return '';
    return new Date(date + 'T12:00:00').toLocaleDateString('en-PH', {
        month: 'long', day: 'numeric', year: 'numeric',
    });
};

const formatRelative = (datetimeStr: string | null): string => {
    if (!datetimeStr) return '';
    const d        = new Date(datetimeStr);
    const now      = new Date();
    const diffDays = Math.floor((now.getTime() - d.getTime()) / 86_400_000);
    if (diffDays === 0) return 'Today';
    if (diffDays === 1) return 'Yesterday';
    if (diffDays < 7)   return `${diffDays} days ago`;
    return formatDate(datetimeStr.split('T')[0]);
};

const dueDaysLabel = (dueDateStr: string | null): string | null => {
    if (!dueDateStr) return null;
    const diff = Math.ceil((new Date(dueDateStr).getTime() - Date.now()) / 86_400_000);
    if (diff < 0)   return `Overdue by ${Math.abs(diff)} day${Math.abs(diff) !== 1 ? 's' : ''}`;
    if (diff === 0) return 'Due today';
    if (diff === 1) return 'Due tomorrow';
    return `Due in ${diff} day${diff !== 1 ? 's' : ''}`;
};

const dueDaysUrgencyClass = (dueDateStr: string | null): string => {
    if (!dueDateStr) return '';
    const diff = Math.ceil((new Date(dueDateStr).getTime() - Date.now()) / 86_400_000);
    if (diff <= 0)  return 'bg-red-100 text-red-800 ring-1 ring-red-300';
    if (diff <= 7)  return 'bg-red-100 text-red-700 ring-1 ring-red-200';
    if (diff <= 14) return 'bg-amber-100 text-amber-700 ring-1 ring-amber-200';
    return 'bg-green-100 text-green-700 ring-1 ring-green-200';
};

// Counts
const totalCount = computed(() => props.active.length + props.history.length);
const hasUnread  = computed(() => visibleActive.value.length > 0);
</script>

<template>
    <AppLayout>
        <Head title="Notifications" />

        <div class="mx-auto max-w-3xl p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- ── Page Header ───────────────────────────────────────────── -->
            <div class="mb-6 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="rounded-2xl bg-blue-100 p-3 shadow-sm">
                        <Bell :size="22" class="text-blue-600" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Notifications</h1>
                        <p class="text-sm text-gray-500">
                            {{ totalCount }} notification{{ totalCount !== 1 ? 's' : '' }}
                            <span v-if="history.length" class="ml-1 text-gray-400">
                                ({{ history.length }} in history)
                            </span>
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        v-if="hasUnread && visibleActive.length > 1"
                        @click="dismissAll"
                        class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-600 shadow-sm transition hover:bg-red-50 hover:border-red-200 hover:text-red-700"
                    >
                        ✕ Dismiss all
                    </button>
                    <button
                        v-if="hasUnread"
                        @click="markAllRead"
                        class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-600 shadow-sm transition hover:bg-gray-50"
                    >
                        ✓ Mark all read
                    </button>
                </div>
            </div>

            <!-- ── Summary Bar ───────────────────────────────────────────── -->
            <div
                v-if="visibleActive.length > 0"
                class="mb-6 grid grid-cols-3 gap-3"
            >
                <div
                    :class="[
                        'rounded-xl border p-3 text-center transition-all',
                        urgentCount > 0
                            ? 'border-red-200 bg-red-50'
                            : 'border-gray-200 bg-gray-50 opacity-50',
                    ]"
                >
                    <p class="text-2xl font-extrabold" :class="urgentCount > 0 ? 'text-red-700' : 'text-gray-400'">
                        {{ urgentCount }}
                    </p>
                    <p class="mt-0.5 text-xs font-semibold uppercase tracking-wide" :class="urgentCount > 0 ? 'text-red-600' : 'text-gray-400'">
                        Urgent
                    </p>
                </div>
                <div
                    :class="[
                        'rounded-xl border p-3 text-center transition-all',
                        approvalCount > 0
                            ? 'border-emerald-200 bg-emerald-50'
                            : 'border-gray-200 bg-gray-50 opacity-50',
                    ]"
                >
                    <p class="text-2xl font-extrabold" :class="approvalCount > 0 ? 'text-emerald-700' : 'text-gray-400'">
                        {{ approvalCount }}
                    </p>
                    <p class="mt-0.5 text-xs font-semibold uppercase tracking-wide" :class="approvalCount > 0 ? 'text-emerald-600' : 'text-gray-400'">
                        Approvals
                    </p>
                </div>
                <div
                    :class="[
                        'rounded-xl border p-3 text-center transition-all',
                        infoCount > 0
                            ? 'border-blue-200 bg-blue-50'
                            : 'border-gray-200 bg-gray-50 opacity-50',
                    ]"
                >
                    <p class="text-2xl font-extrabold" :class="infoCount > 0 ? 'text-blue-700' : 'text-gray-400'">
                        {{ infoCount }}
                    </p>
                    <p class="mt-0.5 text-xs font-semibold uppercase tracking-wide" :class="infoCount > 0 ? 'text-blue-600' : 'text-gray-400'">
                        Updates
                    </p>
                </div>
            </div>

            <!-- ── ACTIVE NOTIFICATIONS ──────────────────────────────────── -->
            <section class="mb-8">
                <TransitionGroup name="notification" tag="div" class="space-y-3">
                    <div
                        v-for="n in sortedActive"
                        :key="n.id"
                        :class="[
                            'rounded-2xl border-2 shadow-sm transition-all overflow-hidden',
                            cfg(n.type).borderClass,
                            cfg(n.type).bgClass,
                        ]"
                    >
                        <!-- Card body -->
                        <div class="p-5">
                            <!-- Icon + type badge + dismiss button -->
                            <div class="mb-3 flex items-start gap-3">
                                <!-- Icon avatar -->
                                <div
                                    :class="[
                                        'flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl',
                                        cfg(n.type).iconBgClass,
                                    ]"
                                >
                                    <component
                                        :is="cfg(n.type).icon"
                                        :size="18"
                                        :class="cfg(n.type).iconClass"
                                    />
                                </div>

                                <div class="min-w-0 flex-1">
                                    <!-- Type label -->
                                    <span
                                        :class="[
                                            'mb-1 inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                            cfg(n.type).badgeClass,
                                        ]"
                                    >
                                        {{ cfg(n.type).label }}
                                    </span>
                                    <!-- Title -->
                                    <h3 class="text-base font-bold leading-snug text-gray-900">
                                        {{ n.title }}
                                    </h3>
                                </div>

                                <!-- Dismiss button -->
                                <button
                                    @click="dismiss(n.id)"
                                    :disabled="dismissForm.processing"
                                    class="ml-1 flex-shrink-0 rounded-lg p-1.5 text-gray-400 transition hover:bg-black/10 hover:text-gray-700 disabled:opacity-40"
                                    title="Dismiss"
                                >
                                    <XCircle :size="16" />
                                </button>
                            </div>

                            <!-- Message body -->
                            <p
                                v-if="n.message"
                                class="mb-4 pl-12 text-sm leading-relaxed whitespace-pre-line text-gray-700"
                            >
                                {{ n.message }}
                            </p>

                            <!-- Due date urgency pill — payment_due / deadline / payment_due_notice -->
                            <div v-if="n.due_date && hasDueDate(n.type)" class="mb-4 pl-12">
                                <span
                                    :class="[
                                        'inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold',
                                        dueDaysUrgencyClass(n.due_date),
                                    ]"
                                >
                                    <CalendarClock :size="12" />
                                    {{ dueDaysLabel(n.due_date) }}
                                    <span class="font-normal opacity-80">· {{ formatDate(n.due_date) }}</span>
                                </span>
                            </div>

                            <!-- Payment approved / rejected confirmation line -->
                            <div
                                v-if="n.type === 'payment_approved'"
                                class="mb-4 pl-12"
                            >
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800 ring-1 ring-emerald-200">
                                    <CheckCircle2 :size="12" />
                                    Payment Verified &amp; Applied
                                </span>
                            </div>

                            <div
                                v-if="n.type === 'payment_rejected'"
                                class="mb-4 pl-12"
                            >
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-red-100 px-3 py-1 text-xs font-bold text-red-800 ring-1 ring-red-200">
                                    <XCircle :size="12" />
                                    Payment Rejected — Action Required
                                </span>
                            </div>
                        </div>

                        <!-- Footer strip -->
                        <div
                            class="flex flex-wrap items-center justify-between gap-2 border-t border-black/10 bg-black/[0.025] px-5 py-3"
                        >
                            <!-- Date range -->
                            <div class="flex flex-wrap gap-3 text-xs text-gray-500">
                                <span
                                    v-if="n.start_date"
                                    class="flex items-center gap-1"
                                >
                                    <Calendar :size="11" />
                                    {{ formatDate(n.start_date) }}
                                    <span v-if="n.end_date"> – {{ formatDate(n.end_date) }}</span>
                                </span>
                                <span
                                    v-else
                                    class="flex items-center gap-1 text-gray-400"
                                >
                                    <Clock :size="11" />
                                    {{ formatRelative(n.created_at) }}
                                </span>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-2">
                                <!-- Pay Now — only for due-date types with a linked term -->
                                <a
                                    v-if="hasDueDate(n.type) && n.payment_term_id"
                                    :href="route('student.account')"
                                    class="rounded-lg bg-emerald-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-700"
                                >
                                    Pay Now →
                                </a>

                                <!-- View account for approved payments -->
                                <a
                                    v-if="n.type === 'payment_approved'"
                                    :href="route('student.account')"
                                    class="rounded-lg bg-emerald-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-700"
                                >
                                    View Account →
                                </a>

                                <!-- Resubmit for rejected payments -->
                                <a
                                    v-if="n.type === 'payment_rejected'"
                                    :href="route('payment.create')"
                                    class="rounded-lg bg-red-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm transition hover:bg-red-700"
                                >
                                    Resubmit Payment →
                                </a>

                                <!-- Dismiss -->
                                <button
                                    @click="dismiss(n.id)"
                                    :disabled="dismissForm.processing"
                                    class="rounded-lg border border-black/15 bg-white/70 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-white disabled:opacity-40"
                                >
                                    Dismiss
                                </button>
                            </div>
                        </div>
                    </div>
                </TransitionGroup>

                <!-- ── Empty states ────────────────────────────────────────── -->
                <!-- All caught up (no history either) -->
                <div
                    v-if="visibleActive.length === 0 && history.length === 0"
                    class="rounded-2xl border-2 border-dashed border-gray-200 py-20 text-center"
                >
                    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-gray-100">
                        <BellOff :size="28" class="text-gray-400" />
                    </div>
                    <p class="text-lg font-semibold text-gray-600">You're all caught up</p>
                    <p class="mt-1 text-sm text-gray-400">No active notifications at this time.</p>
                </div>

                <!-- Active dismissed, history exists -->
                <div
                    v-else-if="visibleActive.length === 0"
                    class="rounded-2xl border-2 border-dashed border-gray-200 py-10 text-center"
                >
                    <Bell :size="28" class="mx-auto mb-2 text-gray-300" />
                    <p class="text-sm font-medium text-gray-400">No active notifications</p>
                    <p class="mt-1 text-xs text-gray-400">Check your notification history below.</p>
                </div>
            </section>

            <!-- ── HISTORY ──────────────────────────────────────────────── -->
            <section v-if="history.length">
                <div class="mb-4 flex items-center gap-3">
                    <div class="h-px flex-1 bg-gradient-to-r from-transparent to-gray-200" />
                    <h2 class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-gray-400">
                        <Clock :size="12" />
                        Past Notifications ({{ history.length }})
                    </h2>
                    <div class="h-px flex-1 bg-gradient-to-l from-transparent to-gray-200" />
                </div>

                <div class="space-y-2">
                    <div
                        v-for="n in history"
                        :key="n.id"
                        class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition-all hover:shadow-md"
                    >
                        <!-- Always-visible summary row -->
                        <button
                            type="button"
                            class="flex w-full select-none items-start gap-3 p-4 text-left transition-colors hover:bg-gray-50"
                            @click="toggleHistory(n.id)"
                        >
                            <!-- Type icon (greyed out for history) -->
                            <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-gray-100">
                                <component
                                    :is="cfg(n.type).icon"
                                    :size="15"
                                    class="text-gray-400"
                                />
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="mb-0.5 flex flex-wrap items-center gap-2">
                                    <!-- Type badge — muted for history -->
                                    <span class="inline-block rounded-md bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-500">
                                        {{ cfg(n.type).label }}
                                    </span>
                                    <!-- Disposition pill -->
                                    <span
                                        v-if="n.dismissed_at"
                                        class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-400"
                                    >
                                        Dismissed
                                    </span>
                                    <span
                                        v-else-if="n.is_complete"
                                        class="rounded-full bg-green-100 px-1.5 py-0.5 text-[10px] font-medium text-green-600"
                                    >
                                        Completed
                                    </span>
                                    <span
                                        v-else
                                        class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-400"
                                    >
                                        Expired
                                    </span>
                                </div>
                                <p class="truncate text-sm font-medium text-gray-600">{{ n.title }}</p>
                            </div>

                            <div class="flex flex-shrink-0 items-center gap-2">
                                <span class="text-xs text-gray-400">
                                    {{ formatRelative(n.dismissed_at ?? n.created_at) }}
                                </span>
                                <component
                                    :is="isHistoryExpanded(n.id) ? ChevronUp : ChevronDown"
                                    :size="14"
                                    class="text-gray-400"
                                />
                            </div>
                        </button>

                        <!-- Expandable detail panel -->
                        <Transition name="expand">
                            <div
                                v-if="isHistoryExpanded(n.id)"
                                class="border-t border-gray-100 bg-gray-50/70 px-4 pb-4 pt-3"
                            >
                                <p
                                    v-if="n.message"
                                    class="text-xs leading-relaxed whitespace-pre-line text-gray-500"
                                >
                                    {{ n.message }}
                                </p>
                                <p v-else class="text-xs italic text-gray-400">No message body.</p>

                                <div class="mt-3 flex flex-wrap gap-4 text-xs text-gray-400">
                                    <span v-if="n.due_date" class="flex items-center gap-1">
                                        <CalendarClock :size="11" />
                                        Due: {{ formatDate(n.due_date) }}
                                    </span>
                                    <span v-if="n.start_date" class="flex items-center gap-1">
                                        <Calendar :size="11" />
                                        {{ formatDate(n.start_date) }}
                                        <span v-if="n.end_date"> – {{ formatDate(n.end_date) }}</span>
                                    </span>
                                    <span v-if="n.dismissed_at" class="flex items-center gap-1">
                                        <Clock :size="11" />
                                        Dismissed {{ formatRelative(n.dismissed_at) }}
                                    </span>
                                </div>
                            </div>
                        </Transition>
                    </div>
                </div>
            </section>

        </div>
    </AppLayout>
</template>

<style scoped>
/* ── Notification card enter/leave ──────────────────────────────────── */
.notification-enter-active,
.notification-leave-active {
    transition: all 0.25s ease;
}
.notification-enter-from {
    opacity: 0;
    transform: translateY(-10px);
}
.notification-leave-to {
    opacity: 0;
    transform: translateX(24px);
}
.notification-leave-active {
    position: absolute;
    width: 100%;
}

/* ── History detail panel expand ────────────────────────────────────── */
.expand-enter-active,
.expand-leave-active {
    transition: all 0.2s ease;
    overflow: hidden;
}
.expand-enter-from,
.expand-leave-to {
    opacity: 0;
    max-height: 0;
}
.expand-enter-to,
.expand-leave-from {
    opacity: 1;
    max-height: 300px;
}
</style>