<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Add 'processed' status + carry-over columns to student_payment_terms.
 *
 * IDEMPOTENT — uses hasColumn() and hasForeignKey() guards before adding anything.
 * Safe to run even if columns already exist from a previous migration attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Add carry-over audit columns (only if not already present) ─────────
        Schema::table('student_payment_terms', function (Blueprint $table) {
            if (! Schema::hasColumn('student_payment_terms', 'carryover_from_term_id')) {
                $table->unsignedBigInteger('carryover_from_term_id')
                    ->nullable()
                    ->after('remarks')
                    ->comment('ID of the term that carried balance into this term; NULL if not a carry recipient.');
            }

            if (! Schema::hasColumn('student_payment_terms', 'carryover_amount')) {
                $table->decimal('carryover_amount', 10, 2)
                    ->nullable()
                    ->after('carryover_from_term_id')
                    ->comment('Peso amount carried in from carryover_from_term_id; NULL if no carry.');
            }
        });

        // ── Add FK only if it does not already exist ───────────────────────────
        // We check the information_schema directly — Laravel's hasForeignKey()
        // is only available in Laravel 11+; this raw check works on all versions.
        $fkExists = DB::selectOne("
            SELECT COUNT(*) AS cnt
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME        = 'student_payment_terms'
              AND CONSTRAINT_NAME   = 'student_payment_terms_carryover_from_term_id_foreign'
              AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
        ");

        if (! $fkExists || $fkExists->cnt === 0) {
            Schema::table('student_payment_terms', function (Blueprint $table) {
                $table->foreign('carryover_from_term_id')
                    ->references('id')
                    ->on('student_payment_terms')
                    ->nullOnDelete();
            });
        }

        // ── Document status values via column comment (MySQL only) ─────────────
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE student_payment_terms
                MODIFY COLUMN `status` VARCHAR(255) DEFAULT 'pending'
                COMMENT 'unpaid (legacy) | pending | partial (legacy) | paid | processed | overdue. processed = partial payment applied, remaining balance carried forward; balance = 0.00.'
            ");
        }
    }

    public function down(): void
    {
        // Drop FK first if it exists, then columns.
        $fkExists = DB::selectOne("
            SELECT COUNT(*) AS cnt
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME        = 'student_payment_terms'
              AND CONSTRAINT_NAME   = 'student_payment_terms_carryover_from_term_id_foreign'
              AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
        ");

        if ($fkExists && $fkExists->cnt > 0) {
            Schema::table('student_payment_terms', function (Blueprint $table) {
                $table->dropForeign(['carryover_from_term_id']);
            });
        }

        Schema::table('student_payment_terms', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('student_payment_terms', 'carryover_from_term_id')) {
                $cols[] = 'carryover_from_term_id';
            }
            if (Schema::hasColumn('student_payment_terms', 'carryover_amount')) {
                $cols[] = 'carryover_amount';
            }
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE student_payment_terms
                MODIFY COLUMN `status` VARCHAR(255) DEFAULT 'pending'
                COMMENT ''
            ");
        }
    }
};