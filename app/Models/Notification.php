<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Custom Admin Notification Model
 *
 * Stored in `admin_notifications` — separate from Laravel's built-in
 * `notifications` table. See docs/NOTIFICATION_ARCHITECTURE.md.
 *
 * Lifecycle (notification_status):
 *   draft      → is_active = false  — invisible to students
 *   scheduled  → is_active = false  — Kernel activates on start_date
 *   active     → is_active = true   — visible within date/trigger window
 *   expired    → is_active = false, is_complete = true
 *
 * The legacy `is_active` and `is_complete` booleans are kept in sync by
 * NotificationController::deriveStatusFields() whenever a notification is
 * saved. They remain the authoritative signal for all student-facing scopes
 * (scopeActive, scopeForUser, etc.) so existing listeners and commands are
 * not broken.
 */
class Notification extends Model
{
    use HasFactory;

    protected $table = 'admin_notifications';

    protected $fillable = [
        'title',
        'message',
        'type',
        'priority',
        'notification_status',
        'start_date',
        'end_date',
        'due_date',
        'payment_term_id',
        'target_role',
        'user_id',
        'user_ids',
        'is_active',
        'is_complete',
        'dismissed_at',
        'read_at',
        'term_ids',
        'target_term_name',
        'trigger_days_before_due',
        'course_filter',
        'year_level_filter',
        'balance_filter',
    ];

    protected $casts = [
        'start_date'          => 'date',
        'end_date'            => 'date',
        'due_date'            => 'date',
        'is_active'           => 'boolean',
        'is_complete'         => 'boolean',
        'dismissed_at'        => 'datetime',
        'read_at'             => 'datetime',
        'term_ids'            => 'array',
        'user_ids'            => 'array',
        'course_filter'       => 'array',
        'year_level_filter'   => 'array',
    ];

    // ─── Constants ────────────────────────────────────────────────────────────

