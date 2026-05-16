<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import NotificationPreview from '@/components/NotificationPreview.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { CheckSquare, Square, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

// ─────────────────────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────────────────────

interface Student {
    id: number;
    name: string;
    email: string;
}

interface PaymentTerm {
    id: number;
    term_name: string;
    term_order: number;
}

interface NotificationProp {
    id: number;
    title: string;
    message: string;
    type?: string;
    priority?: string;
    notification_status?: string;
    target_role: string;
    start_date: string;
    end_date: string;
    due_date?: string | null;
    payment_term_id?: number | null;
    user_id?: number | null;
    user_ids?: number[] | null;
    is_active: boolean;
    term_ids?: number[] | null;
    target_term_name?: string | null;
    trigger_days_before_due?: number | null;
    course_filter?: string[] | null;
    year_level_filter?: string[] | null;
    balance_filter?: string | null;
}

interface Props {
    notification?: NotificationProp;
    students?: Student[];
    paymentTerms?: PaymentTerm[];
    courses?: string[];
    yearLevels?: string[];
}

const props = withDefaults(defineProps<Props>(), {
    notification: undefined,
    students:     () => [],
    paymentTerms: () => [],
    courses:      () => [],
    yearLevels:   () => [],
});

// ─────────────────────────────────────────────────────────────────────────────
// Role detection (Bug #3 fix — breadcrumbs must use accounting routes)
// ─────────────────────────────────────────────────────────────────────────────

const page         = usePage();
const isAccounting = computed(() => (page.props.auth as any)?.user?.role === 'accounting');
const isEditing    = computed(() => !!props.notification?.id);

const breadcrumbs = computed(() => {
    if (isAccounting.value) {
        return [
            { title: 'Accounting', href: route('accounting.dashboard') },
            { title: 'Notifications', href: route('accounting.notifications.index') },
            { title: isEditing.value ? 'Edit Notification' : 'Create Notification', href: '#' },
        ];
    }
    return [
        { title: 'Admin', href: route('admin.dashboard') },
        { title: 'Notifications', href: route('admin.notifications.index') },
        { title: isEditing.value ? 'Edit Notification' : 'Create Notification', href: '#' },
    ];
});

const backHref = computed(() =>
    isAccounting.value
        ? route('accounting.notifications.index')
        : route('admin.notifications.index'),
);

// ─────────────────────────────────────────────────────────────────────────────
// Initial values
// ─────────────────────────────────────────────────────────────────────────────

type StudentMode = 'all' | 'single' | 'multi';

const detectInitialStudentMode = (): StudentMode => {
    if (props.notification?.user_ids?.length) return 'multi';
    if (props.notification?.user_id)          return 'single';
    return 'all';
};

const detectInitialStatus = (): string => {
    if (props.notification?.notification_status) return props.notification.notification_status;
    if (props.notification?.is_active === false) return 'draft';
    if (props.notification?.is_active === true)  return 'active';
    return 'draft';
};

const studentMode = ref<StudentMode>(detectInitialStudentMode());
const searchQuery  = ref('');

const termSelectionMode = ref<'none' | 'by_name' | 'by_id'>(
    props.notification?.target_term_name
        ? 'by_name'
        : props.notification?.term_ids?.length
          ? 'by_id'
          : 'none',
);

const formatDateForInput = (dateString: string | undefined | null): string => {
    if (!dateString) return '';
    return dateString.split('T')[0];
};

const form = useForm({
    title:                   props.notification?.title                   || '',
    message:                 props.notification?.message                 || '',
    type:                    props.notification?.type                    || 'general',
    priority:                props.notification?.priority                || 'medium',
    notification_status:     detectInitialStatus(),
    target_role:             props.notification?.target_role             || 'student',
    start_date:              formatDateForInput(props.notification?.start_date),
    end_date:                formatDateForInput(props.notification?.end_date),
    due_date:                formatDateForInput(props.notification?.due_date),
    payment_term_id:         props.notification?.payment_term_id         || null,
    user_id:                 props.notification?.user_id                 || null,
    user_ids:                (props.notification?.user_ids              ?? []) as number[],
    is_active:               props.notification?.is_active              !== false,
    term_ids:                props.notification?.term_ids               || [],
    target_term_name:        props.notification?.target_term_name       || '',
    trigger_days_before_due: props.notification?.trigger_days_before_due || null,
    course_filter:           (props.notification?.course_filter         ?? []) as string[],
    year_level_filter:       (props.notification?.year_level_filter     ?? []) as string[],
    balance_filter:          props.notification?.balance_filter          || 'any',
});

// ─────────────────────────────────────────────────────────────────────────────
// Watchers
// ─────────────────────────────────────────────────────────────────────────────

