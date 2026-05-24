/**
 * Comprehensive data formatting composable for student UI
 * Handles currency, dates, statuses, roles, and null-safe rendering
 */

// ============================================================================
// TYPE DEFINITIONS
// ============================================================================

// 'processed' = term received a partial payment; remaining balance was carried
// forward to the next term. The term balance is 0 but the student has NOT paid
// in full. Visually distinct from 'paid' (fully settled) and 'partial' (balance
// remains on this term). Mirrors PaymentStatus::PROCESSED in the PHP backend.
export type PaymentTermStatus = 'pending' | 'partial' | 'paid' | 'overdue' | 'awaiting_approval' | 'processed';
export type TransactionStatus = 'pending' | 'awaiting_approval' | 'paid' | 'failed' | 'cancelled';
export type AssessmentStatus = 'active' | 'graduated' | 'archived' | 'pending';
export type TransactionType = 'payment' | 'charge' | 'refund' | 'adjustment';
export type UserRole = 'student' | 'admin' | 'accounting' | string;

export interface StatusConfig {
    label: string;
    color: 'success' | 'danger' | 'warning' | 'info' | 'muted';
    bgClass: string;
    textClass: string;
}

// ============================================================================
// FORMATTING FUNCTIONS
// ============================================================================

/**
 * Format amount as Philippine Peso currency
 * @param amount - Numeric amount to format
 * @param showSymbol - Whether to show the PHP symbol (default: true)
 */
export function formatCurrency(amount: number | null | undefined, showSymbol = true): string {
    if (amount === null || amount === undefined) {
        return showSymbol ? '₱0.00' : '0.00';
    }

    try {
        const num = typeof amount === 'number' ? amount : parseFloat(amount as unknown as string);
        const formatted = num.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
        return showSymbol ? `₱${formatted}` : formatted;
    } catch {
        const numStr = (typeof amount === 'number' ? amount : parseFloat(amount as unknown as string)).toFixed(2);
        return showSymbol ? `₱${numStr}` : numStr;
    }
}

/**
 * Format date with various display options
 * @param date - Date string or Date object
 * @param format - 'short' | 'long' | 'full' | 'monthDay'
 */
export function formatDate(date: string | Date | null | undefined, format: 'short' | 'long' | 'full' | 'monthDay' = 'long'): string {
    if (!date) {
        return '-';
    }

    try {
        const dateObj = typeof date === 'string' ? new Date(date) : date;

        if (isNaN(dateObj.getTime())) {
            return '-';
        }

        const optionsMap: Record<string, Intl.DateTimeFormatOptions> = {
            short: { year: '2-digit', month: '2-digit', day: '2-digit' },
            long: { year: 'numeric', month: 'long', day: 'numeric' },
            full: { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' },
            monthDay: { month: 'short', day: 'numeric' },
        };

        const options = optionsMap[format];
        return dateObj.toLocaleDateString('en-US', options);
    } catch {
        return '-';
    }
}

/**
 * Format date and time together
 */
export function formatDateTime(date: string | Date | null | undefined, timeFormat: 'short' | 'long' = 'short'): string {
    if (!date) {
        return '-';
    }

    try {
        const dateObj = typeof date === 'string' ? new Date(date) : date;

        if (isNaN(dateObj.getTime())) {
            return '-';
        }

        const dateStr = dateObj.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });

        const timeOptionsMap: Record<string, Intl.DateTimeFormatOptions> = {
            short: { hour: '2-digit', minute: '2-digit' },
            long: { hour: '2-digit', minute: '2-digit', second: '2-digit' },
        };

        const timeStr = dateObj.toLocaleTimeString('en-US', timeOptionsMap[timeFormat]);

        return `${dateStr} ${timeStr}`;
    } catch {
        return '-';
    }
}

// ============================================================================
// STATUS FORMATTING
// ============================================================================

/**
 * Get formatted label and color classes for payment term status.
 *
 * Status values and their meanings:
 *   pending           — Term has not received any payment yet.
 *   partial           — Term received a partial payment; remaining balance
 *                       stays on this term (student pays later).
 *   paid              — Term is fully settled.
 *   overdue           — Past due date with unpaid balance.
 *   awaiting_approval — A student-submitted payment is pending accounting review.
 *   processed         — Term received a partial payment that was carried forward
 *                       to the next term via the one-time processing rule.
 *                       Balance is 0 but NOT fully paid — the remaining amount
 *                       was added to the next term. Use sky/teal to distinguish
 *                       from green (paid) and blue (partial/awaiting).
 */
export function getPaymentTermStatusConfig(status: PaymentTermStatus | string | null | undefined): StatusConfig {
    const statusMap: Record<string, StatusConfig> = {
        pending: {
            label: 'Pending',
            color: 'warning',
            bgClass: 'bg-yellow-100',
            textClass: 'text-yellow-800',
        },
        partial: {
            label: 'Partially Paid',
            color: 'info',
            bgClass: 'bg-blue-100',
            textClass: 'text-blue-800',
        },
        paid: {
            label: 'Paid',
            color: 'success',
            bgClass: 'bg-green-100',
            textClass: 'text-green-800',
        },
        overdue: {
            label: 'Overdue',
            color: 'danger',
            bgClass: 'bg-red-100',
            textClass: 'text-red-800',
        },
        awaiting_approval: {
            label: 'Awaiting Verification',
            color: 'info',
            bgClass: 'bg-blue-100',
            textClass: 'text-blue-800',
        },
        // processed: balance was carried forward to the next term.
        // Sky distinguishes it from green (paid) and blue (partial/awaiting).
        processed: {
            label: 'Carried Forward',
            color: 'info',
            bgClass: 'bg-sky-100',
            textClass: 'text-sky-800',
        },
    };

    return (
        statusMap[status as string] || {
            label: status ? String(status).charAt(0).toUpperCase() + String(status).slice(1) : 'Unknown',
            color: 'muted',
            bgClass: 'bg-gray-100',
            textClass: 'text-gray-800',
        }
    );
}

