<?php

namespace App\Console;

use App\Console\Commands\CheckOverduePayments;
use App\Console\Commands\CheckQueueHealth;
use App\Models\Notification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
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

        // ── Notification lifecycle management ─────────────────────────────────

        /**
         * Activate notifications whose start_date has arrived.
         *
         * Transitions: scheduled → active
         * Also sets is_active = true so legacy boolean consumers stay correct.
         *
         * Runs daily at 00:05 so it fires just after midnight, before students log in.
         */
        $schedule->call(function () {
            $count = Notification::where('notification_status', 'scheduled')
                ->whereDate('start_date', '<=', now()->toDateString())
                ->update([
                    'notification_status' => 'active',
                    'is_active'           => true,
                    'is_complete'         => false,
                ]);

            \Illuminate\Support\Facades\Log::info(
                "Scheduler: activated {$count} scheduled notification(s)."
            );
        })
        ->dailyAt('00:05')
        ->name('activate-scheduled-notifications')
        ->description('Activate notifications whose start_date has been reached');

        /**
         * Expire notifications whose end_date has passed.
         *
         * Transitions: active → expired
         * Also sets is_active = false, is_complete = true for legacy compat.
         *
         * Runs daily at 00:10.
         */
        $schedule->call(function () {
            $count = Notification::where('notification_status', 'active')
                ->whereNotNull('end_date')
                ->whereDate('end_date', '<', now()->toDateString())
                ->update([
                    'notification_status' => 'expired',
                    'is_active'           => false,
                    'is_complete'         => true,
                ]);

            \Illuminate\Support\Facades\Log::info(
                "Scheduler: expired {$count} stale notification(s)."
            );
        })
        ->dailyAt('00:10')
        ->name('expire-stale-notifications')
        ->description('Expire notifications whose end_date has passed');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}