// Reset student fields when target_role changes away from 'student'
watch(() => form.target_role, (newRole) => {
    if (newRole !== 'student') {
        studentMode.value  = 'all';
        form.user_id       = null;
        form.user_ids      = [];
        form.course_filter = [];
        form.year_level_filter = [];
        form.balance_filter    = 'any';
    }
});

// Clear due_date + payment_term_id when type changes away from a due-date type.
const DUE_DATE_TYPES = ['payment_due', 'payment_due_notice', 'deadline'];
watch(() => form.type, (newType) => {
    if (!DUE_DATE_TYPES.includes(newType)) {
        form.due_date        = '';
        form.payment_term_id = null;
    }
});

// Auto-fill message template for NEW notifications only.
// On edit, suppress this so we don't overwrite the user's existing content.
const generateMessage = (type: string): string => {
    const msgs: Record<string, string> = {
        general:
            `Dear Student,\n\nWe would like to inform you of an important announcement from CCDI.\n\n[Add your message here]\n\nShould you have any questions, please contact the accounting office.\n\nSincerely,\nCCDI Accounting Office`,
        reminder:
            `Dear Student,\n\nThis is a friendly reminder regarding your account.\n\n[Add reminder details here]\n\nPlease contact the accounting office if you have any questions.\n\nSincerely,\nCCDI Accounting Office`,
        warning:
            `Dear Student,\n\nWe wish to bring to your attention a concern regarding your account.\n\n[Add warning details here]\n\nImmediate action may be required. Please visit the accounting office.\n\nSincerely,\nCCDI Accounting Office`,
        deadline:
            `Dear Student,\n\nThis is to inform you of an important deadline.\n\nDeadline: [Date]\nDetails: [Describe the deadline here]\n\nFailure to comply by the stated deadline may result in penalties.\n\nSincerely,\nCCDI Accounting Office`,
        announcement:
            `Dear Student,\n\nWe are pleased to share the following announcement:\n\n[Add announcement here]\n\nFor further information, please contact the accounting office.\n\nSincerely,\nCCDI Accounting Office`,
        payment_due:
            `Dear Student,\n\nThis is a friendly reminder that your payment is due soon. Please settle your balance on or before the due date to avoid any penalties.\n\nPayment Details:\n• Amount Due: [Amount]\n• Due Date: [Due Date]\n• Payment Term: [Term]\n\nYou may pay at the cashier's office or through our online payment portal.\n\nSincerely,\nCCDI Accounting Office`,
        payment_due_notice:
            `Dear Student,\n\nPlease be informed that a payment deadline is approaching for your account.\n\nDue Date: [Due Date]\nTerm: [Term]\nAmount Due: [Amount]\n\nKindly settle your balance on or before the stated due date.\n\nSincerely,\nCCDI Accounting Office`,
        payment_approved:
            `Dear Student,\n\nWe are pleased to inform you that your payment has been successfully verified and approved.\n\nPayment Details:\n• Reference No.: [Reference]\n• Amount Paid: [Amount]\n• Date Processed: [Date]\n\nThank you for settling your account.\n\nSincerely,\nCCDI Accounting Office`,
        payment_rejected:
            `Dear Student,\n\nWe regret to inform you that your recent payment submission could not be verified and has been rejected.\n\nReason: [State reason here]\n\nPlease resubmit your proof of payment or visit the accounting office.\n\nSincerely,\nCCDI Accounting Office`,
    };
    return msgs[type] ?? msgs.general;
};

// Only set default message on create
if (!isEditing.value && !form.message) {
    form.message = generateMessage(form.type);
}

// Watcher suppressed on edit mode — do not auto-replace existing content
watch(() => form.type, (newType) => {
    if (!isEditing.value) {
        form.message = generateMessage(newType);
    }
});

// Reset student selectors when mode changes
watch(studentMode, (mode) => {
    if (mode === 'all')    { form.user_id = null; form.user_ids = []; }
    if (mode === 'single') { form.user_ids = []; }
    if (mode === 'multi')  { form.user_id = null; }
    searchQuery.value = '';
});

// ─────────────────────────────────────────────────────────────────────────────
// Form submit (Bug #1 fix: update uses accounting.notifications.update)
// ─────────────────────────────────────────────────────────────────────────────

const submit = () => {
    if (termSelectionMode.value === 'none') {
        form.term_ids                = [];
        form.target_term_name        = '';
        form.trigger_days_before_due = null;
    } else if (termSelectionMode.value === 'by_name') {
        form.term_ids = [];
    } else {
        form.target_term_name = '';
    }

    if (studentMode.value !== 'single') form.user_id  = null;
    if (studentMode.value !== 'multi')  form.user_ids  = [];

    if (isEditing.value && props.notification?.id) {
        // ✅ BUG #1 FIX: was route('admin.notifications.show', ...) — wrong route
        form.put(route('accounting.notifications.update', props.notification.id));
    } else {
        form.post(route('accounting.notifications.store'));
    }
};