    /** Notification types that trigger due-date syncing to student_payment_terms. */
    public const DUE_DATE_TYPES = ['payment_due', 'payment_due_notice', 'deadline'];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(StudentPaymentTerm::class, 'payment_term_id');
    }

    // ─── Scopes (student-facing) ──────────────────────────────────────────────

    /**
     * Active notifications that students should see.
     *
     * Intentionally keeps using is_active/is_complete booleans rather than
     * notification_status so existing event listeners and Artisan commands
     * are not broken by the new status field.
     */
    public function scopeActive($query)
    {
        return $query
            ->where('is_active', true)
            ->where('is_complete', false)
            ->whereNull('dismissed_at');
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope: notifications visible to a specific user.
     *
     * Matches on:
     *   1. Direct user_id assignment
     *   2. JSON user_ids array containing this user
     *   3. Broadcast / role-based (no specific user target, matching role + term)
     *
     * IMPORTANT: All nested closures explicitly capture $table, $driver, and
     * $user via use(). PHP closures do NOT inherit parent scope automatically.
     */
    public function scopeForUser($query, int|string $userIdentifier)
    {
        if (is_string($userIdentifier) && str_contains($userIdentifier, '@')) {
            $user = User::where('email', $userIdentifier)->first();
        } else {
            $user = User::find($userIdentifier);
        }

        if (! $user) {
            return $query->whereRaw('0 = 1');
        }

        $driver = DB::getDriverName();
        $table  = $this->getTable();

        return $query->where(function ($q) use ($user, $driver, $table) {

            // 1. Single specific user_id match
            $q->where('user_id', $user->id)

              // 2. Multi-student user_ids JSON array contains this user
              ->orWhere(function ($qm) use ($user, $driver, $table) {
                  $qm->whereNotNull('user_ids')
                     ->where(function ($qi) use ($user, $driver, $table) {
                         if ($driver === 'sqlite') {
                             $qi->whereRaw(
                                 "EXISTS (SELECT 1 FROM json_each({$table}.user_ids) WHERE json_each.value = ?)",
                                 [$user->id]
                             );
                         } else {
                             $qi->whereRaw(
                                 "JSON_CONTAINS({$table}.user_ids, JSON_ARRAY(?))",
                                 [$user->id]
                             );
                         }
                     });
              })

              // 3. Broadcast / role-based notifications (no specific user targeting)
              ->orWhere(function ($q2) use ($user, $driver, $table) {
                  $roleString = $user->role instanceof \BackedEnum
                      ? $user->role->value
                      : (string) $user->role;

                  $q2->whereNull('user_id')
                     ->whereNull('user_ids')
                     ->where(function ($q3) use ($user, $roleString) {
                         $q3->where('target_role', $roleString)
                            ->orWhere('target_role', 'all');
                     })
                     ->where(function ($q4) use ($user) {
                         $q4->where(function ($inner) {
                                $inner->whereNull('target_term_name')
                                      ->orWhere('target_term_name', '');
                            })
                            ->orWhereExists(function ($sub) use ($user) {
                                $sub->from('student_payment_terms')
                                    ->join('student_assessments', 'student_assessments.id', '=', 'student_payment_terms.student_assessment_id')
                                    ->where('student_assessments.user_id', $user->id)
                                    ->whereColumn('student_payment_terms.term_name', 'admin_notifications.target_term_name');
                            });
                     })
                     ->where(function ($q5) use ($user, $table, $driver) {
                         $q5->whereNull('term_ids')
                            ->orWhereRaw("JSON_LENGTH({$table}.term_ids) = 0")
                            ->orWhereExists(function ($sub) use ($user, $table, $driver) {
                                $sub->from('student_payment_terms')
                                    ->join('student_assessments', 'student_assessments.id', '=', 'student_payment_terms.student_assessment_id')
                                    ->where('student_assessments.user_id', $user->id)
                                    ->whereRaw(
                                        $driver === 'sqlite'
                                            ? "EXISTS (SELECT 1 FROM json_each({$table}.term_ids) WHERE json_each.value = student_payment_terms.id)"
                                            : "JSON_CONTAINS({$table}.term_ids, JSON_ARRAY(student_payment_terms.id))"
                                    );
                            });
                     });
              });
        });
    }

    public function scopeWithinDateRange($query)
    {
        $today = now()->toDateString();

        return $query
            ->where(function ($q) use ($today) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
            });
    }

    public function scopeForDueDateTrigger($query, User $user)
    {
        $today        = now()->toDateString();
        $maxLookahead = now()->addDays(90)->toDateString();
        $table        = $this->getTable();

        return $query->where(function ($q) use ($user, $today, $maxLookahead, $table) {
            $q->whereNull('trigger_days_before_due')
              ->orWhere(function ($q2) use ($user, $today, $maxLookahead, $table) {
                  $q2->whereNotNull('trigger_days_before_due')
                     ->whereExists(function ($sub) use ($user, $today, $maxLookahead, $table) {
                         $sub->from('student_payment_terms')
                             ->join('student_assessments', 'student_assessments.id', '=', 'student_payment_terms.student_assessment_id')
                             ->where('student_assessments.user_id', $user->id)
                             ->where('student_payment_terms.balance', '>', 0)
                             ->whereNotNull('student_payment_terms.due_date')
                             ->where('student_payment_terms.due_date', '>=', $today)
                             ->where('student_payment_terms.due_date', '<=', $maxLookahead)
                             ->whereRaw(
                                 'student_payment_terms.due_date <= ' .
                                 self::addDaysExpression("{$table}.trigger_days_before_due")
                             );
                     });
              });
        });
    }

    /**
     * Scope: filter by course_filter and year_level_filter columns.
     *
     * NULL or empty JSON arrays mean "no restriction" — the notification
     * is visible to students regardless of their course or year level.
     *
     * Uses JSON_CONTAINS (MySQL) or json_each (SQLite) for array membership.
     */
    public function scopeForCourseYearLevel($query, User $user)
    {
        $driver = DB::getDriverName();
        $table  = $this->getTable();

        return $query->where(function ($q) use ($user, $driver, $table) {
            // ── Course filter ─────────────────────────────────────────────────
            $q->where(function ($qc) use ($user, $driver, $table) {
                $qc->whereNull("{$table}.course_filter")
                   ->orWhere(function ($qcEmpty) use ($table) {
                       // Treat empty JSON array as "no restriction"
                       $qcEmpty->whereRaw("JSON_LENGTH({$table}.course_filter) = 0");
                   })
                   ->orWhereExists(function ($sub) use ($user, $driver, $table) {
                       $sub->from('student_assessments')
                           ->where('user_id', $user->id)
                           ->where(function ($inner) use ($user, $driver, $table) {
                               if ($driver === 'sqlite') {
                                   $inner->whereRaw(
                                       "EXISTS (SELECT 1 FROM json_each({$table}.course_filter) WHERE json_each.value = student_assessments.course)"
                                   );
                               } else {
                                   $inner->whereRaw(
                                       "JSON_CONTAINS({$table}.course_filter, JSON_QUOTE(student_assessments.course))"
                                   );
                               }
                           });
                   });
            })
            // ── Year level filter ─────────────────────────────────────────────
            ->where(function ($qy) use ($user, $driver, $table) {
                $qy->whereNull("{$table}.year_level_filter")
                   ->orWhere(function ($qyEmpty) use ($table) {
                       $qyEmpty->whereRaw("JSON_LENGTH({$table}.year_level_filter) = 0");
                   })
                   ->orWhereExists(function ($sub) use ($user, $driver, $table) {
                       $sub->from('student_assessments')
                           ->where('user_id', $user->id)
                           ->where(function ($inner) use ($user, $driver, $table) {
                               if ($driver === 'sqlite') {
                                   $inner->whereRaw(
                                       "EXISTS (SELECT 1 FROM json_each({$table}.year_level_filter) WHERE json_each.value = student_assessments.year_level)"
                                   );
                               } else {
                                   $inner->whereRaw(
                                       "JSON_CONTAINS({$table}.year_level_filter, JSON_QUOTE(student_assessments.year_level))"
                                   );
                               }
                           });
                   });
            });
        });
    }

    /**
     * Scope: filter by balance_filter column.
     *
     * 'any'          → no balance restriction (always passes)
     * 'with_balance' → student must have at least one payment term with balance > 0
     * 'overdue'      → student must have at least one payment term with balance > 0
     *                  AND due_date < today
     */
    public function scopeForBalance($query, User $user)
    {
        $table = $this->getTable();
        $today = now()->toDateString();

        return $query->where(function ($q) use ($user, $table, $today) {
            // NULL or 'any' → no restriction
            $q->where(function ($anyGroup) use ($table) {
                  $anyGroup->whereNull("{$table}.balance_filter")
                           ->orWhere("{$table}.balance_filter", 'any');
              })
              // with_balance
              ->orWhere(function ($withBal) use ($user, $table) {
                  $withBal->where("{$table}.balance_filter", 'with_balance')
                          ->whereExists(function ($sub) use ($user) {
                              $sub->from('student_payment_terms')
                                  ->join('student_assessments', 'student_assessments.id', '=', 'student_payment_terms.student_assessment_id')
                                  ->where('student_assessments.user_id', $user->id)
                                  ->where('student_payment_terms.balance', '>', 0);
                          });
              })
              // overdue: balance > 0 AND due_date is in the past
              ->orWhere(function ($overdue) use ($user, $table, $today) {
                  $overdue->where("{$table}.balance_filter", 'overdue')
                          ->whereExists(function ($sub) use ($user, $today) {
                              $sub->from('student_payment_terms')
                                  ->join('student_assessments', 'student_assessments.id', '=', 'student_payment_terms.student_assessment_id')
                                  ->where('student_assessments.user_id', $user->id)
                                  ->where('student_payment_terms.balance', '>', 0)
                                  ->whereNotNull('student_payment_terms.due_date')
                                  ->where('student_payment_terms.due_date', '<', $today);
                          });
              });
        });
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function isCurrentlyActive(): bool
    {
        $today = now()->toDateString();

        return $this->is_active
            && ! $this->is_complete
            && ! $this->dismissed_at
            && (! $this->start_date || $this->start_date->toDateString() <= $today)
            && (! $this->end_date   || $this->end_date->toDateString()   >= $today);
    }

    public function markComplete(): void  { $this->update(['is_complete' => true, 'notification_status' => 'expired']); }
    public function markDismissed(): void { $this->update(['dismissed_at' => now()]); }

    /**
     * Returns a SQL expression that adds an integer column value (days) to today's date.
     * MySQL uses DATE_ADD; SQLite uses date().
     */
    protected static function addDaysExpression(string $columnExpression): string
    {
        $driver = DB::getDriverName();

        return $driver === 'sqlite'
            ? "date('now', '+' || {$columnExpression} || ' days')"
            : "DATE_ADD(CURDATE(), INTERVAL {$columnExpression} DAY)";
    }
}