<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Form from './Form.vue';

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

interface Props {
    students: Student[];
    paymentTerms: PaymentTerm[];
    courses: string[];
    yearLevels: string[];
}

defineProps<Props>();
</script>

<template>
    <Head title="Create Notification" />
    <Form
        :students="students"
        :payment-terms="paymentTerms"
        :courses="courses"
        :year-levels="yearLevels"
    />
</template>