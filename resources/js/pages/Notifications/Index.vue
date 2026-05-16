<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    AlertCircle, Bell, BellOff, Calendar,
    CalendarClock, CheckCircle2, ChevronDown, ChevronUp, Clock, Megaphone,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

// FIX #3: Expanded type list to include all types the backend can produce.
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

const props = defineProps<{
    active:  Notification[];
    history: Notification[];
}>();

// ── Optimistic dismiss ────────────────────────────────────────────────────
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

function markAllRead() {
    router.post(route('student.notifications.mark-all-read'), {}, { preserveScroll: true });
}

// ── History expand/collapse ───────────────────────────────────────────────
const expandedHistory = ref<Set<number>>(new Set());

const toggleHistory = (id: number) => {
    const s = new Set(expandedHistory.value);
    if (s.has(id)) s.delete(id);
    else s.add(id);
    expandedHistory.value = s;
};

const isHistoryExpanded = (id: number) => expandedHistory.value.has(id);

// ── Visible active list (filter out optimistic dismissals) ────────────────
const visibleActive = computed(() =>
    props.active.filter((n) => !locallyDismissed.value.has(n.id)),
);

// ── Formatting ────────────────────────────────────────────────────────────
const formatDate = (date: string | null) => {
    if (!date) return '';
    return new Date(date + 'T12:00:00').toLocaleDateString('en-PH', {
        month: 'long', day: 'numeric', year: 'numeric',
    });
};

const formatRelative = (datetimeStr: string | null): string => {
    if (!datetimeStr) return '';
    const d       = new Date(datetimeStr);
    const now     = new Date();
    const diffMs  = now.getTime() - d.getTime();
    const diffDays = Math.floor(diffMs / 86_400_000);
    if (diffDays === 0) return 'Today';
    if (diffDays === 1) return 'Yesterday';
    if (diffDays < 7)  return `${diffDays} days ago`;
    return formatDate(datetimeStr.split('T')[0]);
};

const dueDaysLabel = (dueDateStr: string | null): string | null => {
    if (!dueDateStr) return null;
    const diff = Math.ceil((new Date(dueDateStr).getTime() - Date.now()) / 86_400_000);
    if (diff < 0)  return `Overdue by ${Math.abs(diff)} day${Math.abs(diff) !== 1 ? 's' : ''}`;
    if (diff === 0) return 'Due today';
    if (diff === 1) return 'Due tomorrow';
    return `Due in ${diff} day${diff !== 1 ? 's' : ''}`;
};

const dueDaysClass = (dueDateStr: string | null): string => {
    if (!dueDateStr) return '';
    const diff = Math.ceil((new Date(dueDateStr).getTime() - Date.now()) / 86_400_000);
    if (diff <= 0)  return 'text-red-700 font-bold';
    if (diff <= 7)  return 'text-red-600 font-semibold';
    if (diff <= 14) return 'text-amber-600 font-semibold';
    return 'text-gray-500';
};

// ── Type config ───────────────────────────────────────────────────────────
// FIX #3: All notification types the backend can produce now have an entry.
// Previously only 4 of 9 types were defined; payment_due_notice, deadline,
// warning, reminder, and announcement all fell through to the generic blue
// "Announcement" card, losing their visual urgency and hiding the due-date
// banner and Pay Now button.
const typeConfig: Record<string, {
    label: string;
    icon: any;
    cardClass: string;
    badgeClass: string;
    iconClass: string;
}> = {
    payment_due: {
        label:     'Payment Due',
        icon:      CalendarClock,
        cardClass: 'border-amber-200 bg-amber-50',
        badgeClass:'bg-amber-100 text-amber-800',
        iconClass: 'text-amber-600',
    },
    payment_due_notice: {
        label:     'Payment Notice',
        icon:      CalendarClock,
        cardClass: 'border-amber-200 bg-amber-50',
        badgeClass:'bg-amber-100 text-amber-800',
        iconClass: 'text-amber-600',
    },
    deadline: {
        label:     'Deadline',
        icon:      AlertCircle,
        cardClass: 'border-red-200 bg-red-50',
        badgeClass:'bg-red-100 text-red-800',
        iconClass: 'text-red-600',
    },
    warning: {
        label:     'Warning',
        icon:      AlertCircle,
        cardClass: 'border-orange-200 bg-orange-50',
        badgeClass:'bg-orange-100 text-orange-800',
        iconClass: 'text-orange-600',
    },
    reminder: {
        label:     'Reminder',
        icon:      Bell,
        cardClass: 'border-blue-200 bg-blue-50',
        badgeClass:'bg-blue-100 text-blue-800',
        iconClass: 'text-blue-600',
    },
    announcement: {
        label:     'Announcement',
        icon:      Megaphone,
        cardClass: 'border-blue-200 bg-blue-50',
        badgeClass:'bg-blue-100 text-blue-800',
        iconClass: 'text-blue-600',
    },
    payment_approved: {
        label:     'Payment Approved',
        icon:      CheckCircle2,
        cardClass: 'border-emerald-200 bg-emerald-50',
        badgeClass:'bg-emerald-100 text-emerald-800',
        iconClass: 'text-emerald-600',
    },
    payment_rejected: {
        label:     'Payment Rejected',
        icon:      AlertCircle,
        cardClass: 'border-red-200 bg-red-50',
        badgeClass:'bg-red-100 text-red-800',
        iconClass: 'text-red-600',
    },
    general: {
        label:     'Announcement',
        icon:      Megaphone,
        cardClass: 'border-blue-200 bg-blue-50',
        badgeClass:'bg-blue-100 text-blue-800',
        iconClass: 'text-blue-600',
    },
};

