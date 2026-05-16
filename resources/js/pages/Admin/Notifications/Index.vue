<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Bell,
    Calendar,
    CalendarClock,
    ChevronLeft,
    ChevronRight,
    Edit2,
    GraduationCap,
    Plus,
    Trash2,
    Users,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface NotificationItem {
    id: number;
    title: string;
    message: string;
    type?: string;
    priority?: string;
    notification_status?: string;
    target_role: string;
    start_date: string;
    end_date?: string | null;
    due_date?: string | null;
    payment_term_id?: number | null;
    is_active: boolean;
    is_complete: boolean;
    target_term_name?: string | null;
    term_ids?: number[] | null;
    trigger_days_before_due?: number | null;
    user_id?: number | null;
    user_ids?: number[] | null;
    course_filter?: string[] | null;
    year_level_filter?: string[] | null;
    balance_filter?: string | null;
    dismissed_at?: string | null;
    created_at: string;
    updated_at: string;
}

interface PaginatedNotifications {
    data: NotificationItem[];
    links: { url: string | null; label: string; active: boolean }[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
}

interface Props {
    notifications: PaginatedNotifications | NotificationItem[];
}

const props = defineProps<Props>();

// Support both paginated (object with .data) and flat array (legacy)
const notificationsData = computed<NotificationItem[]>(() => {
    if (Array.isArray(props.notifications)) return props.notifications;
    return (props.notifications as PaginatedNotifications).data ?? [];
});

const paginationMeta = computed(() => {
    if (Array.isArray(props.notifications)) return null;
    return (props.notifications as PaginatedNotifications).meta ?? null;
});

const paginationLinks = computed(() => {
    if (Array.isArray(props.notifications)) return [];
    return (props.notifications as PaginatedNotifications).links ?? [];
});

// ── Role detection ─────────────────────────────────────────────────────────
const page        = usePage();
const isAccounting = computed(() => (page.props.auth as any)?.user?.role === 'accounting');

const breadcrumbs = computed(() => {
    if (isAccounting.value) {
        return [
            { title: 'Accounting', href: route('accounting.dashboard') },
            { title: 'Notifications', href: route('accounting.notifications.index') },
        ];
    }
    return [
        { title: 'Admin', href: route('admin.dashboard') },
        { title: 'Notifications', href: route('admin.notifications.index') },
    ];
});

// ── Filters ────────────────────────────────────────────────────────────────
const searchQuery = ref('');
type FilterTab = 'all' | 'active' | 'draft' | 'scheduled' | 'expired' | 'payment_due';
const activeTab   = ref<FilterTab>('all');

// ── Delete ─────────────────────────────────────────────────────────────────
const pendingDeleteId = ref<number | null>(null);

const requestDelete = (id: number)  => { pendingDeleteId.value = id; };
const cancelDelete  = ()            => { pendingDeleteId.value = null; };

const confirmDelete = (id: number) => {
    pendingDeleteId.value = null;
    router.delete(route('accounting.notifications.destroy', id));
};

// ── System-generated heuristic ────────────────────────────────────────────
const SYSTEM_TYPES = new Set(['payment_approved', 'payment_rejected']);

const isSystemGenerated = (n: NotificationItem): boolean =>
    SYSTEM_TYPES.has(n.type ?? '') &&
    (n.message?.startsWith('Your payment') ||
     n.message?.startsWith('Payment of') ||
     !n.message);

// ── Status logic ───────────────────────────────────────────────────────────
const todayStr = new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD

const deriveStatus = (n: NotificationItem): string => {
    // Use the DB column if available (Phase 2)
    if (n.notification_status) return n.notification_status;
    // Fallback: derive from legacy booleans
    if (n.is_complete) return 'expired';
    if (!n.is_active)  return 'draft';
    const start = n.start_date?.split('T')[0] ?? '';
    const end   = n.end_date?.split('T')[0]   ?? null;
    if (start > todayStr) return 'scheduled';
    if (end !== null && end < todayStr) return 'expired';
    return 'active';
};

// ── Filtered list ──────────────────────────────────────────────────────────
const filtered = computed(() => {
    let list = notificationsData.value;

    if (activeTab.value !== 'all') {
        if (activeTab.value === 'payment_due') {
            list = list.filter((n) => n.type === 'payment_due');
        } else {
            list = list.filter((n) => deriveStatus(n) === activeTab.value);
        }
    }

    if (searchQuery.value) {
        const q = searchQuery.value.toLowerCase();
        list = list.filter(
            (n) =>
                n.title.toLowerCase().includes(q) ||
                n.message?.toLowerCase().includes(q) ||
                (n.target_term_name ?? '').toLowerCase().includes(q) ||
                (n.course_filter ?? []).some((c) => c.toLowerCase().includes(q)) ||
                (n.year_level_filter ?? []).some((y) => y.toLowerCase().includes(q)),
        );
    }

    return list;
});

const adminCreated    = computed(() => filtered.value.filter((n) => !isSystemGenerated(n)));
const systemGenerated = computed(() => filtered.value.filter((n) => isSystemGenerated(n)));

const tabCounts = computed(() => {
    const all = notificationsData.value;
    return {
        all:         all.filter((n) => !isSystemGenerated(n)).length,
        active:      all.filter((n) => deriveStatus(n) === 'active').length,
        draft:       all.filter((n) => deriveStatus(n) === 'draft').length,
        scheduled:   all.filter((n) => deriveStatus(n) === 'scheduled').length,
        expired:     all.filter((n) => deriveStatus(n) === 'expired').length,
        payment_due: all.filter((n) => n.type === 'payment_due').length,
    };
});

// ── Display helpers ────────────────────────────────────────────────────────

// Priority: left-border color coding
const getPriorityBorderClass = (priority?: string): string => {
    const map: Record<string, string> = {
        urgent: 'border-l-4 border-l-red-500',
        high:   'border-l-4 border-l-amber-400',
        medium: 'border-l-4 border-l-blue-400',
        low:    'border-l-4 border-l-gray-300',
    };
    return map[priority ?? 'medium'] ?? '';
};

// Status badge
const getStatusBadge = (n: NotificationItem): { label: string; cls: string } => {
    const status = deriveStatus(n);
    const map: Record<string, { label: string; cls: string }> = {
        active:    { label: '● Active',    cls: 'bg-green-100 text-green-800' },
        scheduled: { label: '🗓 Scheduled', cls: 'bg-blue-100 text-blue-800' },
        draft:     { label: '○ Draft',     cls: 'bg-gray-100 text-gray-600' },
        expired:   { label: '✕ Expired',   cls: 'bg-red-100 text-red-700' },
    };
    if (n.is_complete && status !== 'expired') {
        return { label: '✓ Completed', cls: 'bg-gray-200 text-gray-600' };
    }
    return map[status] ?? { label: status, cls: 'bg-gray-100 text-gray-600' };
};

// Priority badge
const getPriorityBadge = (priority?: string): { label: string; cls: string } | null => {
    if (!priority || priority === 'medium') return null;
    const map: Record<string, { label: string; cls: string }> = {
        urgent: { label: '🔴 Urgent', cls: 'bg-red-100 text-red-800 ring-1 ring-red-200' },
        high:   { label: '🟠 High',   cls: 'bg-amber-100 text-amber-800' },
        low:    { label: 'Low',       cls: 'bg-gray-100 text-gray-600' },
    };
    return map[priority] ?? null;
};

const getRoleColor = (role: string) => {
    const colors: Record<string, string> = {
        student:    'bg-blue-100 text-blue-800',
        accounting: 'bg-purple-100 text-purple-800',
        admin:      'bg-indigo-100 text-indigo-800',
        all:        'bg-gray-100 text-gray-800',
    };
    return colors[role] || 'bg-gray-100 text-gray-800';
};

const getTypeLabel = (type?: string) => {
    const labels: Record<string, string> = {
        general:           '📢 General',
        reminder:          '⏰ Reminder',
        warning:           '⚠️ Warning',
        deadline:          '⏳ Deadline',
        announcement:      '📣 Announcement',
        payment_due:       '💳 Payment Due',
        payment_due_notice:'💳 Payment Due Notice',
        payment_approved:  '✅ Approved',
        payment_rejected:  '❌ Rejected',
    };
    return labels[type ?? 'general'] || type || 'General';
};

const getTypeColor = (type?: string) => {
    const colors: Record<string, string> = {
        general:           'bg-blue-100 text-blue-800',
        reminder:          'bg-sky-100 text-sky-800',
        warning:           'bg-orange-100 text-orange-800',
        deadline:          'bg-red-100 text-red-800',
        announcement:      'bg-violet-100 text-violet-800',
        payment_due:       'bg-amber-100 text-amber-800',
        payment_due_notice:'bg-amber-100 text-amber-800',
        payment_approved:  'bg-emerald-100 text-emerald-800',
        payment_rejected:  'bg-red-100 text-red-800',
    };
    return colors[type ?? 'general'] || 'bg-gray-100 text-gray-800';
};

const getDueDateChipClass = (dueDateStr: string | null | undefined): string => {
    if (!dueDateStr) return 'bg-gray-100 text-gray-700';
    const diffDays = Math.ceil((new Date(dueDateStr).getTime() - Date.now()) / 86_400_000);
    if (diffDays <= 7)  return 'bg-red-100 text-red-700 ring-1 ring-red-200';
    if (diffDays <= 14) return 'bg-amber-100 text-amber-700 ring-1 ring-amber-200';
    return 'bg-green-100 text-green-700 ring-1 ring-green-200';
};

const formatDate = (dateStr: string | null | undefined): string => {
    if (!dateStr) return '';
    const d = dateStr.split('T')[0];
    return new Date(d + 'T12:00:00').toLocaleDateString('en-US', {
        month: 'short', day: 'numeric', year: 'numeric',
    });
};

const recipientLabel = (n: NotificationItem): string => {
    if (n.user_ids?.length) return `👥 ${n.user_ids.length} specific student${n.user_ids.length !== 1 ? 's' : ''}`;
    if (n.user_id)          return '👤 Personal';
    return '';
};

const audienceChips = (n: NotificationItem): string[] => {
    const chips: string[] = [];
    if (n.course_filter?.length) {
        chips.push(`🎓 ${n.course_filter.slice(0, 2).join(', ')}${n.course_filter.length > 2 ? ` +${n.course_filter.length - 2}` : ''}`);
    }
    if (n.year_level_filter?.length) {
        chips.push(`📅 ${n.year_level_filter.join(', ')}`);
    }
    if (n.balance_filter && n.balance_filter !== 'any') {
        chips.push(n.balance_filter === 'with_balance' ? '💰 With Balance' : '⚠️ Overdue Only');
    }
    return chips;
};

// Pagination
const goToPage = (url: string | null) => {
    if (!url) return;
    router.visit(url, { preserveScroll: true });
};
</script>

<template>
    <Head title="Payment Notifications" />

