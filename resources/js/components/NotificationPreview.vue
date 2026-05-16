<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { AlertCircle, AlertTriangle, Bell, CalendarClock, CheckCircle2, Clock, Megaphone, Zap } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    title?: string;
    message?: string;
    startDate?: string;
    endDate?: string;
    dueDate?: string;
    targetRole?: string;
    type?: string;
    priority?: string;
    notificationStatus?: string;
    selectedStudentEmail?: string;
    courseFilter?: string[];
    yearLevelFilter?: string[];
}

const props = withDefaults(defineProps<Props>(), {
    title:              'Notification Title',
    message:            'Your message will appear here...',
    startDate:          '',
    endDate:            '',
    dueDate:            '',
    targetRole:         'student',
    type:               'general',
    priority:           'medium',
    notificationStatus: 'draft',
    selectedStudentEmail: '',
    courseFilter:       () => [],
    yearLevelFilter:    () => [],
});

// ── Type config ───────────────────────────────────────────────────────────

const typeConfig = computed(() => {
    const configs: Record<string, {
        icon: any;
        cardClass: string;
        badgeClass: string;
        iconClass: string;
        label: string;
    }> = {
        general: {
            label:     'Announcement',
            icon:      Megaphone,
            cardClass: 'border-blue-200 bg-blue-50',
            badgeClass:'bg-blue-100 text-blue-800',
            iconClass: 'text-blue-500',
        },
        reminder: {
            label:     'Reminder',
            icon:      Bell,
            cardClass: 'border-indigo-200 bg-indigo-50',
            badgeClass:'bg-indigo-100 text-indigo-800',
            iconClass: 'text-indigo-500',
        },
        warning: {
            label:     'Warning',
            icon:      AlertTriangle,
            cardClass: 'border-amber-200 bg-amber-50',
            badgeClass:'bg-amber-100 text-amber-800',
            iconClass: 'text-amber-500',
        },
        deadline: {
            label:     'Deadline',
            icon:      Clock,
            cardClass: 'border-orange-200 bg-orange-50',
            badgeClass:'bg-orange-100 text-orange-800',
            iconClass: 'text-orange-500',
        },
        announcement: {
            label:     'Announcement',
            icon:      Megaphone,
            cardClass: 'border-purple-200 bg-purple-50',
            badgeClass:'bg-purple-100 text-purple-800',
            iconClass: 'text-purple-500',
        },
        payment_due: {
            label:     'Payment Due',
            icon:      CalendarClock,
            cardClass: 'border-amber-200 bg-amber-50',
            badgeClass:'bg-amber-100 text-amber-800',
            iconClass: 'text-amber-500',
        },
        payment_due_notice: {
            label:     'Payment Due Notice',
            icon:      CalendarClock,
            cardClass: 'border-amber-200 bg-amber-50',
            badgeClass:'bg-amber-100 text-amber-800',
            iconClass: 'text-amber-500',
        },
        payment_approved: {
            label:     'Payment Approved',
            icon:      CheckCircle2,
            cardClass: 'border-emerald-200 bg-emerald-50',
            badgeClass:'bg-emerald-100 text-emerald-800',
            iconClass: 'text-emerald-500',
        },
        payment_rejected: {
            label:     'Payment Rejected',
            icon:      AlertCircle,
            cardClass: 'border-red-200 bg-red-50',
            badgeClass:'bg-red-100 text-red-800',
            iconClass: 'text-red-500',
        },
    };
    return configs[props.type ?? 'general'] ?? configs.general;
});

// ── Priority config ───────────────────────────────────────────────────────

const priorityConfig = computed(() => {
    const configs: Record<string, { label: string; ringClass: string; dotClass: string; icon?: any }> = {
        low:    { label: 'Low',    ringClass: 'ring-gray-200',   dotClass: 'bg-gray-400' },
        medium: { label: 'Medium', ringClass: 'ring-blue-300',   dotClass: 'bg-blue-500' },
        high:   { label: 'High',   ringClass: 'ring-amber-400',  dotClass: 'bg-amber-500' },
        urgent: { label: 'Urgent', ringClass: 'ring-red-500',    dotClass: 'bg-red-600', icon: Zap },
    };
    return configs[props.priority ?? 'medium'] ?? configs.medium;
});

// ── Status config ─────────────────────────────────────────────────────────

const statusConfig = computed(() => {
    const configs: Record<string, { label: string; cls: string }> = {
        draft:     { label: 'Draft — not visible to students', cls: 'bg-gray-100 text-gray-500 border border-gray-200' },
        scheduled: { label: 'Scheduled — activates on start date', cls: 'bg-blue-100 text-blue-700 border border-blue-200' },
        active:    { label: 'Active — visible to students', cls: 'bg-green-100 text-green-800 border border-green-200' },
        expired:   { label: 'Expired — no longer shown', cls: 'bg-red-100 text-red-600 border border-red-200' },
    };
    return configs[props.notificationStatus ?? 'draft'] ?? configs.draft;
});

// ── Audience label ────────────────────────────────────────────────────────

