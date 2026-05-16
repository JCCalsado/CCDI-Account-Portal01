<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNotificationRequest;
use App\Http\Requests\UpdateNotificationRequest;
use App\Mail\AccountNotification;
use App\Models\Notification;
use App\Models\StudentAssessment;
use App\Models\StudentPaymentTerm;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class NotificationController extends Controller
{
    /**
     * List of valid course names in the system.
     * Duplicated here for validation; consider moving to config/constants.php.
     */
    private const COURSES = [
        'BS Information Technology',
        'BS Computer Science',
        'BS Electronics and Communications Engineering',
        'BS Electrical Engineering',
        'BS Mechanical Engineering',
        'BS Civil Engineering',
        'BS Business Administration',
        'BS Accounting Information Systems',
    ];

    private const YEAR_LEVELS = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

    // =========================================================================
    // Routing entry point
    // =========================================================================

    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->isAdmin() || $user->isAccounting()) {
            // Paginated — returns {data, links, meta} to the frontend.
            $paginated = Notification::orderByDesc('created_at')
                ->paginate(20)
                ->through(fn ($n) => $this->mapNotificationForAdmin($n));

            return Inertia::render('Admin/Notifications/Index', [
                'notifications' => $paginated,
                'role'          => $user->role,
            ]);
        }

        return $this->studentIndex($request);
    }

    // =========================================================================
    // Student-facing index
    // =========================================================================

    public function studentIndex(Request $request): \Inertia\Response
    {
        $user = $request->user();

        $active = Notification::active()
            ->forUser($user->id)
            ->withinDateRange()
            ->forDueDateTrigger($user)
            ->forBalance($user)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($n) => $this->mapNotification($n));

        $history = Notification::where('is_active', true)
            ->forUser($user->id)
            ->where(function ($q) {
                $q->whereNotNull('dismissed_at')
                  ->orWhere('is_complete', true)
                  ->orWhere(function ($q2) {
                      $today = now()->toDateString();
                      $q2->whereNotNull('end_date')->where('end_date', '<', $today);
                  });
            })
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn ($n) => $this->mapNotification($n));

        Notification::active()
            ->forUser($user->id)
            ->withinDateRange()
            ->forDueDateTrigger($user)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        Cache::forget("unread_notifications_count:{$user->id}");

        return Inertia::render('Notifications/Index', [
            'active'  => $active,
            'history' => $history,
        ]);
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();

        Notification::active()
            ->forUser($user->id)
            ->withinDateRange()
            ->forDueDateTrigger($user)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        Cache::forget("unread_notifications_count:{$user->id}");

        return back();
    }

    // =========================================================================
    // CRUD — Accounting only (enforced by Form Requests + Policy)
    // =========================================================================

    public function create()
    {
        $this->authorize('create', Notification::class);

        return Inertia::render('Admin/Notifications/Create', [
            'students'     => $this->resolveStudentsList(),
            'paymentTerms' => $this->resolvePaymentTermsList(),
            'courses'      => self::COURSES,
            'yearLevels'   => self::YEAR_LEVELS,
        ]);
    }

    public function store(StoreNotificationRequest $request)
    {
        $validated = $this->prepareValidatedData($request->validated());

        DB::transaction(function () use ($validated) {
            Notification::create($validated);
        });

        // Only dispatch emails when the notification is being published (active).
        if ($validated['notification_status'] === 'active') {
            $this->syncDueDateToPaymentTerms($validated);
            $this->dispatchNotificationEmails($validated);
        }

        return redirect('/accounting/notifications')
            ->with('success', 'Notification created successfully.');
    }

    public function show(Notification $notification)
    {
        $this->authorize('view', $notification);

        return Inertia::render('Admin/Notifications/Show', [
            'notification' => $notification,
        ]);
    }

    public function edit(Notification $notification)
    {
        $this->authorize('update', $notification);

        return Inertia::render('Admin/Notifications/Edit', [
            'notification' => $notification,
            'students'     => $this->resolveStudentsList(),
            'paymentTerms' => $this->resolvePaymentTermsList(),
            'courses'      => self::COURSES,
            'yearLevels'   => self::YEAR_LEVELS,
        ]);
    }

    public function update(UpdateNotificationRequest $request, Notification $notification)
    {
        $validated = $this->prepareValidatedData($request->validated());

        DB::transaction(function () use ($notification, $validated) {
            $notification->update($validated);
        });

        if ($validated['notification_status'] === 'active') {
            $this->syncDueDateToPaymentTerms($validated);
            $this->dispatchNotificationEmails($validated);
        }

        return redirect('/accounting/notifications')
            ->with('success', 'Notification updated successfully.');
    }

    public function destroy(Notification $notification)
    {
        $this->authorize('delete', $notification);
        $notification->delete();

        return redirect('/accounting/notifications')
            ->with('success', 'Notification deleted successfully.');
    }

    public function dismiss(Request $request, Notification $notification)
    {
        $user = $request->user();

        if (! $user->isAdmin()) {
            if ($notification->user_id !== null && $notification->user_id !== $user->id) {
                abort(403, 'You are not authorised to dismiss this notification.');
            }

            if ($notification->user_ids !== null && ! in_array($user->id, array_map('intval', $notification->user_ids), true)) {
                abort(403, 'You are not authorised to dismiss this notification.');
            }

            if ($notification->user_id === null && $notification->user_ids === null) {
                $roleString = $user->role instanceof \BackedEnum
                    ? $user->role->value
                    : (string) $user->role;

                if (! in_array($notification->target_role, [$roleString, 'all'], true)) {
                    abort(403, 'You are not authorised to dismiss this notification.');
                }
            }
        }

        $notification->markDismissed();
        Cache::forget("unread_notifications_count:{$user->id}");

        return back()->with('success', 'Notification dismissed.');
    }

    // =========================================================================
    // Email Dispatch
    // =========================================================================

    private function dispatchNotificationEmails(array $data): void
    {
        try {
            $recipients = $this->resolveEmailRecipients($data);

            if ($recipients->isEmpty()) {
                Log::info('NotificationController: no email recipients resolved', [
                    'target_role' => $data['target_role'] ?? null,
                    'user_id'     => $data['user_id']     ?? null,
                ]);
                return;
            }

            $actionUrl   = null;
            $actionLabel = null;
            $type        = $data['type'] ?? 'general';

            if (in_array($type, ['payment_due', 'payment_due_notice', 'deadline'], true)) {
                $actionUrl   = route('student.account', ['tab' => 'payment']);
                $actionLabel = 'View Payment Details';
            }

            $emailType = match ($type) {
                'payment_due', 'payment_due_notice', 'deadline', 'warning' => 'warning',
                'payment_approved'                                          => 'success',
                'payment_rejected'                                          => 'error',
                default                                                     => 'info',
            };

            $queued = 0;

            foreach ($recipients as $user) {
                if (empty($user->email)) {
                    continue;
                }

                Mail::to($user->email)->queue(
                    new AccountNotification(
                        studentName:         "{$user->first_name} {$user->last_name}",
                        notificationTitle:   $data['title'],
                        notificationMessage: $data['message'] ?? '',
                        notificationType:    $emailType,
                        actionUrl:           $actionUrl,
                        actionLabel:         $actionLabel,
                        dueDate:             $data['due_date']    ?? null,
                        startDate:           $data['start_date']  ?? null,
                        endDate:             $data['end_date']    ?? null,
                    )
                );

                $queued++;
            }

            Log::info('NotificationController: queued notification emails', [
                'queued' => $queued,
                'type'   => $type,
                'title'  => $data['title'],
            ]);

        } catch (\Throwable $e) {
            Log::error('NotificationController: failed to dispatch notification emails', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Resolve email recipients with audience filter support.
     *
     * Priority chain:
     *   1. user_ids (explicit multi-student)
     *   2. user_id  (single student)
     *   3. Role-based, optionally filtered by course + year_level + balance
     */
    private function resolveEmailRecipients(array $data): \Illuminate\Database\Eloquent\Collection
    {
        // 1. Explicit multi-student list — no further filtering needed.
        if (! empty($data['user_ids']) && is_array($data['user_ids'])) {
            return User::whereIn('id', $data['user_ids'])
                ->whereNotNull('email')
                ->get();
        }

        // 2. Single user.
        if (! empty($data['user_id'])) {
            return User::where('id', $data['user_id'])
                ->whereNotNull('email')
                ->get();
        }

        $role = $data['target_role'] ?? null;

        // 3a. All users.
        if ($role === 'all') {
            $query = User::whereNotNull('email')->where('is_active', true);
            return $this->applyAudienceFilters($query, $data)->get();
        }

        // 3b. Role-specific.
        if (in_array($role, ['student', 'accounting', 'admin'], true)) {
            $query = User::where('role', $role)
                ->whereNotNull('email')
                ->where('is_active', true);

            // Audience filters only make sense for students.
            if ($role === 'student') {
                $query = $this->applyAudienceFilters($query, $data);
            }

            return $query->get();
        }

        return collect();
    }

    /**
     * Apply course, year-level, and balance filters to a User query builder.
     * Joins through student_assessments for course/year_level matching.
     */
    private function applyAudienceFilters(Builder $query, array $data): Builder
    {
        $courseFilter    = array_filter((array) ($data['course_filter']    ?? []));
        $yearFilter      = array_filter((array) ($data['year_level_filter'] ?? []));
        $balanceFilter   = $data['balance_filter'] ?? 'any';
        $today           = now()->toDateString();

        $needsAssessmentJoin = ! empty($courseFilter) || ! empty($yearFilter)
                            || in_array($balanceFilter, ['with_balance', 'overdue'], true);

        if (! $needsAssessmentJoin) {
            return $query;
        }

        // Join student_assessments to apply course/year_level filters.
        $query->whereExists(function ($sub) use ($courseFilter, $yearFilter, $balanceFilter, $today) {
            $sub->from('student_assessments')
                ->whereColumn('student_assessments.user_id', 'users.id')
                ->where('student_assessments.status', 'active');

            if (! empty($courseFilter)) {
                $sub->whereIn('student_assessments.course', $courseFilter);
            }

            if (! empty($yearFilter)) {
                $sub->whereIn('student_assessments.year_level', $yearFilter);
            }

            if ($balanceFilter === 'with_balance') {
                $sub->whereExists(function ($termSub) {
                    $termSub->from('student_payment_terms')
                            ->whereColumn('student_payment_terms.student_assessment_id', 'student_assessments.id')
                            ->where('student_payment_terms.balance', '>', 0);
                });
            } elseif ($balanceFilter === 'overdue') {
                $sub->whereExists(function ($termSub) use ($today) {
                    $termSub->from('student_payment_terms')
                            ->whereColumn('student_payment_terms.student_assessment_id', 'student_assessments.id')
                            ->where('student_payment_terms.balance', '>', 0)
                            ->whereNotNull('student_payment_terms.due_date')
                            ->where('student_payment_terms.due_date', '<', $today);
                });
            }
        });

        return $query;
    }

    // =========================================================================
    // Due Date Sync
    // =========================================================================

    /**
     * Sync due_date to student_payment_terms when a notification of a
     * due-date type is saved.
     *
     * Fires for: payment_due, payment_due_notice, deadline.
     * Priority: term_ids > payment_term_id > target_term_name scoped to audience.
     *
     * RISK: broad updates are possible with course/year_level filters.
     * Guard: at least one scoping constraint must be set for course/year broadcasts.
     */
    private function syncDueDateToPaymentTerms(array $data): void
    {
        $dueDateTypes = ['payment_due', 'payment_due_notice', 'deadline'];

        if (! in_array($data['type'] ?? '', $dueDateTypes, true)) {
            return;
        }

        $dueDate = $data['due_date'] ?? null;
        if (! $dueDate) {
            return;
        }

        try {
            // 1. Explicit term ID list — highest priority.
            if (! empty($data['term_ids'])) {
                StudentPaymentTerm::whereIn('id', $data['term_ids'])
                    ->update(['due_date' => $dueDate]);
                return;
            }

            // 2. Single payment term.
            if (! empty($data['payment_term_id'])) {
                StudentPaymentTerm::where('id', $data['payment_term_id'])
                    ->update(['due_date' => $dueDate]);
                return;
            }

            // 3. Term name scoped to audience.
            if (! empty($data['target_term_name'])) {
                $this->syncByTermName($dueDate, $data);
            }

        } catch (\Throwable $e) {
            Log::error('NotificationController: failed to sync due_date to payment terms', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncByTermName(string $dueDate, array $data): void
    {
        $courseFilter  = array_filter((array) ($data['course_filter']    ?? []));
        $yearFilter    = array_filter((array) ($data['year_level_filter'] ?? []));
        $termName      = $data['target_term_name'];

        // If course/year filters are set, scope through student_assessments.
        if (! empty($courseFilter) || ! empty($yearFilter)) {
            $assessmentIds = StudentAssessment::query()
                ->when(! empty($courseFilter), fn ($q) => $q->whereIn('course', $courseFilter))
                ->when(! empty($yearFilter),   fn ($q) => $q->whereIn('year_level', $yearFilter))
                ->pluck('id');

            if ($assessmentIds->isEmpty()) {
                return;
            }

            StudentPaymentTerm::whereIn('student_assessment_id', $assessmentIds)
                ->where('term_name', $termName)
                ->update(['due_date' => $dueDate]);

            return;
        }

        // Scope to specific users if provided.
        $query = StudentPaymentTerm::where('term_name', $termName);

        if (! empty($data['user_id'])) {
            $query->whereHas('studentAssessment', fn ($q) => $q->where('user_id', $data['user_id']));
        } elseif (! empty($data['user_ids'])) {
            $query->whereHas('studentAssessment', fn ($q) => $q->whereIn('user_id', $data['user_ids']));
        }

        $query->update(['due_date' => $dueDate]);
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Prepare validated data before persistence:
     *   - Derive is_active / is_complete from notification_status
     *   - Normalise nulls for empty arrays/strings
     *   - Resolve user targeting priority
     */
    private function prepareValidatedData(array $data): array
    {
        // Derive boolean flags from notification_status so they stay in sync.
        $data = array_merge($data, Notification::deriveActiveFlagsFromStatus(
            $data['notification_status'] ?? 'draft'
        ));

        // Multi-student takes priority over single user_id.
        if (! empty($data['user_ids'])) {
            $data['user_id']     = null;
            $data['target_role'] = 'student';
        } elseif (! empty($data['user_id'])) {
            $data['user_ids']    = null;
            $data['target_role'] = 'student';
        }

        // Normalise empty strings / arrays to null.
        $nullIfEmpty = ['target_term_name', 'due_date', 'end_date'];
        foreach ($nullIfEmpty as $key) {
            if (isset($data[$key]) && $data[$key] === '') {
                $data[$key] = null;
            }
        }

        $emptyArrayToNull = ['term_ids', 'user_ids', 'course_filter', 'year_level_filter'];
        foreach ($emptyArrayToNull as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && count($data[$key]) === 0) {
                $data[$key] = null;
            }
        }

        return $data;
    }

    private function mapNotificationForAdmin(Notification $n): array
    {
        return [
            'id'                      => $n->id,
            'title'                   => $n->title,
            'message'                 => $n->message,
            'type'                    => $n->type,
            'priority'                => $n->priority ?? 'medium',
            'notification_status'     => $n->notification_status ?? 'draft',
            'target_role'             => $n->target_role,
            'start_date'              => $n->start_date?->toDateString(),
            'end_date'                => $n->end_date?->toDateString(),
            'due_date'                => $n->due_date?->toDateString(),
            'payment_term_id'         => $n->payment_term_id,
            'is_active'               => $n->is_active,
            'is_complete'             => $n->is_complete,
            'target_term_name'        => $n->target_term_name,
            'term_ids'                => $n->term_ids,
            'trigger_days_before_due' => $n->trigger_days_before_due,
            'user_id'                 => $n->user_id,
            'user_ids'                => $n->user_ids,
            'course_filter'           => $n->course_filter,
            'year_level_filter'       => $n->year_level_filter,
            'balance_filter'          => $n->balance_filter ?? 'any',
            'dismissed_at'            => $n->dismissed_at?->toDateTimeString(),
            'created_at'              => $n->created_at->toDateString(),
            'updated_at'              => $n->updated_at->toDateString(),
        ];
    }

    private function mapNotification(Notification $n): array
    {
        return [
            'id'              => $n->id,
            'title'           => $n->title,
            'message'         => $n->message,
            'type'            => $n->type,
            'priority'        => $n->priority ?? 'medium',
            'start_date'      => $n->start_date?->toDateString(),
            'end_date'        => $n->end_date?->toDateString(),
            'due_date'        => $n->due_date?->toDateString(),
            'payment_term_id' => $n->payment_term_id,
            'target_role'     => $n->target_role,
            'is_active'       => $n->is_active,
            'is_complete'     => $n->is_complete,
            'dismissed_at'    => $n->dismissed_at?->toDateTimeString(),
            'created_at'      => $n->created_at->toDateTimeString(),
        ];
    }

    private function resolveStudentsList(): \Illuminate\Support\Collection
    {
        return User::whereRole('student')
            ->select('id', 'first_name', 'last_name', 'middle_initial', 'email')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn ($s) => [
                'id'    => $s->id,
                'name'  => "{$s->last_name}, {$s->first_name}" . ($s->middle_initial ? " {$s->middle_initial}." : ''),
                'email' => $s->email,
            ]);
    }

    private function resolvePaymentTermsList(): \Illuminate\Database\Eloquent\Collection
    {
        return StudentPaymentTerm::distinct()
            ->orderBy('term_order')
            ->get(['id', 'term_name', 'term_order']);
    }
}