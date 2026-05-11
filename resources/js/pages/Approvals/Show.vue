<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { useDataFormatting } from '@/composables/useDataFormatting';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Download, ExternalLink, FileText, ImageOff, RotateCcw, XCircle } from 'lucide-vue-next';
import { ref } from 'vue';

interface TransactionMeta {
    term_name?: string;
    proof_of_payment?: string;
    description?: string;
    selected_term_id?: number;
    assessment_id?: number;
}

interface Approval {
    id: number;
    status: 'pending' | 'approved' | 'rejected';
    step_name: string;
    approver_name: string | null;
    comments: string | null;
    created_at: string;
    updated_at: string;
    workflow_instance?: {
        workflowable: {
            amount?: number;
            reference?: string;
            meta?: TransactionMeta;
            type?: string;
            payment_channel?: string;
            user?: { first_name: string; last_name: string; account_id: string };
        };
    };
}

interface Student {
    id: number;
    student_id: string | null;
    user?: {
        first_name: string;
        last_name: string;
        account_id: string;
    };
}

interface UnpaidTerm {
    id: number;
    term_name: string;
    amount: number;
    balance: number;
    due_date: string | null;
    status: string;
}

interface Assessment {
    id: number;
    assessment_number: string;
    school_year: string;
    semester: string;
    total_assessment: number;
    status: string;
}

interface Props {
    approval: Approval;
    student?: Student | null;
    unpaidTerms?: UnpaidTerm[];
    assessment?: Assessment | null;
    proofUrl?: string | null;
    proofType?: 'image' | 'pdf' | null;
}

const props = defineProps<Props>();

const { formatCurrency } = useDataFormatting();

const breadcrumbs = [
    { title: 'Dashboard', href: route('accounting.dashboard') },
    { title: 'Approvals', href: route('approvals.index') },
    { title: 'Details' },
];

