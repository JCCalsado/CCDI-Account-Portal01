/**
 * useNotificationTypeConfig
 *
 * Centralized notification type configuration.
 * Used by Student/Dashboard.vue (sidebar widget) and Notifications/Index.vue (full page).
 * Update once here, sync everywhere.
 */

import { computed } from 'vue'
import {
    CalendarClock,
    CheckCircle,
    XCircle,
    AlertCircle,
    Bell,
    Megaphone,
} from 'lucide-vue-next'

export interface NotificationTypeConfig {
    borderClass: string
    badgeClass: string
    icon: any
    iconClass: string
    hasDueDate: boolean
    hasPayNow: boolean
}

export function useNotificationTypeConfig() {
    const notifTypeConfig = computed(() => ({
        payment_due: {
            borderClass: 'border-l-4 border-l-amber-500',
            badgeClass: 'bg-amber-100 text-amber-800',
            icon: CalendarClock,
            iconClass: 'text-amber-500',
            hasDueDate: true,
            hasPayNow: true,
        },
        payment_due_notice: {
            borderClass: 'border-l-4 border-l-amber-400',
            badgeClass: 'bg-amber-100 text-amber-700',
            icon: CalendarClock,
            iconClass: 'text-amber-400',
            hasDueDate: true,
            hasPayNow: true,
        },
        deadline: {
            borderClass: 'border-l-4 border-l-red-500',
            badgeClass: 'bg-red-100 text-red-800',
            icon: AlertCircle,
            iconClass: 'text-red-500',
            hasDueDate: true,
            hasPayNow: false,
        },
        warning: {
            borderClass: 'border-l-4 border-l-orange-500',
            badgeClass: 'bg-orange-100 text-orange-800',
            icon: AlertCircle,
            iconClass: 'text-orange-500',
            hasDueDate: false,
            hasPayNow: false,
        },
        payment_approved: {
            borderClass: 'border-l-4 border-l-emerald-500',
            badgeClass: 'bg-emerald-100 text-emerald-800',
            icon: CheckCircle,
            iconClass: 'text-emerald-500',
            hasDueDate: false,
            hasPayNow: false,
        },
        payment_rejected: {
            borderClass: 'border-l-4 border-l-red-500',
            badgeClass: 'bg-red-100 text-red-800',
            icon: XCircle,
            iconClass: 'text-red-500',
            hasDueDate: false,
            hasPayNow: false,
        },
        reminder: {
            borderClass: 'border-l-4 border-l-blue-400',
            badgeClass: 'bg-blue-100 text-blue-800',
            icon: Bell,
            iconClass: 'text-blue-400',
            hasDueDate: false,
            hasPayNow: false,
        },
        announcement: {
            borderClass: 'border-l-4 border-l-blue-500',
            badgeClass: 'bg-blue-100 text-blue-800',
            icon: Megaphone,
            iconClass: 'text-blue-500',
            hasDueDate: false,
            hasPayNow: false,
        },
        general: {
            borderClass: 'border-l-4 border-l-blue-400',
            badgeClass: 'bg-blue-100 text-blue-700',
            icon: Megaphone,
            iconClass: 'text-blue-400',
            hasDueDate: false,
            hasPayNow: false,
        },
    } as Record<string, NotificationTypeConfig>))

    /**
     * Get config for a specific notification type.
     * Defaults to 'general' if type not found.
     */
    const getTypeConfig = (type?: string | null): NotificationTypeConfig => {
        return notifTypeConfig.value[type ?? 'general'] ?? notifTypeConfig.value.general
    }

    return {
        notifTypeConfig,
        getTypeConfig,
    }
}
