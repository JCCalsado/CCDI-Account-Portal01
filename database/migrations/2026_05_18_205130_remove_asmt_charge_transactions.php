<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * INTENTIONALLY EMPTY — DO NOT DELETE THIS FILE.
 *
 * Original intent: remove stale kind='charge' Transaction rows that were
 * accumulating as duplicates on every StudentFeeController::store() call.
 *
 * Resolution history:
 * - 2026-03-27: 4 duplicate charge rows were deleted directly via
 *   `php artisan tinker` on Hostinger SSH.
 * - The root cause was fixed at the code level in StudentFeeController::store():
 *   an upsert guard now checks for an existing charge before inserting, using
 *   Transaction::withoutEvents() / saveQuietly() to avoid mid-transaction
 *   observer side-effects.
 * - No schema change was ever needed; this migration stub remains as an audit
 *   trail of the incident.
 *
 * If you are reading this wondering if you need to run it: NO. It is a no-op
 * by design. The data fix is already applied in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Intentional no-op. See docblock above.
    }

    public function down(): void
    {
        // Intentional no-op.
    }
};