    <AppLayout>
        <div class="w-full p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Header -->
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <h1 class="mb-1 text-3xl font-bold text-gray-900">Payment Notifications</h1>
                    <p class="text-gray-500 text-sm">
                        {{ isAccounting
                            ? 'Create and manage notifications for students'
                            : 'View all system notifications (read-only)' }}
                    </p>
                </div>

                <template v-if="isAccounting">
                    <Link :href="route('accounting.notifications.create')">
                        <Button>
                            <Plus class="mr-2 h-4 w-4" />
                            Create Notification
                        </Button>
                    </Link>
                </template>
                <template v-else>
                    <span class="inline-flex items-center rounded-full bg-purple-100 px-3 py-1 text-xs font-medium text-purple-700">
                        Read-only
                    </span>
                </template>
            </div>

            <!-- Filters -->
            <div class="mb-6 space-y-3">
                <!-- Tab bar -->
                <div class="flex w-fit flex-wrap gap-1 rounded-xl bg-gray-100 p-1">
                    <button
                        v-for="tab in ([
                            { key: 'all',         label: 'All' },
                            { key: 'active',      label: '● Active' },
                            { key: 'scheduled',   label: '🗓 Scheduled' },
                            { key: 'draft',       label: '○ Draft' },
                            { key: 'expired',     label: '✕ Expired' },
                            { key: 'payment_due', label: '💳 Payment Due' },
                        ] as { key: FilterTab; label: string }[])"
                        :key="tab.key"
                        type="button"
                        @click="activeTab = tab.key"
                        :class="[
                            'rounded-lg px-3 py-1.5 text-sm font-medium transition-all',
                            activeTab === tab.key
                                ? 'bg-white shadow text-gray-900'
                                : 'text-gray-500 hover:text-gray-700',
                        ]"
                    >
                        {{ tab.label }}
                        <span class="ml-1 rounded-full bg-gray-200 px-1.5 py-0.5 text-xs font-semibold text-gray-700">
                            {{ tabCounts[tab.key] }}
                        </span>
                    </button>
                </div>

                <!-- Search -->
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search by title, message, term, course, or year level…"
                    class="w-full rounded-lg border border-gray-300 px-4 py-3 focus:border-transparent focus:ring-2 focus:ring-blue-500"
                />
            </div>

            <!-- Pagination summary -->
            <div v-if="paginationMeta && paginationMeta.total > 0" class="mb-4 text-sm text-gray-500">
                Showing {{ paginationMeta.from }}–{{ paginationMeta.to }} of {{ paginationMeta.total }} notifications
            </div>

            <!-- Empty state -->
            <div v-if="filtered.length === 0" class="py-16 text-center">
                <Bell class="mx-auto mb-4 h-12 w-12 text-gray-300" />
                <h3 class="mb-2 text-lg font-semibold text-gray-700">No notifications found</h3>
                <p class="mb-4 text-gray-500 text-sm">
                    {{
                        searchQuery || activeTab !== 'all'
                            ? 'Try adjusting your search or filter'
                            : isAccounting
                              ? 'Create your first notification to get started'
                              : 'No notifications have been created yet'
                    }}
                </p>
                <Link v-if="isAccounting && !searchQuery && activeTab === 'all'" :href="route('accounting.notifications.create')">
                    <Button variant="outline">
                        <Plus class="mr-2 h-4 w-4" />
                        Create First Notification
                    </Button>
                </Link>
            </div>

            <div v-else class="space-y-10">

                <!-- ── Admin-Created Notifications ──────────────────────────── -->
                <section>
                    <div class="mb-4 flex items-center gap-3">
                        <div class="h-px flex-1 bg-gray-200" />
                        <h2 class="text-xs font-semibold uppercase tracking-widest text-gray-400">
                            📋 Notifications
                        </h2>
                        <div class="h-px flex-1 bg-gray-200" />
                    </div>

                    <div v-if="adminCreated.length === 0" class="py-8 text-center text-sm text-gray-400">
                        No notifications match the current filter.
                    </div>

                    <div v-else class="space-y-3">
                        <Card
                            v-for="notification in adminCreated"
                            :key="notification.id"
                            :class="['transition-all duration-200 overflow-hidden', getPriorityBorderClass(notification.priority)]"
                        >
                            <CardContent class="pt-5">
                                <!-- Title row + badges -->
                                <div class="mb-3 flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2 mb-1">
                                            <!-- Status badge -->
                                            <span
                                                :class="[
                                                    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                                                    getStatusBadge(notification).cls,
                                                ]"
                                            >
                                                {{ getStatusBadge(notification).label }}
                                            </span>

                                            <!-- Priority badge (only for high/urgent) -->
                                            <span
                                                v-if="getPriorityBadge(notification.priority)"
                                                :class="[
                                                    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                                                    getPriorityBadge(notification.priority)!.cls,
                                                ]"
                                            >
                                                {{ getPriorityBadge(notification.priority)!.label }}
                                            </span>

                                            <!-- Recipient badge -->
                                            <span
                                                v-if="recipientLabel(notification)"
                                                class="inline-flex items-center rounded-full bg-purple-100 px-2.5 py-0.5 text-xs font-medium text-purple-800"
                                            >
                                                {{ recipientLabel(notification) }}
                                            </span>
                                        </div>

                                        <h3 class="text-base font-semibold text-gray-900 leading-snug">
                                            {{ notification.title }}
                                        </h3>
                                    </div>

                                    <!-- Action buttons -->
                                    <div v-if="isAccounting" class="flex shrink-0 gap-1.5">
                                        <template v-if="pendingDeleteId === notification.id">
                                            <Button variant="outline" size="sm" @click="cancelDelete">
                                                Cancel
                                            </Button>
                                            <Button
                                                size="sm"
                                                class="bg-red-600 text-white hover:bg-red-700"
                                                @click="confirmDelete(notification.id)"
                                            >
                                                <Trash2 class="mr-1 h-3.5 w-3.5" />
                                                Delete
                                            </Button>
                                        </template>
                                        <template v-else>
                                            <Link :href="route('accounting.notifications.edit', notification.id)" as="button">
                                                <Button variant="outline" size="sm">
                                                    <Edit2 class="mr-1.5 h-3.5 w-3.5" />
                                                    Edit
                                                </Button>
                                            </Link>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                class="text-red-500 hover:bg-red-50 hover:text-red-600"
                                                @click="requestDelete(notification.id)"
                                            >
                                                <Trash2 class="h-3.5 w-3.5" />
                                            </Button>
                                        </template>
                                    </div>
                                </div>

                                <!-- Message preview -->
                                <p
                                    v-if="notification.message"
                                    class="mb-4 text-sm leading-relaxed text-gray-600 line-clamp-2"
                                >
                                    {{ notification.message }}
                                </p>

                                <!-- Metadata chips -->
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <!-- Role -->
                                    <span
                                        :class="[
                                            'inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-medium',
                                            getRoleColor(notification.target_role),
                                        ]"
                                    >
                                        <Users class="h-3 w-3" />
                                        {{
                                            notification.target_role.charAt(0).toUpperCase() +
                                            notification.target_role.slice(1)
                                        }}
                                    </span>

                                    <!-- Type -->
                                    <span
                                        v-if="notification.type"
                                        :class="['rounded-full px-2.5 py-1 font-medium', getTypeColor(notification.type)]"
                                    >
                                        {{ getTypeLabel(notification.type) }}
                                    </span>

                                    <!-- Term name -->
                                    <span
                                        v-if="notification.target_term_name"
                                        class="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2.5 py-1 font-medium text-indigo-800"
                                    >
                                        <GraduationCap class="h-3 w-3" />
                                        {{ notification.target_term_name }} only
                                    </span>
                                    <span
                                        v-else-if="notification.term_ids && notification.term_ids.length > 0"
                                        class="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2.5 py-1 font-medium text-indigo-800"
                                    >
                                        <GraduationCap class="h-3 w-3" />
                                        {{ notification.term_ids.length }} specific term(s)
                                    </span>

                                    <!-- Audience filter chips (Phase 2) -->
                                    <span
                                        v-for="chip in audienceChips(notification)"
                                        :key="chip"
                                        class="rounded-full bg-teal-100 px-2.5 py-1 font-medium text-teal-800"
                                    >
                                        {{ chip }}
                                    </span>

                                    <!-- Due date -->
                                    <span
                                        v-if="notification.due_date"
                                        :class="[
                                            'inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-medium',
                                            getDueDateChipClass(notification.due_date),
                                        ]"
                                    >
                                        <CalendarClock class="h-3 w-3" />
                                        Due: {{ formatDate(notification.due_date) }}
                                    </span>

                                    <!-- Date range -->
                                    <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 px-2.5 py-1 font-medium text-gray-600">
                                        <Calendar class="h-3 w-3" />
                                        {{ formatDate(notification.start_date) }}
                                        <span v-if="notification.end_date"> → {{ formatDate(notification.end_date) }}</span>
                                        <span v-else>→ ongoing</span>
                                    </span>

                                    <!-- Trigger days -->
                                    <span
                                        v-if="notification.trigger_days_before_due"
                                        class="rounded-full bg-yellow-100 px-2.5 py-1 font-medium text-yellow-800"
                                    >
                                        ⏱ {{ notification.trigger_days_before_due }}d before due
                                    </span>

                                    <span class="ml-auto text-gray-400 text-xs">
                                        Created {{ formatDate(notification.created_at) }}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </section>

                <!-- ── System-Generated ─────────────────────────────────────── -->
                <section v-if="systemGenerated.length > 0">
                    <div class="mb-4 flex items-center gap-3">
                        <div class="h-px flex-1 bg-gray-200" />
                        <h2 class="text-xs font-semibold uppercase tracking-widest text-gray-400">
                            ⚡ System-Generated
                        </h2>
                        <div class="h-px flex-1 bg-gray-200" />
                    </div>

                    <p class="mb-4 text-xs text-gray-400">
                        Automatically generated by payment events. They cannot be edited.
                    </p>

                    <div class="space-y-2">
                        <Card
                            v-for="notification in systemGenerated"
                            :key="notification.id"
                            class="border-dashed opacity-90"
                        >
                            <CardContent class="py-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0 flex-1">
                                        <div class="mb-1 flex flex-wrap items-center gap-2">
                                            <span
                                                :class="[
                                                    'rounded-full px-2.5 py-0.5 text-xs font-medium',
                                                    getStatusBadge(notification).cls,
                                                ]"
                                            >
                                                {{ getStatusBadge(notification).label }}
                                            </span>
                                            <span
                                                v-if="notification.user_id"
                                                class="rounded-full bg-purple-100 px-2.5 py-0.5 text-xs font-medium text-purple-800"
                                            >👤 Personal</span>
                                            <span
                                                v-if="notification.type"
                                                :class="['rounded-full px-2.5 py-0.5 text-xs font-medium', getTypeColor(notification.type)]"
                                            >
                                                {{ getTypeLabel(notification.type) }}
                                            </span>
                                        </div>
                                        <p class="text-sm font-semibold text-gray-900">{{ notification.title }}</p>
                                        <p v-if="notification.message" class="mt-0.5 line-clamp-1 text-xs text-gray-500">
                                            {{ notification.message }}
                                        </p>
                                    </div>

                                    <div class="flex shrink-0 flex-col items-end gap-2">
                                        <span class="text-xs text-gray-400">{{ formatDate(notification.created_at) }}</span>
                                        <template v-if="isAccounting">
                                            <template v-if="pendingDeleteId === notification.id">
                                                <div class="flex gap-1">
                                                    <Button variant="outline" size="sm" class="h-7 text-xs" @click="cancelDelete">Cancel</Button>
                                                    <Button
                                                        size="sm"
                                                        class="h-7 bg-red-600 text-xs text-white hover:bg-red-700"
                                                        @click="confirmDelete(notification.id)"
                                                    >Confirm</Button>
                                                </div>
                                            </template>
                                            <template v-else>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    class="h-7 text-red-400 hover:text-red-600"
                                                    @click="requestDelete(notification.id)"
                                                >
                                                    <Trash2 class="h-3.5 w-3.5" />
                                                </Button>
                                            </template>
                                        </template>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </section>
            </div>

            <!-- Pagination -->
            <div
                v-if="paginationMeta && paginationMeta.last_page > 1"
                class="mt-8 flex items-center justify-center gap-1"
            >
                <button
                    v-for="link in paginationLinks"
                    :key="link.label"
                    :disabled="!link.url"
                    @click="goToPage(link.url)"
                    :class="[
                        'flex h-9 min-w-[2.25rem] items-center justify-center rounded-lg px-3 text-sm font-medium transition-colors',
                        link.active
                            ? 'bg-blue-600 text-white'
                            : link.url
                              ? 'border border-gray-300 text-gray-700 hover:bg-gray-50'
                              : 'border border-gray-200 text-gray-300 cursor-not-allowed',
                    ]"
                    v-html="link.label.replace('&laquo;', '‹').replace('&raquo;', '›')"
                />
            </div>
        </div>
    </AppLayout>
</template>