/**
 * Get formatted label and color classes for transaction/payment status
 */
export function getTransactionStatusConfig(status: TransactionStatus | string | null | undefined): StatusConfig {
    const statusMap: Record<string, StatusConfig> = {
        paid: {
            label: 'Paid',
            color: 'success',
            bgClass: 'bg-green-100',
            textClass: 'text-green-800',
        },
        pending: {
            label: 'Pending',
            color: 'warning',
            bgClass: 'bg-yellow-100',
            textClass: 'text-yellow-800',
        },
        awaiting_approval: {
            label: 'Awaiting Verification',
            color: 'info',
            bgClass: 'bg-blue-100',
            textClass: 'text-blue-800',
        },
        failed: {
            label: 'Failed',
            color: 'danger',
            bgClass: 'bg-red-100',
            textClass: 'text-red-800',
        },
        cancelled: {
            label: 'Cancelled',
            color: 'muted',
            bgClass: 'bg-gray-100',
            textClass: 'text-gray-800',
        },
    };

    return (
        statusMap[status as string] || {
            label: status ? String(status).charAt(0).toUpperCase() + String(status).slice(1) : 'Unknown',
            color: 'muted',
            bgClass: 'bg-gray-100',
            textClass: 'text-gray-800',
        }
    );
}

/**
 * Get formatted label and color classes for assessment status
 */
export function getAssessmentStatusConfig(status: AssessmentStatus | string | null | undefined): StatusConfig {
    const statusMap: Record<string, StatusConfig> = {
        active: {
            label: 'Active',
            color: 'success',
            bgClass: 'bg-green-100',
            textClass: 'text-green-800',
        },
        graduated: {
            label: 'Graduated',
            color: 'info',
            bgClass: 'bg-blue-100',
            textClass: 'text-blue-800',
        },
        archived: {
            label: 'Archived',
            color: 'muted',
            bgClass: 'bg-gray-100',
            textClass: 'text-gray-800',
        },
        pending: {
            label: 'Pending',
            color: 'warning',
            bgClass: 'bg-yellow-100',
            textClass: 'text-yellow-800',
        },
    };

    return (
        statusMap[status as string] || {
            label: status ? String(status).charAt(0).toUpperCase() + String(status).slice(1) : 'Unknown',
            color: 'muted',
            bgClass: 'bg-gray-100',
            textClass: 'text-gray-800',
        }
    );
}

/**
 * Get formatted label for user role
 */
export function formatUserRole(role: UserRole | null | undefined): string {
    const roleMap: Record<string, string> = {
        student: 'Student',
        admin: 'Administrator',
        accounting: 'Accounting Staff',
    };

    if (!role) return 'Unknown';
    return roleMap[role.toLowerCase()] || String(role).charAt(0).toUpperCase() + String(role).slice(1);
}

/**
 * Format transaction type to human-readable
 */
export function formatTransactionType(type: string | null | undefined): string {
    if (!type) return '-';

    const typeMap: Record<string, string> = {
        payment: 'Payment',
        charge: 'Charge',
        refund: 'Refund',
        adjustment: 'Adjustment',
    };

    return typeMap[type.toLowerCase()] || String(type).charAt(0).toUpperCase() + String(type).slice(1);
}

// ============================================================================
// NULL-SAFE RENDERING HELPERS
// ============================================================================

/**
 * Safely display currency or fallback message
 */
export function displayCurrency(amount: number | null | undefined, fallback = '-'): string {
    return amount === null || amount === undefined ? fallback : formatCurrency(amount);
}

/**
 * Safely display date or fallback message
 */
export function displayDate(date: string | Date | null | undefined, fallback = '-'): string {
    return !date ? fallback : formatDate(date);
}

/**
 * Safely get status label or fallback
 */
export function displayStatus(status: string | null | undefined, fallback = 'Unknown'): string {
    return !status ? fallback : String(status).charAt(0).toUpperCase() + String(status).slice(1);
}

/**
 * Safely format and display a value, with optional prefix/suffix
 */
export function displayValue(
    value: any,
    fallback = '-',
    options?: {
        prefix?: string;
        suffix?: string;
        formatter?: (v: any) => string;
    },
): string {
    if (value === null || value === undefined) {
        return fallback;
    }

    let formatted = options?.formatter ? options.formatter(value) : String(value);

    if (options?.prefix) formatted = options.prefix + formatted;
    if (options?.suffix) formatted = formatted + options.suffix;

    return formatted;
}

// ============================================================================
// COMPOSABLE EXPORT
// ============================================================================

export function useDataFormatting() {
    return {
        // Currency & numeric
        formatCurrency,
        displayCurrency,

        // Date & time
        formatDate,
        formatDateTime,
        displayDate,

        // Status formatting
        getPaymentTermStatusConfig,
        getTransactionStatusConfig,
        getAssessmentStatusConfig,

        // Display helpers
        formatUserRole,
        formatTransactionType,
        displayStatus,
        displayValue,
    };
}