// ─────────────────────────────────────────────────────────────────────────────
// Student picker helpers
// ─────────────────────────────────────────────────────────────────────────────

const filteredStudents = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();
    if (!q) return props.students;
    return props.students.filter(
        (s) => s.name.toLowerCase().includes(q) || s.email.toLowerCase().includes(q),
    );
});

const selectedStudent  = computed(() => form.user_id ? props.students.find((s) => s.id === form.user_id) : undefined);
const selectedStudents = computed(() => props.students.filter((s) => form.user_ids.includes(s.id)));

const isStudentSelected = (id: number) => form.user_ids.includes(id);

const toggleStudent = (id: number) => {
    form.user_ids = isStudentSelected(id)
        ? form.user_ids.filter((v) => v !== id)
        : [...form.user_ids, id];
};

const removeStudentFromMulti = (id: number) => {
    form.user_ids = form.user_ids.filter((v) => v !== id);
};

const selectAllFiltered = () => {
    const ids = filteredStudents.value.map((s) => s.id);
    form.user_ids = Array.from(new Set([...form.user_ids, ...ids]));
};

const clearAllStudents = () => { form.user_ids = []; };

// ─────────────────────────────────────────────────────────────────────────────
// Course / year level filter helpers
// ─────────────────────────────────────────────────────────────────────────────

const toggleCourse = (course: string) => {
    if (form.course_filter.includes(course)) {
        form.course_filter = form.course_filter.filter((c) => c !== course);
    } else {
        form.course_filter = [...form.course_filter, course];
    }
};

const toggleYearLevel = (yl: string) => {
    if (form.year_level_filter.includes(yl)) {
        form.year_level_filter = form.year_level_filter.filter((y) => y !== yl);
    } else {
        form.year_level_filter = [...form.year_level_filter, yl];
    }
};

// ─────────────────────────────────────────────────────────────────────────────
// Character counters
// ─────────────────────────────────────────────────────────────────────────────

const titleCharsLeft   = computed(() => 150 - (form.title?.length   ?? 0));
const messageCharsLeft = computed(() => 2000 - (form.message?.length ?? 0));

// ─────────────────────────────────────────────────────────────────────────────
// Trigger days preview text
// ─────────────────────────────────────────────────────────────────────────────

