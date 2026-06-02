<template>
    <div class="payment-terms-breakdown">
        <!-- Header -->
        <div class="mb-6">
            <h2 class="mb-2 text-2xl font-bold text-gray-900">Payment Terms Breakdown</h2>
            <p class="text-gray-600">5-Term payment structure with automatic balance carryover</p>
        </div>

        <!-- Summary Cards -->
        <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
            <!-- Total Assessment -->
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm font-medium text-gray-600">Total Assessment</p>
                <p class="text-2xl font-bold text-gray-900">₱{{ formatCurrency(totalAssessment) }}</p>
            </div>

            <!-- Total Paid -->
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm font-medium text-gray-600">Total Paid</p>
                <p class="text-2xl font-bold text-gray-900">₱{{ formatCurrency(totalPaid) }}</p>
            </div>

            <!-- Remaining Balance -->
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <p class="text-sm font-medium text-gray-600">Remaining Balance</p>
                <p class="text-2xl font-bold text-gray-900">₱{{ formatCurrency(remainingBalance) }}</p>
            </div>
        </div>

        <!-- Payment Terms Table -->
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <table class="w-full">
                <thead class="border-b border-gray-200 bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">Payment Term</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">Percentage</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">Original Amount</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">Current Balance</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">Due Date</th>
                        <th class="px-4 py-3 text-left text-sm font-semibold text-gray-700">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(term, index) in terms"
                        :key="term.id"
                        :class="['border-b border-gray-200 transition-colors hover:bg-gray-50', index === terms.length - 1 ? '' : '']"
                    >
                        <!-- Term Name -->
                        <td class="px-4 py-3">
                            <div>
                                <p class="font-medium text-gray-900">{{ term.term_name }}</p>
                                <p v-if="term.remarks && term.remarks.includes('carried')" class="mt-1 text-xs text-amber-600">🔄 Has carryover</p>
                            </div>
                        </td>

                        <!-- Percentage -->
                        <td class="px-4 py-3">
                            <span class="text-sm font-medium text-gray-700">{{ term.percentage }}%</span>
                        </td>

                        <!-- Original Amount -->
                        <td class="px-4 py-3">
                            <p class="text-sm text-gray-700">₱{{ formatCurrency(term.amount) }}</p>
                        </td>

                        <!-- Current Balance -->
                        <td class="px-4 py-3">
                            <p class="text-sm font-semibold text-gray-900">₱{{ formatCurrency(term.balance) }}</p>
                            <p v-if="term.remarks" class="mt-1 text-xs text-gray-500">
                                {{ term.remarks }}
                            </p>
                        </td>

                        <!-- Due Date -->
                        <td class="px-4 py-3">
                            <p class="text-sm text-gray-700">{{ formatDate(term.due_date) }}</p>
                            <p v-if="isOverdue(term.due_date)" class="mt-1 text-xs text-red-600">⚠️ Overdue</p>
                        </td>

                        <!-- Status Badge -->
                        <td class="px-4 py-3">
                            <span :class="['inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium', statusClasses(term.status)]">
                                {{ formatStatus(term.status) }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Carryover Information -->
        <div v-if="hasCarryover" class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4">
            <p class="mb-2 text-sm font-semibold text-amber-900">💡 Payment Carryover System</p>
            <p class="mb-3 text-sm text-amber-800">
                Unpaid balances from previous terms automatically carry forward to the next term until fully settled. When you make a payment, it
                first clears carried-over balances from earlier terms.
            </p>
            <div class="space-y-1 text-xs text-amber-700">
                <p>✓ Balances carry automatically across terms</p>
                <p>✓ Earlier unpaid terms are settled first when you pay</p>
                <p>✓ Carryover continues until total assessment is fully paid</p>
            </div>
        </div>

        <!-- Terms Totals -->
        <div class="mt-6 rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <p class="mb-1 text-sm text-gray-600">Total Original Amount</p>
                    <p class="text-xl font-bold text-gray-900">₱{{ formatCurrency(totalOriginal) }}</p>
                </div>
                <div>
                    <p class="mb-1 text-sm text-gray-600">Total Current Balance</p>
                    <p class="text-xl font-bold text-gray-900">₱{{ formatCurrency(totalBalance) }}</p>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';

interface PaymentTerm {
    id: number;
    term_name: string;
    term_order: number;
    percentage: number;
    amount: number;
    balance: number;
    status: string;
    due_date: string | null;
    remarks?: string | null;
    paid_date?: string | null;
}

interface Props {
    terms: PaymentTerm[];
    totalAssessment: number;
}

const props = defineProps<Props>();

const formatCurrency = (amount: number): string => {
    return new Intl.NumberFormat('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);
};

const formatDate = (date: string | null | undefined): string => {
    if (!date) return '—';
    const d = new Date(date);
    if (isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

const formatStatus = (status: string): string => {
    const statuses: Record<string, string> = {
        pending:   'Pending',
        partial:   'Partial',       // legacy
        underpaid: 'Underpaid',     // final term with remaining balance
        paid:      'Paid',
        overdue:   'Overdue',
        processed: 'Carried Fwd',
    };
    return statuses[status] || status;
};

const statusClasses = (status: string): string => {
    const classes: Record<string, string> = {
        pending:   'bg-yellow-100 text-yellow-800',
        partial:   'bg-orange-100 text-orange-800',   // legacy
        underpaid: 'bg-amber-50 text-amber-700',      // final term, balance remains
        paid:      'bg-green-100 text-green-800',
        overdue:   'bg-red-100 text-red-800',
        processed: 'bg-sky-100 text-sky-800',
    };
    return classes[status] || 'bg-gray-100 text-gray-800';
};

const isOverdue = (dueDate: string | null | undefined): boolean => {
    if (!dueDate) return false; // No due date = never overdue
    const due   = new Date(dueDate);
    const today = new Date();
    due.setHours(0, 0, 0, 0);
    today.setHours(0, 0, 0, 0);
    return due < today;
};

const totalOriginal = computed(() => {
    return props.terms.reduce((sum, term) => sum + term.amount, 0);
});

const totalBalance = computed(() => {
    return props.terms.reduce((sum, term) => sum + term.balance, 0);
});

const totalPaid = computed(() => {
    return props.totalAssessment - totalBalance.value;
});

const remainingBalance = computed(() => {
    return totalBalance.value;
});

const hasCarryover = computed(() => {
    // PHP writes 'Carry-over of ₱...' (capital C, hyphenated) in the receiving
    // term's remarks, and 'X carried to Y' in the closed term's remarks.
    // We must check for both patterns case-insensitively.
    return props.terms.some((term) => {
        if (!term.remarks) return false;
        const r = term.remarks.toLowerCase();
        return r.includes('carry-over') || r.includes('carried to');
    });
});
</script>

<style scoped>
.payment-terms-breakdown {
    /* Custom styles if needed */
}
</style>