const audienceLabel = computed(() => {
    const parts: string[] = [];
    if (props.selectedStudentEmail) return props.selectedStudentEmail;

    const roleLabels: Record<string, string> = {
        student:    'All Students',
        accounting: 'Accounting Staff',
        admin:      'Admins',
        all:        'Everyone',
    };
    parts.push(roleLabels[props.targetRole ?? 'student'] ?? props.targetRole ?? '');

    if (props.courseFilter && props.courseFilter.length > 0) {
        parts.push(`🎓 ${props.courseFilter.join(', ')}`);
    }
    if (props.yearLevelFilter && props.yearLevelFilter.length > 0) {
        parts.push(props.yearLevelFilter.join(', '));
    }

    return parts.join(' • ');
});

// ── Due date urgency ──────────────────────────────────────────────────────

const formatDate = (d: string) => {
    if (!d) return '';
    return new Date(d + 'T12:00:00').toLocaleDateString('en-PH', {
        month: 'short', day: 'numeric', year: 'numeric',
    });
};

const dueDateUrgency = computed(() => {
    if (!props.dueDate) return null;
    const diff = Math.ceil((new Date(props.dueDate).getTime() - Date.now()) / 86_400_000);
    if (diff < 0)   return { cls: 'text-red-700 font-bold', label: `Overdue — was due ${formatDate(props.dueDate)} ⚠️` };
    if (diff === 0) return { cls: 'text-red-700 font-bold', label: `Due TODAY ⚠️` };
    if (diff <= 7)  return { cls: 'text-red-600 font-bold', label: `Due ${formatDate(props.dueDate)} (${diff}d) ⚠️` };
    if (diff <= 14) return { cls: 'text-amber-700 font-semibold', label: `Due ${formatDate(props.dueDate)} (${diff}d)` };
    return { cls: 'text-gray-600', label: `Due ${formatDate(props.dueDate)}` };
});
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="text-sm">📺 Live Preview</CardTitle>
        </CardHeader>
        <CardContent class="space-y-3">

            <!-- Status bar (shown above the simulated card) -->
            <div :class="['rounded-md px-3 py-1.5 text-xs font-medium', statusConfig.cls]">
                {{ statusConfig.label }}
            </div>

            <!-- Simulated student notification card -->
            <div
                :class="[
                    'rounded-xl border-2 p-4 transition-all duration-300 ring-2',
                    typeConfig.cardClass,
                    priorityConfig.ringClass,
                ]"
            >
                <!-- Priority indicator (urgent only gets icon) -->
                <div v-if="priority === 'urgent'" class="mb-2 flex items-center gap-1 text-xs font-bold text-red-700">
                    <Zap class="h-3.5 w-3.5" />
                    URGENT
                </div>
                <div v-else-if="priority === 'high'" class="mb-2 flex items-center gap-1 text-xs font-semibold text-amber-700">
                    <span class="h-2 w-2 rounded-full bg-amber-500 inline-block" />
                    High Priority
                </div>

                <!-- Type badge + icon + title -->
                <div class="mb-3 flex items-start gap-2">
                    <component
                        :is="typeConfig.icon"
                        :size="18"
                        :class="['mt-0.5 shrink-0', typeConfig.iconClass]"
                    />
                    <div class="min-w-0 flex-1">
                        <span
                            :class="[
                                'mb-1 inline-block rounded-md px-2 py-0.5 text-xs font-semibold',
                                typeConfig.badgeClass,
                            ]"
                        >
                            {{ typeConfig.label }}
                        </span>
                        <h4 class="text-sm font-semibold text-gray-900 leading-tight">
                            {{ title || 'Notification Title' }}
                        </h4>
                    </div>
                </div>

                <!-- Message -->
                <p class="pl-6 text-xs leading-relaxed text-gray-700 whitespace-pre-line max-h-28 overflow-y-auto">
                    {{ message || 'Your message will appear here...' }}
                </p>

                <!-- Footer meta -->
                <div class="mt-3 pl-6 space-y-1 border-t border-black/10 pt-2 text-xs text-gray-500">
                    <p v-if="dueDateUrgency" :class="['flex items-center gap-1', dueDateUrgency.cls]">
                        <CalendarClock :size="12" />
                        {{ dueDateUrgency.label }}
                    </p>
                    <p v-if="startDate" class="flex items-center gap-1">
                        📅 From: {{ formatDate(startDate) }}
                        <span v-if="endDate"> — {{ formatDate(endDate) }}</span>
                    </p>
                    <p>
                        <strong>👥 For:</strong> {{ audienceLabel }}
                    </p>
                </div>

                <!-- Simulated dismiss button -->
                <div class="mt-3 pl-6 flex justify-end">
                    <span class="rounded-lg border border-black/15 px-3 py-1 text-xs font-medium text-gray-400 cursor-not-allowed opacity-60">
                        Dismiss
                    </span>
                </div>
            </div>

            <!-- Priority legend -->
            <div class="flex flex-wrap items-center gap-3 rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-500">
                <span class="font-medium text-gray-600">Priority ring:</span>
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-gray-400 ring-1 ring-gray-200 inline-block" /> Low</span>
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-blue-500 ring-1 ring-blue-300 inline-block" /> Medium</span>
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-amber-500 ring-1 ring-amber-400 inline-block" /> High</span>
                <span class="flex items-center gap-1"><span class="h-2 w-2 rounded-full bg-red-600 ring-1 ring-red-500 inline-block" /> Urgent</span>
            </div>

            <p class="text-center text-xs text-gray-400 italic">
                This is how students will see this notification
            </p>
        </CardContent>
    </Card>
</template>