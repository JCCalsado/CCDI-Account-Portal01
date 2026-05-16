<?php

namespace App\Http\Requests;

use App\Models\Notification;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationRequest extends FormRequest
{
    /**
     * Only Accounting staff may update notifications.
     */
    public function authorize(): bool
    {
        /** @var Notification $notification */
        $notification = $this->route('notification');

        return $this->user()->can('update', $notification);
    }

    public function rules(): array
    {
        return [
            // ── Content ──────────────────────────────────────────────────────
            'title'   => ['required', 'string', 'min:3', 'max:150'],
            'message' => ['nullable', 'string', 'max:2000'],

            // ── Classification ────────────────────────────────────────────────
            'type' => [
                'nullable',
                'string',
                'in:general,reminder,warning,deadline,announcement,payment_due,payment_due_notice,payment_approved,payment_rejected',
            ],
            'priority' => [
                'required',
                'string',
                'in:low,medium,high,urgent',
            ],
            'notification_status' => [
                'required',
                'string',
                'in:draft,scheduled,active,expired',
            ],

            // ── Dates ─────────────────────────────────────────────────────────
            // start_date is still required on update — it defines the visible window.
            'start_date' => ['required', 'date'],
            'end_date'   => ['nullable', 'date', 'after_or_equal:start_date'],
            'due_date'   => [
                'nullable',
                'date',
                function ($attribute, $value, $fail) {
                    $type         = $this->input('type');
                    $dueDateTypes = ['payment_due', 'payment_due_notice', 'deadline'];
                    if (in_array($type, $dueDateTypes, true) && empty($value)) {
                        $fail("A due date is required when notification type is "{$type}".");
                    }
                    if ($value && $value < now()->toDateString()) {
                        $fail('The due date must be today or a future date.');
                    }
                },
            ],

            // ── Payment term linking ──────────────────────────────────────────
            'payment_term_id' => ['nullable', 'integer', 'exists:student_payment_terms,id'],

            // ── Audience ──────────────────────────────────────────────────────
            'target_role' => ['required', 'string', 'in:student,accounting,admin,all'],
            'user_id'     => ['nullable', 'integer', 'exists:users,id'],
            'user_ids'    => ['nullable', 'array'],
            'user_ids.*'  => ['integer', 'exists:users,id'],

            // ── Audience filters ──────────────────────────────────────────────
            'course_filter'       => ['nullable', 'array'],
            'course_filter.*'     => ['string', 'max:120'],
            'year_level_filter'   => ['nullable', 'array'],
            'year_level_filter.*' => ['string', 'max:50'],
            'balance_filter'      => ['required', 'string', 'in:any,with_balance,overdue'],

            // ── Term targeting ────────────────────────────────────────────────
            'term_ids'                => ['nullable', 'array'],
            'term_ids.*'              => ['integer', 'exists:student_payment_terms,id'],
            'target_term_name'        => ['nullable', 'string', 'in:Upon Registration,Prelim,Midterm,Semi-Final,Final'],
            'trigger_days_before_due' => ['nullable', 'integer', 'min:0', 'max:90'],

            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.min'              => 'Title must be at least 3 characters.',
            'title.max'              => 'Title may not exceed 150 characters.',
            'message.max'            => 'Message may not exceed 2,000 characters.',
            'priority.in'            => 'Priority must be low, medium, high, or urgent.',
            'notification_status.in' => 'Status must be draft, scheduled, active, or expired.',
            'target_role.in'         => 'Invalid audience selection.',
            'balance_filter.in'      => 'Balance filter must be any, with_balance, or overdue.',
        ];
    }
}