function cfg(type: NotificationType) {
    return typeConfig[type ?? 'general'] ?? typeConfig.general;
}

// FIX #3: All types that carry a due date — used for the urgency banner and
// "Pay Now" button guards in the template. Previously both guards were hard-
// coded to check `=== 'payment_due'` only, hiding the countdown and button
// for payment_due_notice and deadline notifications.
const DUE_DATE_TYPES: NotificationType[] = ['payment_due', 'payment_due_notice', 'deadline'];

function hasDueDate(type: NotificationType): boolean {
    return DUE_DATE_TYPES.includes(type);
}

const totalCount = computed(() => props.active.length + props.history.length);
const hasUnread  = computed(() => visibleActive.value.length > 0);
</script>

<template>
    <AppLayout>
        <Head title="Notifications" />

        <div class="mx-auto max-w-3xl p-6">
            <!-- Header -->
            <div class="mb-6 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="rounded-xl bg-blue-100 p-3">
                        <Bell :size="24" class="text-blue-600" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Notifications</h1>
                        <p class="text-sm text-gray-500">
                            {{ totalCount }} notification{{ totalCount !== 1 ? 's' : '' }}
                        </p>
                    </div>
                </div>

                <button
                    v-if="hasUnread"
                    @click="markAllRead"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50"
                >
                    ✓ Mark all read
                </button>
            </div>

            <!-- ── ACTIVE ─────────────────────────────────────────────────── -->
            <section class="mb-8">
                <TransitionGroup
                    name="notification"
                    tag="div"
                    class="space-y-3"
                >
                    <div
                        v-for="n in visibleActive"
                        :key="n.id"
                        :class="['rounded-xl border p-5 transition-all', cfg(n.type).cardClass]"
                    >
                        <!-- Type badge + title -->
                        <div class="mb-3 flex items-start gap-3">
                            <component
                                :is="cfg(n.type).icon"
                                :size="20"
                                :class="['mt-0.5 shrink-0', cfg(n.type).iconClass]"
                            />
                            <div class="min-w-0 flex-1">
                                <span
                                    :class="[
                                        'mb-1 inline-block rounded-md px-2 py-0.5 text-xs font-semibold',
                                        cfg(n.type).badgeClass,
                                    ]"
                                >
                                    {{ cfg(n.type).label }}
                                </span>
                                <h3 class="text-base font-semibold text-gray-900">{{ n.title }}</h3>
                            </div>
                        </div>

                        <!-- Message -->
                        <p
                            v-if="n.message"
                            class="mb-4 pl-8 text-sm leading-relaxed whitespace-pre-line text-gray-700"
                        >
                            {{ n.message }}
                        </p>

                        <!-- Due date urgency banner -->
                        <!-- FIX #3: was n.type === 'payment_due' — now covers all due-date types -->
                        <div
                            v-if="n.due_date && hasDueDate(n.type)"
                            class="mb-4 pl-8"
                        >
                            <span :class="['text-sm flex items-center gap-1.5', dueDaysClass(n.due_date)]">
                                <CalendarClock :size="14" />
                                {{ dueDaysLabel(n.due_date) }} — {{ formatDate(n.due_date) }}
                            </span>
                        </div>

                        <!-- Footer row -->
                        <div
                            class="flex flex-wrap items-center justify-between gap-2 border-t border-black/10 pt-3 pl-8"
                        >
                            <div class="flex flex-wrap gap-4 text-xs text-gray-500">
                                <span v-if="n.start_date" class="flex items-center gap-1">
                                    <Calendar :size="13" />
                                    {{ formatDate(n.start_date) }}
                                    <span v-if="n.end_date"> — {{ formatDate(n.end_date) }}</span>
                                </span>
                            </div>

                            <div class="flex items-center gap-2">
                                <!-- FIX #3: was n.type === 'payment_due' — now covers all due-date types -->
                                <a
                                    v-if="hasDueDate(n.type) && n.payment_term_id"
                                    :href="route('student.account')"
                                    class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700"
                                >
                                    Pay Now
                                </a>
                                <button
                                    @click="dismiss(n.id)"
                                    :disabled="dismissForm.processing"
                                    class="rounded-lg border border-black/15 px-3 py-1.5 text-xs font-medium text-gray-600 transition hover:bg-white/60 disabled:opacity-50"
                                >
                                    Dismiss
                                </button>
                            </div>
                        </div>
                    </div>
                </TransitionGroup>

                <!-- No active notifications -->
                <div
                    v-if="visibleActive.length === 0 && history.length === 0"
                    class="py-20 text-center"
                >
                    <BellOff :size="56" class="mx-auto mb-4 text-gray-300" />
                    <p class="text-lg font-medium text-gray-500">You're all caught up</p>
                    <p class="mt-1 text-sm text-gray-400">No active notifications at the moment.</p>
                </div>

                <div
                    v-else-if="visibleActive.length === 0"
                    class="rounded-xl border border-dashed border-gray-200 py-8 text-center text-sm text-gray-400"
                >
                    <Bell :size="28" class="mx-auto mb-2 text-gray-300" />
                    No active notifications — check your history below.
                </div>
            </section>

            <!-- ── HISTORY ─────────────────────────────────────────────────── -->
            <section v-if="history.length">
                <div class="mb-4 flex items-center gap-3">
                    <div class="h-px flex-1 bg-gray-200" />
                    <h2 class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-gray-400">
                        <Clock :size="12" />
                        Past Notifications
                    </h2>
                    <div class="h-px flex-1 bg-gray-200" />
                </div>

                <div class="space-y-2">
                    <div
                        v-for="n in history"
                        :key="n.id"
                        class="rounded-xl border border-gray-200 bg-gray-50 overflow-hidden"
                    >
                        <!-- Always-visible summary row -->
                        <button
                            type="button"
                            class="w-full text-left p-4 flex items-start gap-3 hover:bg-gray-100 transition-colors"
                            @click="toggleHistory(n.id)"
                        >
                            <component
                                :is="cfg(n.type).icon"
                                :size="16"
                                class="mt-0.5 shrink-0 text-gray-400"
                            />
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap mb-0.5">
                                    <span class="inline-block rounded-md bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-600">
                                        {{ cfg(n.type).label }}
                                    </span>
                                    <span v-if="n.dismissed_at" class="text-xs text-gray-400">dismissed</span>
                                    <span v-else-if="n.is_complete" class="text-xs text-gray-400">completed</span>
                                    <span v-else class="text-xs text-gray-400">expired</span>
                                </div>
                                <p class="text-sm font-medium text-gray-600">{{ n.title }}</p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
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
                        <div
                            v-if="isHistoryExpanded(n.id)"
                            class="border-t border-gray-200 bg-white px-4 pb-4 pt-3"
                        >
                            <p
                                v-if="n.message"
                                class="text-xs leading-relaxed text-gray-500 whitespace-pre-line"
                            >
                                {{ n.message }}
                            </p>
                            <p v-else class="text-xs text-gray-400 italic">No message body.</p>

                            <div class="mt-2 flex flex-wrap gap-3 text-xs text-gray-400">
                                <span v-if="n.due_date" class="flex items-center gap-1">
                                    <CalendarClock :size="11" />
                                    Due: {{ formatDate(n.due_date) }}
                                </span>
                                <span v-if="n.start_date" class="flex items-center gap-1">
                                    <Calendar :size="11" />
                                    {{ formatDate(n.start_date) }}
                                    <span v-if="n.end_date"> — {{ formatDate(n.end_date) }}</span>
                                </span>
                                <span v-if="n.dismissed_at">
                                    Dismissed: {{ formatRelative(n.dismissed_at) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>

<style scoped>
.notification-enter-active,
.notification-leave-active {
    transition: all 0.3s ease;
}
.notification-enter-from {
    opacity: 0;
    transform: translateY(-8px);
}
.notification-leave-to {
    opacity: 0;
    transform: translateX(20px);
    max-height: 0;
}
.notification-leave-active {
    overflow: hidden;
}
</style>