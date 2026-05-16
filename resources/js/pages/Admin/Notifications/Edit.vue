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

interface Notification {
    id: number;
    title: string;
    message: string | null;
    type: string | null;
    priority: string | null;
    notification_status: string | null;
    target_role: string;
    start_date: string | null;
    end_date: string | null;
    due_date: string | null;
    payment_term_id: number | null;
    user_id: number | null;
    user_ids: number[] | null;
    is_active: boolean;
    term_ids: number[] | null;
    target_term_name: string | null;
    trigger_days_before_due: number | null;
    course_filter: string[] | null;
    year_level_filter: string[] | null;
    balance_filter: string | null;
}

interface Props {
    notification: Notification;
    students: Student[];
    paymentTerms: PaymentTerm[];
    courses: string[];
    yearLevels: string[];
}

defineProps<Props>();
</script>

<template>
    <Head title="Edit Notification" />
    <Form
        :notification="notification"
        :students="students"
        :payment-terms="paymentTerms"
        :courses="courses"
        :year-levels="yearLevels"
    />
</template>