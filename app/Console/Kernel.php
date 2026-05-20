<?php

namespace App\Console;

use App\Console\Commands\CheckOverduePayments;
use App\Console\Commands\CheckQueueHealth;
use App\Models\Notification;
use App\Services\PhilSmsService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // ── Overdue payment reminders ─────────────────────────────────────────
        $schedule->command(CheckOverduePayments::class)
            ->dailyAt('06:00')
            ->name('check-overdue-payments')
            ->description('Check for overdue payments and generate reminders');

        $schedule->command(CheckOverduePayments::class)
            ->dailyAt('12:00')
            ->name('check-overdue-payments-noon')
            ->description('Check for overdue payments and generate reminders (noon check)');

        // ── Queue health monitor ──────────────────────────────────────────────
        $schedule->command(CheckQueueHealth::class, ['--threshold=100'])
            ->everyFiveMinutes()
            ->name('queue-health-check')
            ->description('Alert if queue backlog exceeds threshold');

        // ── Notification lifecycle: scheduled → active ────────────────────────
        /**
         * Activates notifications whose start_date has arrived, then
         * dispatches SMS to all recipients with a phone number on record.
         *
         * Runs daily at 00:05 so it fires just after midnight.
         */
        $schedule->call(function () {
            // Fetch the records BEFORE the bulk update so we have their data.
            $toActivate = Notification::where('notification_status', 'scheduled')
                ->whereDate('start_date', '<=', now()->toDateString())
                ->get();

            if ($toActivate->isEmpty()) {
                return;
            }

            // Bulk status update — fast single query.
            Notification::whereIn('id', $toActivate->pluck('id'))
                ->update([
                    'notification_status' => 'active',
                    'is_active'           => true,
                    'is_complete'         => false,
                ]);

            Log::info("Scheduler: activated {$toActivate->count()} scheduled notification(s).");

            // Dispatch SMS for each newly-activated notification.
            $sms = app(PhilSmsService::class);

            foreach ($toActivate as $notification) {
                $data = $notification->toArray();

                // Resolve recipients using the same priority chain as the controller.
                $recipients = self::resolveRecipientsForNotification($notification);

                $sent   = 0;
                $failed = 0;

                foreach ($recipients as $user) {
                    $phone = $user->phone ?? null;
                    if (! $phone) {
                        continue;
                    }

                    $name    = $user->first_name ?? 'Student';
                    $type    = $notification->type ?? 'general';
                    $title   = $notification->title;
                    $dueDate = $notification->due_date
                        ? Carbon::parse($notification->due_date)->format('M j, Y')
                        : null;

                    $message = self::buildScheduledSms($type, $title, $name, $dueDate);

                    $sms->send($phone, $message) ? $sent++ : $failed++;
                }

                Log::info("Scheduler SMS: notification #{$notification->id} — sent:{$sent} failed:{$failed}");
            }
        })
        ->dailyAt('00:05')
        ->name('activate-scheduled-notifications')
        ->description('Activate scheduled notifications and dispatch SMS');

        // ── Notification lifecycle: active → expired ──────────────────────────
        $schedule->call(function () {
            $count = Notification::where('notification_status', 'active')
                ->whereNotNull('end_date')
                ->whereDate('end_date', '<', now()->toDateString())
                ->update([
                    'notification_status' => 'expired',
                    'is_active'           => false,
                    'is_complete'         => true,
                ]);

            Log::info("Scheduler: expired {$count} stale notification(s).");
        })
        ->dailyAt('00:10')
        ->name('expire-stale-notifications')
        ->description('Expire notifications whose end_date has passed');
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }

    // =========================================================================
    // Private Helpers (static — Kernel closures cannot use $this)
    // =========================================================================

    private static function resolveRecipientsForNotification(Notification $notification): \Illuminate\Database\Eloquent\Collection
    {
        // Multi-user explicit targeting
        if (! empty($notification->user_ids)) {
            return User::whereIn('id', $notification->user_ids)->get();
        }

        // Single-user explicit targeting
        if (! empty($notification->user_id)) {
            return User::where('id', $notification->user_id)->get();
        }

        // Role-based broadcast
        $role = $notification->target_role;

        if ($role === 'all') {
            return User::where('is_active', true)->get();
        }

        if (in_array($role, ['student', 'accounting', 'admin'], true)) {
            return User::where('role', $role)->where('is_active', true)->get();
        }

        return collect();
    }

    private static function buildScheduledSms(string $type, string $title, string $firstName, ?string $dueDate): string
    {
        return match (true) {
            in_array($type, ['payment_due', 'payment_due_notice', 'deadline'], true) && $dueDate
                => "Hi {$firstName}! {$title}. Due: {$dueDate}. Login to CCDI Portal to pay. -CCDI",

            $type === 'payment_approved'
                => "Hi {$firstName}! {$title}. Your payment has been approved. -CCDI",

            $type === 'payment_rejected'
                => "Hi {$firstName}! {$title}. Please contact the accounting office. -CCDI",

            default
                => "Hi {$firstName}! {$title}. Login to CCDI Portal for details. -CCDI",
        };
    }
}