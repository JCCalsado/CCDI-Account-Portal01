<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { useDataFormatting } from '@/composables/useDataFormatting';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
const { formatCurrency } = useDataFormatting();

interface Props {
    transaction: {
        id: number;
        reference: string;
        or_number?: string | null; 
        amount: number;
        status: string;
        type: string;
        kind?: 'charge' | 'payment';
        year?: string | null;
        semester?: string | null;
        created_at: string;
        paid_at?: string;
        payment_channel?: string;
        meta?: Record<string, any>;
        user?: {
            id: number;
            name: string;
            email: string;
            account_id?: string;
        };
    };
    account?: {
        balance: number;
    } | null;
}

const props = defineProps<Props>();

const breadcrumbs = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Transactions', href: route('transactions.index') },
    { title: `#${props.transaction.reference || props.transaction.id}` },
];

const formatDate = (date: string) => new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });

const accountBalance = parseFloat(String(props.account?.balance ?? 0));
const hasCredit = accountBalance < 0;
const displayBalance = Math.abs(accountBalance);

/**
 * Build the term key for the receipt download.
 * Guards against null year/semester which would produce "null null" in the URL.
 *
 * Falls back to the current term derived client-side when the transaction
 * has no year/semester (e.g. old manual-entry records).
 */
const buildCurrentTermFallback = (): string => {
    const now = new Date();
    const month = now.getMonth() + 1; // 1-based
    const year = now.getFullYear();
    const sem = month >= 6 && month <= 10 ? '1st Sem' : '2nd Sem';
    return `${year} ${sem}`;
};

const downloadReceipt = () => {
    const parts = [props.transaction.year, props.transaction.semester].filter(Boolean);
    const termKey = parts.length === 2 ? parts.join(' ') : buildCurrentTermFallback();
    const url = route('transactions.download') + '?term=' + encodeURIComponent(termKey);
    window.open(url, '_blank');
};
</script>

<template>
    <Head :title="`Transaction ${transaction.reference}`" />

    <AppLayout>
        <div class="mx-auto max-w-3xl space-y-6 p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <div class="space-y-6 rounded-xl bg-white p-6 shadow-md">
                <!-- Header -->
                <div class="flex items-start justify-between">
                    <div>
                        <h1 class="text-2xl font-bold">Transaction #{{ transaction.reference || transaction.id }}</h1>
                        <p class="mt-1 text-sm text-gray-500">{{ formatDate(transaction.created_at) }}</p>
                    </div>
                    <span
                        class="rounded-full px-3 py-1 text-sm font-semibold"
                        :class="{
                            'bg-green-100 text-green-800': transaction.status === 'paid',
                            'bg-yellow-100 text-yellow-800': transaction.status === 'pending',
                            'bg-blue-100 text-blue-800': transaction.status === 'awaiting_approval',
                            'bg-red-100 text-red-800': transaction.status === 'failed',
                            'bg-gray-100 text-gray-800': transaction.status === 'cancelled',
                        }"
                    >
                        {{ transaction.status === 'awaiting_approval' ? 'Awaiting Verification' : transaction.status }}
                    </span>
                </div>

                <!-- Main Details -->
                <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <div>
                        <p class="text-xs font-medium text-gray-500 uppercase">Kind</p>
                        <span
                            class="mt-1 inline-block rounded-full px-2 py-1 text-xs font-semibold"
                            :class="transaction.kind === 'charge' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'"
                        >
                            {{ transaction.kind || 'transaction' }}
                        </span>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-gray-500 uppercase">Category</p>
                        <p class="mt-1 font-semibold">{{ transaction.type }}</p>
                    </div>
                    <div class="col-span-2">
                        <p class="text-xs font-medium text-gray-500 uppercase">Amount</p>
                        <p class="mt-1 text-3xl font-bold" :class="transaction.kind === 'charge' ? 'text-red-600' : 'text-green-600'">
                            {{ transaction.kind === 'charge' ? '+' : '−' }}{{ formatCurrency(transaction.amount) }}
                        </p>
                    </div>
                </div>

                <!-- Academic Term -->
                <div v-if="transaction.year || transaction.semester" class="border-t pt-4">
                    <p class="mb-1 text-xs font-medium text-gray-500 uppercase">Academic Term</p>
                    <p class="text-lg font-semibold">
                        {{ [transaction.year, transaction.semester].filter(Boolean).join(' ') }}
                    </p>
                </div>

                <!-- Payment Info -->
                <div v-if="transaction.kind === 'payment'" class="border-t pt-4">
                    <h3 class="mb-3 text-base font-semibold">Payment Information</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase">Method</p>
                            <p class="mt-1 font-medium capitalize">
                                {{ transaction.payment_channel?.replace(/_/g, ' ') || 'N/A' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase">Payment Date</p>
                            <p class="mt-1 font-medium">{{ transaction.paid_at ? formatDate(transaction.paid_at) : 'N/A' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase">OR Number</p>
                            <p class="mt-1 font-mono font-medium">{{ transaction.or_number ?? '—' }}</p>
                        </div>
                        <div v-if="transaction.meta?.term_name">
                            <p class="text-xs font-medium text-gray-500 uppercase">Applied to Term</p>
                            <p class="mt-1 font-medium">{{ transaction.meta.term_name }}</p>
                        </div>
                        <div v-if="transaction.meta?.description">
                            <p class="text-xs font-medium text-gray-500 uppercase">Description</p>
                            <p class="mt-1 font-medium">{{ transaction.meta.description }}</p>
                        </div>
                    </div>
                </div>

                <!-- Student info (visible when staff sees a student's transaction) -->
                <div v-if="transaction.user" class="border-t pt-4">
                    <h3 class="mb-3 text-base font-semibold">Student</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase">Name</p>
                            <p class="mt-1 font-medium">{{ transaction.user.name }}</p>
                        </div>
                        <div v-if="transaction.user.account_id">
                            <p class="text-xs font-medium text-gray-500 uppercase">Student No.</p>
                            <p class="mt-1 font-medium">{{ transaction.user.account_id }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-medium text-gray-500 uppercase">Email</p>
                            <p class="mt-1 font-medium">{{ transaction.user.email }}</p>
                        </div>
                    </div>
                </div>

                <!-- Overall Balance -->
                <div class="rounded-lg border-t pt-4">
                    <div :class="['rounded-lg p-4', hasCredit ? 'bg-green-50' : 'bg-blue-50']">
                        <p class="text-xs font-medium text-gray-500 uppercase">Overall Remaining Balance</p>
                        <p
                            class="mt-1 text-3xl font-bold"
                            :class="hasCredit ? 'text-green-600' : accountBalance > 0 ? 'text-red-600' : 'text-green-600'"
                        >
                            {{ hasCredit ? '−' : '' }}{{ formatCurrency(displayBalance) }}
                        </p>
                        <p v-if="hasCredit" class="mt-1 text-sm text-green-600">You have a credit balance.</p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-3 border-t pt-4">
                    <Link :href="route('transactions.index')" class="text-sm text-blue-600 hover:underline"> ← Back to Transactions </Link>
                    <div class="ml-auto">
                        <button
                            @click="downloadReceipt"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-700"
                        >
                            📄 Download Receipt
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
