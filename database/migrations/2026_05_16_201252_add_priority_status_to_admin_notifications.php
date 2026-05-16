<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `priority` and `notification_status` columns to admin_notifications.
 *
 * IMPORTANT — Backfill:
 *   After adding the columns, existing rows are back-filled so the new
 *   notification_status is consistent with the old is_active / is_complete booleans.
 *   Without the backfill every existing notification would get the DEFAULT ('draft')
 *   and Accounting users would find all historical notifications "missing".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table) {
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])
                  ->default('medium')
                  ->after('type')
                  ->comment('Visual urgency indicator for Accounting staff');

            // 'notification_status' rather than 'status' to avoid MySQL reserved-word collision
            // with any future query that references status without a table alias.
            $table->enum('notification_status', ['draft', 'scheduled', 'active', 'expired'])
                  ->default('draft')
                  ->after('priority')
                  ->comment('Lifecycle state: draft=invisible, scheduled=auto-activates, active=visible, expired=closed');
        });

        // ── Backfill ──────────────────────────────────────────────────────────
        // Derive notification_status from the existing boolean columns so no
        // historical record is lost. Run this once immediately after the ALTER TABLE.
        //
        // Logic:
        //   is_complete = 1           → expired
        //   is_active   = 1           → active  (date-range honoured by scopes, not here)
        //   is_active   = 0           → draft   (was manually deactivated or never published)
        //
        // We deliberately do NOT try to infer 'scheduled' from start_date because
        // old records with future start_dates were still served as 'inactive' under
        // the old system — mapping them to 'scheduled' would change their behaviour.
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement("
                UPDATE admin_notifications
                SET notification_status = CASE
                    WHEN is_complete = 1 THEN 'expired'
                    WHEN is_active   = 1 THEN 'active'
                    ELSE 'draft'
                END
            ");
        } else {
            DB::statement("
                UPDATE admin_notifications
                SET notification_status = CASE
                    WHEN is_complete = 1 THEN 'expired'
                    WHEN is_active   = 1 THEN 'active'
                    ELSE 'draft'
                END
            ");
        }
    }

    public function down(): void
    {
        Schema::table('admin_notifications', function (Blueprint $table) {
            $table->dropColumn(['priority', 'notification_status']);
        });
    }
};