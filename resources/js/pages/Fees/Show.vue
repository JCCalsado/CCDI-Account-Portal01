<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { useDataFormatting } from '@/composables/useDataFormatting';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
const { formatCurrency } = useDataFormatting();

type Fee = {
    id: number;
    code: string;
    name: string;
    category: string;
    amount: number;
    year_level: string;
    semester: string;
    school_year: string;
    description: string | null;
    is_active: boolean;
    created_at: string;
    transactions?: any[];
};

const props = defineProps<{
    fee: Fee;
}>();

const breadcrumbs = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Fee Management', href: route('fees.index') },
    { title: props.fee.name },
];

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};
</script>

<template>
    <AppLayout>
        <Head :title="fee.name" />

        <div class="w-full p-6">
            <Breadcrumbs :items="breadcrumbs" />

            <div class="mx-auto max-w-4xl">
                <!-- Header -->
                <div class="mb-6 flex items-start justify-between">
                    <div>
                        <h1 class="text-3xl font-bold">{{ fee.name }}</h1>
                        <p class="mt-1 text-gray-600">{{ fee.code }}</p>
                    </div>
                    <Link :href="route('fees.edit', fee.id)" class="rounded bg-blue-600 px-4 py-2 text-white hover:bg-blue-700"> Edit Fee </Link>
                </div>

                <!-- Fee Details -->
                <div class="mb-6 rounded-lg bg-white p-6 shadow-md">
                    <h2 class="mb-4 text-xl font-semibold">Fee Details</h2>

                    <div class="grid grid-cols-2 gap-6">
                        <div>
                            <p class="text-sm text-gray-600">Category</p>
                            <p class="font-medium">{{ fee.category }}</p>
                        </div>

                        <div>
                            <p class="text-sm text-gray-600">Amount</p>
                            <p class="text-2xl font-bold text-blue-600">{{ formatCurrency(fee.amount) }}</p>
                        </div>

                        <div>
                            <p class="text-sm text-gray-600">Year Level</p>
                            <p class="font-medium">{{ fee.year_level }}</p>
                        </div>

                        <div>
                            <p class="text-sm text-gray-600">Semester</p>
                            <p class="font-medium">{{ fee.semester }}</p>
                        </div>

                        <div>
                            <p class="text-sm text-gray-600">School Year</p>
                            <p class="font-medium">{{ fee.school_year }}</p>
                        </div>

                        <div>
                            <p class="text-sm text-gray-600">Status</p>
                            <span
                                :class="fee.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                                class="rounded-full px-3 py-1 text-sm font-medium"
                            >
                                {{ fee.is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                    </div>

                    <div v-if="fee.description" class="mt-6 border-t pt-6">
                        <p class="mb-2 text-sm text-gray-600">Description</p>
                        <p class="text-gray-800">{{ fee.description }}</p>
                    </div>

                    <div class="mt-6 border-t pt-6">
                        <p class="text-sm text-gray-600">Created</p>
                        <p class="text-gray-800">{{ formatDate(fee.created_at) }}</p>
                    </div>
                </div>

                <!-- Recent Transactions -->
                <div v-if="fee.transactions && fee.transactions.length" class="rounded-lg bg-white p-6 shadow-md">
                    <h2 class="mb-4 text-xl font-semibold">Recent Transactions</h2>

                    <div class="space-y-3">
                        <div v-for="txn in fee.transactions" :key="txn.id" class="border-b pb-3 last:border-b-0">
                            <div class="flex items-start justify-between">
                                <div>
                                    <p class="font-medium">{{ txn.user?.name || 'N/A' }}</p>
                                    <p class="text-sm text-gray-600">OR: {{ txn.or_number ?? txn.reference }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold">{{ formatCurrency(txn.amount) }}</p>
                                    <p class="text-sm text-gray-600">{{ formatDate(txn.created_at) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