const triggerPreviewText = computed(() => {
    if (!form.trigger_days_before_due || !form.due_date) return null;
    const due     = new Date(form.due_date + 'T12:00:00');
    const trigger = new Date(due);
    trigger.setDate(due.getDate() - form.trigger_days_before_due);
    return `Notification appears from ${trigger.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
});

// ─────────────────────────────────────────────────────────────────────────────
// Status helper text
// ─────────────────────────────────────────────────────────────────────────────

const statusHelperText = computed(() => {
    const map: Record<string, string> = {
        draft:     'Saved but invisible — students will not see this until you publish it.',
        scheduled: 'Will automatically activate on the Start Date.',
        active:    'Visible to students immediately after saving.',
        expired:   'Archived — not visible to students.',
    };
    return map[form.notification_status] ?? '';
});

// ─────────────────────────────────────────────────────────────────────────────
// Due date urgency warning
// ─────────────────────────────────────────────────────────────────────────────

const dueDateWarning = computed(() => {
    if (!form.due_date) return null;
    const today = new Date();
    const due   = new Date(form.due_date + 'T12:00:00');
    const diff  = Math.ceil((due.getTime() - today.getTime()) / 86_400_000);
    if (diff < 0)  return { type: 'error',   msg: 'Due date is in the past.' };
    if (diff <= 3) return { type: 'warning',  msg: `Due date is ${diff === 0 ? 'today' : `in ${diff} day(s)`}.` };
    return null;
});
</script>

<template>
    <Head :title="isEditing ? 'Edit Notification' : 'Create Notification'" />
    <AppLayout>
        <div class="w-full p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">
                    {{ isEditing ? 'Edit Notification' : 'Create Notification' }}
                </h1>
                <p class="mt-1 text-sm text-gray-500">
                    {{ isEditing ? 'Update notification details below.' : 'Compose a notification for students or staff.' }}
                </p>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

                <!-- ═══════════════════════════════════════════════════════════
                     LEFT COLUMN — main form (2/3 width)
                ════════════════════════════════════════════════════════════ -->
                <div class="space-y-6 lg:col-span-2">

                    <!-- ── Section 1: Content ──────────────────────────────── -->
                    <Card>
                        <CardHeader>
                            <CardTitle>📝 Notification Content</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-5">

                            <!-- Title -->
                            <div>
                                <div class="mb-1 flex items-center justify-between">
                                    <label class="text-sm font-semibold text-gray-900">Title *</label>
                                    <span
                                        class="text-xs"
                                        :class="titleCharsLeft < 0 ? 'text-red-600 font-medium' : 'text-gray-400'"
                                    >{{ titleCharsLeft }} remaining</span>
                                </div>
                                <input
                                    v-model="form.title"
                                    type="text"
                                    placeholder="e.g. Midterm Payment Reminder"
                                    maxlength="150"
                                    class="w-full rounded-lg border border-gray-300 px-4 py-3 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                />
                                <p v-if="form.errors.title" class="mt-1 text-xs text-red-600">{{ form.errors.title }}</p>
                            </div>

                            <!-- Type + Priority row -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="mb-1 block text-sm font-semibold text-gray-900">Type</label>
                                    <select
                                        v-model="form.type"
                                        class="w-full rounded-lg border border-gray-300 px-4 py-3 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                    >
                                        <option value="general">📢 General</option>
                                        <option value="reminder">⏰ Reminder</option>
                                        <option value="warning">⚠️ Warning</option>
                                        <option value="deadline">⏳ Deadline</option>
                                        <option value="announcement">📣 Announcement</option>
                                        <option value="payment_due">💳 Payment Due</option>
                                        <option value="payment_due_notice">💳 Payment Due Notice</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-semibold text-gray-900">Priority</label>
                                    <div class="flex gap-2">
                                        <button
                                            v-for="p in [
                                                { val: 'low',    label: 'Low',    cls: 'border-gray-300 text-gray-600' },
                                                { val: 'medium', label: 'Medium', cls: 'border-blue-300 text-blue-700' },
                                                { val: 'high',   label: 'High',   cls: 'border-amber-400 text-amber-700' },
                                                { val: 'urgent', label: 'Urgent', cls: 'border-red-400 text-red-700' },
                                            ]"
                                            :key="p.val"
                                            type="button"
                                            @click="form.priority = p.val"
                                            :class="[
                                                'flex-1 rounded-lg border px-2 py-2 text-xs font-semibold transition',
                                                form.priority === p.val
                                                    ? `${p.cls} ring-2 ring-offset-1 ring-blue-500 bg-white shadow`
                                                    : 'border-gray-200 text-gray-400 hover:border-gray-400',
                                            ]"
                                        >
                                            {{ p.label }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Status selector -->
                            <div>
                                <label class="mb-2 block text-sm font-semibold text-gray-900">Status</label>
                                <div class="flex gap-3">
                                    <button
                                        v-for="s in [
                                            { val: 'draft',     label: '○ Draft',       cls: 'border-gray-300 text-gray-600' },
                                            { val: 'scheduled', label: '🗓 Scheduled',  cls: 'border-blue-300 text-blue-700' },
                                            { val: 'active',    label: '● Active',      cls: 'border-green-400 text-green-700' },
                                        ]"
                                        :key="s.val"
                                        type="button"
                                        @click="form.notification_status = s.val"
                                        :class="[
                                            'flex-1 rounded-lg border px-3 py-2.5 text-sm font-medium transition',
                                            form.notification_status === s.val
                                                ? `${s.cls} bg-white shadow ring-2 ring-blue-500 ring-offset-1`
                                                : 'border-gray-200 text-gray-400 hover:border-gray-300',
                                        ]"
                                    >
                                        {{ s.label }}
                                    </button>
                                </div>
                                <p class="mt-2 text-xs text-gray-500 italic">{{ statusHelperText }}</p>
                                <p v-if="form.errors.notification_status" class="mt-1 text-xs text-red-600">{{ form.errors.notification_status }}</p>
                            </div>

                            <!-- Start + End date row -->
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="mb-1 block text-sm font-semibold text-gray-900">Start Date *</label>
                                    <input
                                        v-model="form.start_date"
                                        type="date"
                                        class="w-full rounded-lg border border-gray-300 px-4 py-3 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                    />
                                    <p v-if="form.errors.start_date" class="mt-1 text-xs text-red-600">{{ form.errors.start_date }}</p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-semibold text-gray-900">End Date</label>
                                    <input
                                        v-model="form.end_date"
                                        type="date"
                                        class="w-full rounded-lg border border-gray-300 px-4 py-3 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                    />
                                    <p v-if="form.errors.end_date" class="mt-1 text-xs text-red-600">{{ form.errors.end_date }}</p>
                                </div>
                            </div>

                            <!-- Message -->
                            <div>
                                <div class="mb-1 flex items-center justify-between">
                                    <label class="text-sm font-semibold text-gray-900">Message</label>
                                    <span
                                        class="text-xs"
                                        :class="messageCharsLeft < 0 ? 'text-red-600 font-medium' : 'text-gray-400'"
                                    >{{ messageCharsLeft }} remaining</span>
                                </div>
                                <textarea
                                    v-model="form.message"
                                    rows="8"
                                    maxlength="2000"
                                    placeholder="Write your notification message here…"
                                    class="w-full rounded-lg border border-gray-300 px-4 py-3 text-sm leading-relaxed focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                />
                                <p v-if="form.errors.message" class="mt-1 text-xs text-red-600">{{ form.errors.message }}</p>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- ── Section 2: Scope & Term Assignment ─────────────── -->
                    <Card>
                        <CardHeader>
                            <CardTitle>📅 Scope & Term Assignment</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-5">

                            <!-- Due date (for payment/deadline types) -->
                            <div v-if="['payment_due', 'payment_due_notice', 'deadline'].includes(form.type)">
                                <label class="mb-1 block text-sm font-semibold text-gray-900">Due Date *</label>
                                <input
                                    v-model="form.due_date"
                                    type="date"
                                    class="w-full rounded-lg border px-4 py-3 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                    :class="dueDateWarning?.type === 'error' ? 'border-red-400' : 'border-gray-300'"
                                />
                                <p
                                    v-if="dueDateWarning"
                                    class="mt-1 text-xs"
                                    :class="dueDateWarning.type === 'error' ? 'text-red-600' : 'text-amber-600'"
                                >
                                    {{ dueDateWarning.msg }}
                                </p>
                                <p v-if="form.errors.due_date" class="mt-1 text-xs text-red-600">{{ form.errors.due_date }}</p>
                            </div>

                            <!-- Term filter mode -->
                            <div>
                                <label class="mb-2 block text-sm font-semibold text-gray-900">Term Filter</label>
                                <div class="space-y-2">
                                    <label
                                        v-for="opt in [
                                            { val: 'none',    label: 'No filter — all matching students' },
                                            { val: 'by_name', label: 'By term name (e.g., Midterm, Prelim)' },
                                            { val: 'by_id',   label: 'By specific payment term IDs' },
                                        ]"
                                        :key="opt.val"
                                        class="flex cursor-pointer items-center gap-3"
                                    >
                                        <input
                                            v-model="termSelectionMode"
                                            type="radio"
                                            :value="opt.val"
                                            class="h-4 w-4 text-blue-600"
                                        />
                                        <span class="text-sm text-gray-700">{{ opt.label }}</span>
                                    </label>
                                </div>
                            </div>

                            <!-- By term name -->
                            <div v-if="termSelectionMode === 'by_name'">
                                <label class="mb-1 block text-sm font-semibold text-gray-900">Which term? *</label>
                                <select
                                    v-model="form.target_term_name"
                                    class="w-full rounded-lg border border-gray-300 px-4 py-3 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                >
                                    <option value="">-- Select a Term --</option>
                                    <option value="Upon Registration">Upon Registration</option>
                                    <option value="Prelim">Prelim</option>
                                    <option value="Midterm">Midterm</option>
                                    <option value="Semi-Final">Semi-Final</option>
                                    <option value="Final">Final</option>
                                </select>
                                <p v-if="form.errors.target_term_name" class="mt-1 text-xs text-red-600">{{ form.errors.target_term_name }}</p>
                            </div>

                            <!-- By specific IDs -->
                            <div v-if="termSelectionMode === 'by_id'">
                                <label class="mb-1 block text-sm font-semibold text-gray-900">Select Payment Terms *</label>
                                <div class="max-h-48 space-y-2 overflow-y-auto rounded-lg border border-gray-300 p-4">
                                    <div v-if="paymentTerms.length === 0" class="text-sm text-gray-400">
                                        No payment terms available.
                                    </div>
                                    <label v-for="term in paymentTerms" :key="term.id" class="flex cursor-pointer items-center gap-3">
                                        <input
                                            type="checkbox"
                                            :value="term.id"
                                            v-model="form.term_ids"
                                            class="h-4 w-4 rounded border-gray-300 text-blue-600"
                                        />
                                        <span class="text-sm text-gray-700">{{ term.term_name }}</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Trigger days before due -->
                            <div v-if="termSelectionMode !== 'none'">
                                <label class="mb-1 block text-sm font-semibold text-gray-900">
                                    Show N days before due date
                                    <span class="font-normal text-gray-400">(Optional)</span>
                                </label>
                                <input
                                    v-model.number="form.trigger_days_before_due"
                                    type="number"
                                    placeholder="e.g., 7"
                                    min="0"
                                    max="90"
                                    class="w-full rounded-lg border border-gray-300 px-4 py-3 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                />
                                <p v-if="triggerPreviewText" class="mt-1 text-xs text-blue-600">
                                    ℹ️ {{ triggerPreviewText }}
                                </p>
                            </div>

                            <!-- Payment term link -->
                            <div v-if="['payment_due', 'payment_due_notice'].includes(form.type) && paymentTerms.length">
                                <label class="mb-1 block text-sm font-semibold text-gray-900">
                                    Link to Specific Payment Term
                                    <span class="font-normal text-gray-400">(Optional)</span>
                                </label>
                                <select
                                    v-model="form.payment_term_id"
                                    class="w-full rounded-lg border border-gray-300 px-4 py-3 text-sm focus:border-transparent focus:ring-2 focus:ring-blue-500"
                                >
                                    <option :value="null">-- No specific link --</option>
                                    <option v-for="term in paymentTerms" :key="term.id" :value="term.id">
                                        {{ term.term_name }}
                                    </option>
                                </select>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- ── Section 3: Target Audience ─────────────────────── -->
                    <Card>
                        <CardHeader>
                            <CardTitle>👥 Target Audience</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-5">

                            <!-- Who receives this? -->
                            <div>
                                <label class="mb-2 block text-sm font-semibold text-gray-900">Who receives this?</label>
                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                    <button
                                        v-for="r in [
                                            { val: 'student',    label: '🎓 Students' },
                                            { val: 'accounting', label: '🏦 Accounting' },
                                            { val: 'admin',      label: '🔧 Admin' },
                                            { val: 'all',        label: '🌐 Everyone' },
                                        ]"
                                        :key="r.val"
                                        type="button"
                                        @click="form.target_role = r.val"
                                        :class="[
                                            'rounded-lg border px-3 py-2.5 text-sm font-medium transition',
                                            form.target_role === r.val
                                                ? 'border-blue-500 bg-blue-50 text-blue-700 ring-1 ring-blue-300'
                                                : 'border-gray-200 text-gray-600 hover:border-gray-400',
                                        ]"
                                    >
                                        {{ r.label }}
                                    </button>
                                </div>
                                <p v-if="form.errors.target_role" class="mt-1 text-xs text-red-600">{{ form.errors.target_role }}</p>
                            </div>

                            <!-- Student targeting -->
                            <div v-if="form.target_role === 'student'" class="space-y-5">

                                <!-- Student mode -->
                                <div>
                                    <label class="mb-2 block text-sm font-semibold text-gray-900">Student scope</label>
                                    <div class="flex gap-2">
                                        <button
                                            type="button"
                                            @click="studentMode = 'all'"
                                            :class="[
                                                'flex-1 rounded-lg border px-4 py-2.5 text-sm font-medium transition',
                                                studentMode === 'all'
                                                    ? 'border-blue-500 bg-blue-50 text-blue-700'
                                                    : 'border-gray-300 text-gray-600 hover:bg-gray-50',
                                            ]"
                                        >All Students</button>
                                        <button
                                            type="button"
                                            @click="studentMode = 'single'"
                                            :class="[
                                                'flex-1 rounded-lg border px-4 py-2.5 text-sm font-medium transition',
                                                studentMode === 'single'
                                                    ? 'border-blue-500 bg-blue-50 text-blue-700'
                                                    : 'border-gray-300 text-gray-600 hover:bg-gray-50',
                                            ]"
                                        >One Student</button>
                                        <button
                                            type="button"
                                            @click="studentMode = 'multi'"
                                            :class="[
                                                'flex-1 rounded-lg border px-4 py-2.5 text-sm font-medium transition',
                                                studentMode === 'multi'
                                                    ? 'border-blue-500 bg-blue-50 text-blue-700'
                                                    : 'border-gray-300 text-gray-600 hover:bg-gray-50',
                                            ]"
                                        >
                                            Specific Students
                                            <span
                                                v-if="selectedStudents.length"
                                                class="ml-1 inline-flex h-5 w-5 items-center justify-center rounded-full bg-blue-600 text-xs text-white"
                                            >{{ selectedStudents.length }}</span>
                                        </button>
                                    </div>
                                </div>

                                <!-- All students: course + year level filters -->
                                <template v-if="studentMode === 'all'">
                                    <!-- Course filter -->
                                    <div v-if="courses.length">
                                        <label class="mb-2 block text-sm font-semibold text-gray-900">
                                            Course Filter
                                            <span class="font-normal text-gray-400">(Optional — leave empty for all courses)</span>
                                        </label>
                                        <div class="flex flex-wrap gap-2">
                                            <button
                                                v-for="c in courses"
                                                :key="c"
                                                type="button"
                                                @click="toggleCourse(c)"
                                                :class="[
                                                    'rounded-full border px-3 py-1.5 text-xs font-medium transition',
                                                    form.course_filter.includes(c)
                                                        ? 'border-teal-500 bg-teal-50 text-teal-700'
                                                        : 'border-gray-200 text-gray-600 hover:border-gray-400',
                                                ]"
                                            >
                                                {{ c }}
                                            </button>
                                        </div>
                                        <p v-if="form.course_filter.length" class="mt-1.5 text-xs text-teal-600">
                                            ✓ {{ form.course_filter.length }} course(s) selected
                                        </p>
                                    </div>

                                    <!-- Year level filter -->
                                    <div v-if="yearLevels.length">
                                        <label class="mb-2 block text-sm font-semibold text-gray-900">
                                            Year Level Filter
                                            <span class="font-normal text-gray-400">(Optional)</span>
                                        </label>
                                        <div class="flex flex-wrap gap-2">
                                            <button
                                                v-for="yl in yearLevels"
                                                :key="yl"
                                                type="button"
                                                @click="toggleYearLevel(yl)"
                                                :class="[
                                                    'rounded-full border px-3 py-1.5 text-xs font-medium transition',
                                                    form.year_level_filter.includes(yl)
                                                        ? 'border-indigo-500 bg-indigo-50 text-indigo-700'
                                                        : 'border-gray-200 text-gray-600 hover:border-gray-400',
                                                ]"
                                            >
                                                {{ yl }}
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Balance filter -->
                                    <div>
                                        <label class="mb-2 block text-sm font-semibold text-gray-900">Balance Filter</label>
                                        <div class="space-y-2">
                                            <label
                                                v-for="bf in [
                                                    { val: 'any',          label: 'Any — all students regardless of balance' },
                                                    { val: 'with_balance', label: 'With Balance — students who owe money' },
                                                    { val: 'overdue',      label: 'Overdue — students with past-due balances' },
                                                ]"
                                                :key="bf.val"
                                                class="flex cursor-pointer items-center gap-3"
                                            >
                                                <input
                                                    v-model="form.balance_filter"
                                                    type="radio"
                                                    :value="bf.val"
                                                    class="h-4 w-4 text-blue-600"
                                                />
                                                <span class="text-sm text-gray-700">{{ bf.label }}</span>
                                            </label>
                                        </div>
                                    </div>
                                </template>

                                <!-- Single student picker -->
                                <div v-if="studentMode === 'single'" class="space-y-3">
                                    <input
                                        v-model="searchQuery"
                                        type="text"
                                        placeholder="Search by name or email…"
                                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-500"
                                    />
                                    <div class="max-h-56 overflow-y-auto rounded-lg border border-gray-300">
                                        <div
                                            v-if="filteredStudents.length === 0"
                                            class="p-4 text-center text-sm text-gray-400"
                                        >No students found.</div>
                                        <div
                                            v-for="student in filteredStudents"
                                            :key="student.id"
                                            @click="form.user_id = student.id"
                                            :class="[
                                                'flex cursor-pointer items-center gap-3 border-b border-gray-100 p-3 last:border-b-0 hover:bg-gray-50',
                                                form.user_id === student.id ? 'bg-blue-50' : '',
                                            ]"
                                        >
                                            <div
                                                :class="[
                                                    'h-3.5 w-3.5 rounded-full border-2 shrink-0',
                                                    form.user_id === student.id
                                                        ? 'border-blue-500 bg-blue-500'
                                                        : 'border-gray-300',
                                                ]"
                                            />
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-medium text-gray-900">{{ student.name }}</p>
                                                <p class="truncate text-xs text-gray-500">{{ student.email }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <p v-if="form.errors.user_id" class="text-xs text-red-600">{{ form.errors.user_id }}</p>
                                </div>

                                <!-- Multi student picker -->
                                <div v-if="studentMode === 'multi'" class="space-y-3">
                                    <!-- Selected chips -->
                                    <div
                                        v-if="selectedStudents.length"
                                        class="flex flex-wrap gap-2 rounded-lg border border-blue-200 bg-blue-50 p-3"
                                    >
                                        <span
                                            v-for="s in selectedStudents"
                                            :key="s.id"
                                            class="inline-flex items-center gap-1.5 rounded-full bg-blue-600 px-3 py-1 text-xs font-medium text-white"
                                        >
                                            {{ s.name }}
                                            <button
                                                type="button"
                                                @click="removeStudentFromMulti(s.id)"
                                                class="rounded-full hover:bg-blue-700"
                                            >
                                                <X class="h-3 w-3" />
                                            </button>
                                        </span>
                                    </div>
                                    <p v-else class="text-xs text-gray-400">No students selected yet.</p>

                                    <input
                                        v-model="searchQuery"
                                        type="text"
                                        placeholder="Search by name or email…"
                                        class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:ring-2 focus:ring-blue-500"
                                    />

                                    <div class="flex gap-2">
                                        <button
                                            type="button"
                                            @click="selectAllFiltered"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                        >
                                            Select all ({{ filteredStudents.length }})
                                        </button>
                                        <button
                                            type="button"
                                            @click="clearAllStudents"
                                            class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50"
                                        >
                                            Clear all
                                        </button>
                                    </div>

                                    <div class="max-h-72 overflow-y-auto rounded-lg border border-gray-300">
                                        <div
                                            v-if="filteredStudents.length === 0"
                                            class="p-4 text-center text-sm text-gray-400"
                                        >No students found.</div>
                                        <div
                                            v-for="student in filteredStudents"
                                            :key="student.id"
                                            @click="toggleStudent(student.id)"
                                            :class="[
                                                'flex cursor-pointer items-center gap-3 border-b border-gray-100 p-3 last:border-b-0 hover:bg-gray-50 transition-colors',
                                                isStudentSelected(student.id) ? 'bg-blue-50' : '',
                                            ]"
                                        >
                                            <component
                                                :is="isStudentSelected(student.id) ? CheckSquare : Square"
                                                class="h-4 w-4 shrink-0"
                                                :class="isStudentSelected(student.id) ? 'text-blue-600' : 'text-gray-400'"
                                            />
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-medium text-gray-900">{{ student.name }}</p>
                                                <p class="truncate text-xs text-gray-500">{{ student.email }}</p>
                                            </div>
                                        </div>
                                    </div>
                                    <p v-if="form.errors['user_ids']" class="text-xs text-red-600">{{ form.errors['user_ids'] }}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- ═══════════════════════════════════════════════════════════
                     RIGHT COLUMN — sidebar (1/3 width)
                ════════════════════════════════════════════════════════════ -->
                <div class="space-y-5">

                    <!-- Recipient summary -->
                    <Card class="border border-gray-200">
                        <CardHeader>
                            <CardTitle class="text-sm">📬 Email Recipients</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-1 text-xs text-gray-600">
                                <template v-if="studentMode === 'multi' && selectedStudents.length">
                                    <p class="font-semibold text-blue-700">
                                        {{ selectedStudents.length }} specific student{{ selectedStudents.length !== 1 ? 's' : '' }}
                                    </p>
                                    <p v-for="s in selectedStudents.slice(0, 5)" :key="s.id" class="truncate text-gray-400">
                                        {{ s.email }}
                                    </p>
                                    <p v-if="selectedStudents.length > 5" class="text-gray-400">
                                        +{{ selectedStudents.length - 5 }} more…
                                    </p>
                                </template>
                                <template v-else-if="studentMode === 'single' && selectedStudent">
                                    <p class="font-semibold text-blue-700">1 student</p>
                                    <p class="truncate text-gray-400">{{ selectedStudent.email }}</p>
                                </template>
                                <template v-else-if="form.target_role === 'all'">
                                    <p>All active users will receive an email.</p>
                                </template>
                                <template v-else>
                                    <p>All active <strong>{{ form.target_role }}</strong> users.</p>
                                    <template v-if="form.course_filter.length">
                                        <p class="text-teal-600">Course: {{ form.course_filter.join(', ') }}</p>
                                    </template>
                                    <template v-if="form.year_level_filter.length">
                                        <p class="text-indigo-600">Year: {{ form.year_level_filter.join(', ') }}</p>
                                    </template>
                                    <template v-if="form.balance_filter !== 'any'">
                                        <p class="text-amber-600">{{ form.balance_filter === 'with_balance' ? 'With balance only' : 'Overdue only' }}</p>
                                    </template>
                                </template>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Live preview -->
                    <NotificationPreview
                        :title="form.title"
                        :message="form.message"
                        :type="form.type"
                        :priority="form.priority"
                        :notification-status="form.notification_status"
                        :start-date="form.start_date"
                        :end-date="form.end_date"
                        :due-date="form.due_date"
                        :target-role="form.target_role"
                        :selected-student-email="selectedStudents[0]?.email ?? selectedStudent?.email ?? ''"
                    />

                    <!-- Tips -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="text-sm">💡 Tips</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul class="space-y-2 text-xs text-gray-600">
                                <li>✓ <strong>Draft</strong> saves without sending — review before publishing</li>
                                <li>✓ <strong>Scheduled</strong> auto-activates on the Start Date</li>
                                <li>✓ Set <strong>Course Filter</strong> to target one program only</li>
                                <li>✓ <strong>Payment Due</strong> type updates payment term due dates automatically</li>
                                <li>✓ Emails are queued and sent in the background</li>
                            </ul>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <!-- ── Sticky action bar ──────────────────────────────────────── -->
            <div class="sticky bottom-0 mt-8 flex items-center justify-end gap-3 border-t border-gray-200 bg-white/95 py-4 backdrop-blur">
                <span v-if="form.processing" class="text-sm text-gray-400 animate-pulse">Saving…</span>
                <span v-else-if="form.recentlySuccessful" class="text-sm font-medium text-green-600">✓ Saved!</span>

                <Link :href="backHref">
                    <Button type="button" variant="outline" class="px-6">Cancel</Button>
                </Link>
                <Button
                    type="button"
                    :disabled="form.processing || messageCharsLeft < 0 || titleCharsLeft < 0"
                    @click="submit"
                    class="bg-blue-600 px-8 text-white hover:bg-blue-700 disabled:opacity-60"
                >
                    <span v-if="form.processing">Saving…</span>
                    <span v-else>{{ isEditing ? 'Update Notification' : 'Create Notification' }}</span>
                </Button>
            </div>
        </div>
    </AppLayout>
</template>