const formatDate = (date: string | null) => {
    if (!date) return '—';
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const capitalize = (str: string) => str.charAt(0).toUpperCase() + str.slice(1);

const showRejectDialog = ref(false);
const proofLoadError = ref(false);

const approveForm = useForm({});

const approve = () => {
    approveForm.post(route('approvals.approve', props.approval.id));
};

const rejectForm = useForm({ comments: '' });

const openRejectDialog = () => {
    rejectForm.reset();
    showRejectDialog.value = true;
};

const reject = () => {
    rejectForm.post(route('approvals.reject', props.approval.id), {
        onSuccess: () => {
            showRejectDialog.value = false;
        },
    });
};

const refreshApproval = () => {
    router.reload();
};

const onProofLoadError = () => {
    proofLoadError.value = true;
};

const paymentMethodLabel: Record<string, string> = {
    cash: 'Cash',
    gcash: 'GCash',
    bank_transfer: 'Bank Transfer',
    credit_card: 'Credit Card',
    debit_card: 'Debit Card',
    paymongo_checkout: 'PayMongo',
};
</script>

<template>
    <Head title="Approval Details" />

    <AppLayout>
        <div class="w-full space-y-6 p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold">Approval Details</h1>
                    <p class="text-gray-500">Review and action this workflow approval</p>
                </div>
                <button
                    @click="refreshApproval"
                    title="Refresh approval details"
                    class="rounded-lg border border-gray-300 bg-white p-2 text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900"
                >
                    <RotateCcw :size="20" />
                </button>
            </div>

            <!-- Approval Details Card -->
            <div class="space-y-6 rounded-xl border bg-white p-6 shadow-sm">

                <!-- Status Badge + Amount -->
                <div class="flex items-center justify-between">
                    <span
                        class="rounded-full px-4 py-2 text-sm font-semibold"
                        :class="{
                            'bg-yellow-100 text-yellow-800': approval.status === 'pending',
                            'bg-green-100 text-green-800': approval.status === 'approved',
                            'bg-red-100 text-red-800': approval.status === 'rejected',
                        }"
                    >
                        {{ capitalize(approval.status) }}
                    </span>
                    <p v-if="approval.workflow_instance?.workflowable?.amount" class="text-2xl font-bold text-blue-700">
                        {{ formatCurrency(approval.workflow_instance.workflowable.amount) }}
                    </p>
                </div>

                <!-- Student + Reference -->
                <div class="grid grid-cols-2 gap-4 border-b pb-4">
                    <div>
                        <p class="text-sm text-gray-500">Student</p>
                        <p class="font-semibold">
                            {{
                                student?.user
                                    ? `${student.user.last_name}, ${student.user.first_name}`
                                    : approval.workflow_instance?.workflowable?.user
                                        ? `${approval.workflow_instance.workflowable.user.last_name}, ${approval.workflow_instance.workflowable.user.first_name}`
                                        : '—'
                            }}
                        </p>
                        <p class="text-xs text-gray-400">
                            {{
                                student?.user?.account_id
                                    ?? approval.workflow_instance?.workflowable?.user?.account_id
                                    ?? ''
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Reference</p>
                        <p class="font-mono font-semibold">
                            {{ approval.workflow_instance?.workflowable?.reference ?? '—' }}
                        </p>
                    </div>
                </div>

                <!-- Payment Details -->
                <div class="grid grid-cols-2 gap-4 border-b pb-4">
                    <div>
                        <p class="text-sm text-gray-500">Term</p>
                        <p class="font-semibold">
                            {{ approval.workflow_instance?.workflowable?.meta?.term_name ?? approval.workflow_instance?.workflowable?.type ?? '—' }}
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Payment Method</p>
                        <p class="font-semibold">
                            {{
                                paymentMethodLabel[approval.workflow_instance?.workflowable?.payment_channel ?? '']
                                ?? approval.workflow_instance?.workflowable?.payment_channel
                                ?? '—'
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Assessment No.</p>
                        <p class="font-mono font-semibold">{{ assessment?.assessment_number ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">School Year / Semester</p>
                        <p class="font-semibold">
                            {{ assessment ? `${assessment.school_year} · ${assessment.semester}` : '—' }}
                        </p>
                    </div>
                </div>

                <!-- Approver & Dates -->
                <div class="grid grid-cols-2 gap-4 border-b pb-4">
                    <div>
                        <p class="text-sm text-gray-500">Assigned to</p>
                        <p class="font-semibold">{{ approval.approver_name ?? 'Unassigned' }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Submitted</p>
                        <p class="font-semibold">{{ formatDate(approval.created_at) }}</p>
                    </div>
                </div>

                <!-- ── PROOF OF PAYMENT ────────────────────────────────────── -->
                <div class="border-t pt-6">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="font-semibold text-gray-900">Proof of Payment</h3>
                        <a
                            v-if="proofUrl"
                            :href="proofUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
                        >
                            <Download :size="14" />
                            Download
                        </a>
                    </div>

                    <!-- No proof uploaded -->
                    <div
                        v-if="!proofUrl"
                        class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-200 bg-gray-50 py-12 text-center"
                    >
                        <ImageOff :size="40" class="mb-3 text-gray-300" />
                        <p class="text-sm font-medium text-gray-500">No proof of payment submitted</p>
                        <p class="mt-1 text-xs text-gray-400">
                            This may be a PayMongo (online) payment — no manual receipt is required.
                        </p>
                    </div>

                    <!-- Image proof (jpg, png, webp) -->
                    <div v-else-if="proofType === 'image'" class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50">
                        <!-- Load error fallback -->
                        <div
                            v-if="proofLoadError"
                            class="flex flex-col items-center justify-center py-12 text-center"
                        >
                            <ImageOff :size="36" class="mb-2 text-gray-300" />
                            <p class="text-sm text-gray-500">Image could not be loaded.</p>
                            <a
                                :href="proofUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:underline"
                            >
                                <ExternalLink :size="14" />
                                Open in new tab
                            </a>
                        </div>
                        <img
                            v-else
                            :src="proofUrl"
                            alt="Proof of payment"
                            class="max-h-[600px] w-full object-contain"
                            @error="onProofLoadError"
                        />
                    </div>

                    <!-- PDF proof -->
                    <div v-else-if="proofType === 'pdf'" class="overflow-hidden rounded-xl border border-gray-200">
                        <div class="flex items-center gap-3 border-b bg-gray-50 px-4 py-3">
                            <FileText :size="20" class="text-red-500" />
                            <span class="text-sm font-medium text-gray-700">PDF Receipt</span>
                            <a
                                :href="proofUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="ml-auto inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:underline"
                            >
                                <ExternalLink :size="14" />
                                Open in new tab
                            </a>
                        </div>
                        <iframe
                            :src="proofUrl"
                            class="h-[600px] w-full border-0"
                            title="Proof of payment PDF"
                        />
                    </div>
                </div>
                <!-- ── END PROOF OF PAYMENT ────────────────────────────────── -->

                <!-- Comments (if any) -->
                <div v-if="approval.comments" class="border-t pt-4">
                    <p class="text-sm text-gray-500">Comment</p>
                    <p class="mt-2 rounded-lg bg-gray-50 p-4 text-sm">{{ approval.comments }}</p>
                </div>

                <!-- Other Unpaid Terms -->
                <div v-if="unpaidTerms && unpaidTerms.length > 0" class="border-t pt-6">
                    <h3 class="mb-4 font-semibold text-gray-900">Other Unpaid Payment Terms</h3>
                    <div class="overflow-x-auto rounded-lg border">
                        <table class="w-full">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="border-b px-4 py-3 text-left text-sm font-semibold text-gray-700">Term</th>
                                    <th class="border-b px-4 py-3 text-left text-sm font-semibold text-gray-700">Amount</th>
                                    <th class="border-b px-4 py-3 text-left text-sm font-semibold text-gray-700">Balance</th>
                                    <th class="border-b px-4 py-3 text-left text-sm font-semibold text-gray-700">Due Date</th>
                                    <th class="border-b px-4 py-3 text-left text-sm font-semibold text-gray-700">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="term in unpaidTerms" :key="term.id" class="border-b hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm">{{ term.term_name }}</td>
                                    <td class="px-4 py-3 text-sm">{{ formatCurrency(term.amount) }}</td>
                                    <td class="px-4 py-3 text-sm font-semibold text-orange-600">{{ formatCurrency(term.balance) }}</td>
                                    <td class="px-4 py-3 text-sm">{{ formatDate(term.due_date) }}</td>
                                    <td class="px-4 py-3 text-sm">
                                        <span
                                            class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                            :class="{
                                                'bg-yellow-100 text-yellow-800': term.status === 'pending',
                                                'bg-orange-100 text-orange-800': term.status === 'partial',
                                                'bg-green-100 text-green-800': term.status === 'paid',
                                            }"
                                        >
                                            {{ capitalize(term.status) }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Action Buttons — pending only -->
                <div v-if="approval.status === 'pending'" class="border-t pt-6">
                    <p class="mb-4 text-sm font-semibold text-gray-700">Take Action</p>
                    <div class="grid grid-cols-2 gap-4">
                        <button
                            @click="approve"
                            :disabled="approveForm.processing"
                            class="group relative inline-flex items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-green-500 to-green-600 px-6 py-3 font-semibold text-white shadow-lg transition-all duration-200 hover:scale-105 hover:from-green-600 hover:to-green-700 hover:shadow-xl disabled:scale-100 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <CheckCircle2 v-if="!approveForm.processing" :size="20" class="transition-transform group-hover:scale-110" />
                            <span>{{ approveForm.processing ? 'Approving…' : 'Approve' }}</span>
                        </button>
                        <button
                            @click="openRejectDialog"
                            :disabled="approveForm.processing"
                            class="group relative inline-flex items-center justify-center gap-2 rounded-lg bg-gradient-to-r from-red-500 to-red-600 px-6 py-3 font-semibold text-white shadow-lg transition-all duration-200 hover:scale-105 hover:from-red-600 hover:to-red-700 hover:shadow-xl disabled:scale-100 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <XCircle v-if="!approveForm.processing" :size="20" class="transition-transform group-hover:scale-110" />
                            <span>Decline</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Decline Dialog -->
        <div
            v-if="showRejectDialog"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4"
            @click.self="showRejectDialog = false"
        >
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h2 class="mb-1 text-lg font-bold text-gray-900">Decline Payment</h2>
                <p class="mb-4 text-sm text-gray-500">Provide a reason. The student will be notified.</p>

                <textarea
                    v-model="rejectForm.comments"
                    class="w-full rounded-lg border border-gray-300 p-3 text-sm outline-none focus:border-transparent focus:ring-2 focus:ring-red-400"
                    placeholder="Enter rejection reason (required)..."
                    rows="4"
                />
                <p v-if="rejectForm.errors.comments" class="mt-1 text-sm text-red-500">
                    {{ rejectForm.errors.comments }}
                </p>

                <div class="mt-5 flex gap-3">
                    <button
                        @click="showRejectDialog = false"
                        class="flex-1 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        @click="reject"
                        :disabled="rejectForm.processing || !rejectForm.comments.trim()"
                        class="flex-1 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span v-if="rejectForm.processing">Declining…</span>
                        <span v-else>Confirm Decline</span>
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>