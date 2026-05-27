<?php

namespace App\Services;

use App\Models\FeeSetting;
use App\Models\Subject;
use App\Services\MoneyService;

/**
 * AssessmentService
 *
 * Single source of truth for fee computation, curriculum lookup, and
 * discount application for CCDI student assessments (AY 2025-2026).
 *
 * ── BILLING RULES ─────────────────────────────────────────────────────────────
 *   Tuition   = billable_lec_units × tuition_per_unit
 *               + 1.5 (NSTP fixed billing units) × tuition_per_unit  ← always 1.5
 *   Lab Fee   = (count of subjects with lab_units > 0) × lab_fee_per_subject
 *               + ₱600 entrepreneurship_fee (flat, once, if any lab subjects)
 *   Misc Fee  = ₱4,700 fixed
 *   Total     = tuition + lab_fee + misc_fee
 *
 * ── NSTP / PATHFIT CHED EXCLUSION RULES ──────────────────────────────────────
 *   NSTP subjects:
 *     - Excluded from BILLABLE lec_units (tracked separately)
 *     - ALWAYS billed at exactly 1.5 units regardless of curriculum unit count
 *       e.g. CS-NSTP1 lists 3 units in DB → billing is still 1.5 × ₱364 = ₱546
 *     - NSTP tuition is billed at FULL PRICE regardless of any discount
 *     - Discount percentage NEVER applies to the NSTP portion
 *     - Detected by str_contains($code, 'NSTP') — NOT str_starts_with —
 *       because all DB codes have a course prefix (CS-NSTP1, IT-NSTP1, etc.)
 *   PATHFIT / PE subjects:
 *     - Excluded from tuition billing entirely (CHED non-tuition subjects)
 *
 * ── COURSES WITH NSTP (from ccdi_portal.subjects table) ──────────────────────
 *   Associate in Computer Technology  → ACT-NSTP1, ACT-NSTP2  (3 lec units in DB)
 *   BS Computer Science               → CS-NSTP1,  CS-NSTP2   (3 lec units in DB)
 *   BS Eng. Technology - Electrical   → EET-NSTP1, EET-NSTP2  (3 lec units in DB)
 *   BS Eng. Technology - Electronics  → ECE-NSTP1, ECE-NSTP2  (3 lec units in DB)
 *   BS Information Systems            → IS-NSTP1,  IS-NSTP2   (3 lec units in DB)
 *   BS Information Technology         → IT-NSTP1,  IT-NSTP2   (3 lec units in DB)
 *   ALL 6 courses → billed at 1.5 units ONLY (not 3)
 *
 * ── DISCOUNT POLICY ───────────────────────────────────────────────────────────
 *   discount_percentage applies ONLY to billable (non-NSTP) tuition.
 *   NSTP tuition is always billed at full price (1.5 × rate = ₱546).
 *   Lab and miscellaneous fees are NEVER discounted.
 *
 *   Formula (example: BSCS 1st Yr 1st Sem, no discount):
 *     billable_tuition = 17 × ₱364 = ₱6,188
 *     nstp_tuition     = 1.5 × ₱364 = ₱546
 *     lab_fee          = 3 × ₱1,656 = ₱4,968
 *     entrep_fee       = ₱600
 *     misc_fee         = ₱4,700
 *     total            = ₱17,002
 */
class AssessmentService
{
    // ─── Constants ────────────────────────────────────────────────────────────

    /**
     * NSTP billing units — ALWAYS 1.5 for ALL courses, regardless of DB value.
     * DB stores 3 units for every course's NSTP subject.
     * Admin instruction: bill only 1.5 units = ₱546.00 (at ₱364/unit).
     */
    const NSTP_MINIMUM_UNITS = 1.5;

    // ─── Fee Rates ────────────────────────────────────────────────────────────

    /**
     * Load all active fee rates from fee_settings table.
     * Falls back to config values if the table is not seeded.
     */
    public static function loadRates(): array
    {
        $settings = FeeSetting::allActive();

        $tuitionPerUnit   = (float) ($settings['tuition_per_unit']?->amount    ?? config('fees.tuition_per_lec_unit', 364.00));
        $labFeePerSubject = (float) ($settings['lab_fee_per_subject']?->amount  ?? config('fees.lab.per_subject',      1656.00));
        $entrepreneurFee  = (float) ($settings['entrepreneurship_fee']?->amount ?? config('fees.lab.entrepreneurship_fee', 600.00));

        $miscItems = $settings
            ->whereIn('category', ['miscellaneous', 'other'])
            ->sortBy('sort_order')
            ->values()
            ->map(fn ($s) => [
                'id'       => $s->id,
                'key'      => $s->key,
                'label'    => $s->label,
                'amount'   => (float) $s->amount,
                'category' => $s->category,
            ])
            ->all();

        $miscTotal = collect($miscItems)->sum('amount');

        if ($miscTotal === 0.0) {
            $miscTotal = (float) config('fees.misc_fee_fixed', 4700.00);
        }

        $paymentTerms = [];
        for ($i = 1; $i <= 5; $i++) {
            $key = "term_{$i}_pct";
            if (isset($settings[$key])) {
                $paymentTerms[] = [
                    'term_name'  => $settings[$key]->label,
                    'term_order' => $i,
                    'percentage' => (float) $settings[$key]->amount,
                ];
            }
        }

        if (empty($paymentTerms)) {
            throw new \RuntimeException(
                'Payment term percentages are missing from fee_settings. ' .
                'Run: php artisan db:seed --class=FeeSettingsSeeder'
            );
        }

        return [
            'tuition_per_unit'     => $tuitionPerUnit,
            'lab_fee_per_subject'  => $labFeePerSubject,
            'entrepreneurship_fee' => $entrepreneurFee,
            'misc_total'           => $miscTotal,
            'misc_items'           => $miscItems,
            'payment_terms'        => $paymentTerms,
        ];
    }

    // ─── Curriculum Lookup ────────────────────────────────────────────────────

    /**
     * Get curriculum subjects for a regular student and compute billable units.
     *
     * Handles ALL 6 courses in ccdi_portal:
     *   - Associate in Computer Technology (ACT)
     *   - BS Computer Science (BSCS)
     *   - BS Engineering Technology - Electrical (BSEET)
     *   - BS Engineering Technology - Electronics (BSEECT)
     *   - BS Information Systems (BSIS)
     *   - BS Information Technology (BSIT)
     *
     * NSTP detection uses str_contains($code, 'NSTP') to match all course-prefixed
     * codes: CS-NSTP1, IT-NSTP1, ACT-NSTP1, EET-NSTP1, ECE-NSTP1, IS-NSTP1, etc.
     *
     * nstp_lec_units returned is ALWAYS 1.5 when NSTP is present —
     * never the DB value (which is 3 for all 6 courses).
     */
    public static function getCurriculumUnits(string $course, string $yearLevel, string $semester): array
    {
        $semesterDb = self::normalizeSemester($semester);
        $rates      = self::loadRates();

        $subjects = Subject::where('course', $course)
            ->where('year_level', $yearLevel)
            ->where('semester', $semesterDb)
            ->where('is_active', true)
            ->get();

        $billableLecUnits = 0;
        $hasNstp          = false;
        $labSubjectCount  = 0;
        $pathfitUnits     = 0;
        $subjectList      = [];

        foreach ($subjects as $subj) {
            $isNstp    = self::isNstpSubject($subj->code, $subj->name);
            $isPathfit = self::isPathfitSubject($subj->code, $subj->name);
            $lecUnits  = (float) ($subj->lec_units ?? 0.0);
            $labUnits  = (int) ($subj->lab_units ?? 0);

            if ($isNstp) {
                // Mark NSTP presence only — billing units are fixed at 1.5, NOT the DB value (3)
                $hasNstp = true;
            } elseif ($isPathfit) {
                // PATHFIT/PE: excluded from billing per CHED
                $pathfitUnits += $lecUnits;
            } else {
                $billableLecUnits += $lecUnits;
                if ($labUnits > 0) {
                    $labSubjectCount++;
                }
            }

            // ── Per-subject fee preview (at current rates) ────────────────────
            // These are for display only in getCurriculumUnits(). The authoritative
            // billing snapshot is written by buildSubjectSnapshot() inside store().
            $subjectFees = self::computeSubjectFees($isNstp, $isPathfit, $lecUnits, $labUnits, $rates);

            $subjectList[] = [
                'id'                 => $subj->id,
                'code'               => $subj->code,
                'name'               => $subj->name,
                'lec_units'          => $lecUnits,
                'lab_units'          => $labUnits,
                'total_units'        => $lecUnits + $labUnits,
                'is_nstp'            => $isNstp,
                'is_pathfit'         => $isPathfit,
                'is_billable'        => ! $isNstp && ! $isPathfit,
                'nstp_billing_units' => $isNstp ? self::NSTP_MINIMUM_UNITS : 0,
                // Per-subject fee preview
                'tuition_fee'        => $subjectFees['tuition_fee'],
                'lab_fee'            => $subjectFees['lab_fee'],
                'total_fee'          => $subjectFees['total_fee'],
            ];
        }

        // NSTP billing is ALWAYS 1.5 units for ALL courses — never the DB value (3)
        $nstpBillingUnits = $hasNstp ? self::NSTP_MINIMUM_UNITS : 0;

        return [
            'subjects'           => $subjectList,
            'billable_lec_units' => $billableLecUnits,
            'nstp_lec_units'     => $nstpBillingUnits,
            'has_nstp'           => $hasNstp,
            'lab_subject_count'  => $labSubjectCount,
            'pathfit_units'      => $pathfitUnits,
            'total_units'        => $billableLecUnits + (int) $nstpBillingUnits + $pathfitUnits,
        ];
    }

    // ─── Per-Subject Fee Computation ──────────────────────────────────────────

    /**
     * Compute the fee contribution of a single subject.
     *
     * Rules:
     *   Regular subject: tuition = lec_units × rate
     *                    lab_fee = lab_fee_per_subject if lab_units > 0
     *   NSTP:            tuition = 1.5 × rate (fixed, regardless of lec_units stored)
     *                    lab_fee = 0 (NSTP has no lab component)
     *   PATHFIT:         tuition = 0 (excluded from billing per CHED)
     *                    lab_fee = 0
     *
     * Note: entrepreneurship_fee is charged ONCE at the assessment level (not per subject).
     * It is NOT included in the per-subject total here.
     *
     * @param  bool   $isNstp
     * @param  bool   $isPathfit
     * @param  float  $lecUnits   Stored lec_units from subjects table (1.5 for NSTP, integer for others)
     * @param  int    $labUnits   Stored lab_units from subjects table
     * @param  array  $rates      Output of loadRates()
     * @return array{tuition_fee: float, lab_fee: float, total_fee: float}
     */
    public static function computeSubjectFees(
        bool  $isNstp,
        bool  $isPathfit,
        float $lecUnits,
        int   $labUnits,
        array $rates
    ): array {
        $rate             = $rates['tuition_per_unit'];
        $labFeePerSubject = $rates['lab_fee_per_subject'];

        if ($isPathfit) {
            return ['tuition_fee' => 0.0, 'lab_fee' => 0.0, 'total_fee' => 0.0];
        }

        if ($isNstp) {
            // NSTP always billed at 1.5 units — never the DB value
            $tuition = round(self::NSTP_MINIMUM_UNITS * $rate, 2);
            return ['tuition_fee' => $tuition, 'lab_fee' => 0.0, 'total_fee' => $tuition];
        }

        $tuition = round($lecUnits * $rate, 2);
        $labFee  = $labUnits > 0 ? round($labFeePerSubject, 2) : 0.0;

        return [
            'tuition_fee' => $tuition,
            'lab_fee'     => $labFee,
            'total_fee'   => round($tuition + $labFee, 2),
        ];
    }

    // ─── Assessment Subject Snapshot ──────────────────────────────────────────

    /**
     * Build the assessment_subjects snapshot rows for a new assessment.
     *
     * Called inside StudentFeeController::store() after the StudentAssessment
     * row is created. Rates are locked at the values passed in $rates (which
     * should be the same $rates used to compute the assessment totals).
     *
     * Returns an array of row arrays ready for DB::table('assessment_subjects')->insert().
     *
     * For irregular students (or when subjects can't be determined), returns [].
     * The caller checks the return value and handles the empty case gracefully.
     *
     * @param  string $course
     * @param  string $yearLevel
     * @param  string $semester      Normalised DB format: '1st Sem', '2nd Sem', 'Summer'
     * @param  array  $rates         Output of loadRates() — rates locked at creation time
     * @param  int    $assessmentId  student_assessments.id for the FK
     * @return array<int, array>
     */
    public static function buildSubjectSnapshot(
        string $course,
        string $yearLevel,
        string $semester,
        array  $rates,
        int    $assessmentId
    ): array {
        $semesterDb = self::normalizeSemester($semester);

        $subjects = Subject::where('course', $course)
            ->where('year_level', $yearLevel)
            ->where('semester', $semesterDb)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($subjects->isEmpty()) {
            return [];
        }

        $rows      = [];
        $sortOrder = 1;
        $now       = now();

        foreach ($subjects as $subj) {
            $isNstp    = self::isNstpSubject($subj->code, $subj->name);
            $isPathfit = self::isPathfitSubject($subj->code, $subj->name);
            $lecUnits  = (float) ($subj->lec_units ?? 0.0);
            $labUnits  = (int) ($subj->lab_units ?? 0);
            $isBillable = ! $isNstp && ! $isPathfit;

            $fees = self::computeSubjectFees($isNstp, $isPathfit, $lecUnits, $labUnits, $rates);

            $rows[] = [
                'student_assessment_id' => $assessmentId,
                'subject_id'            => $subj->id,
                'code'                  => $subj->code,
                'name'                  => $subj->name,
                'lec_units'             => $lecUnits,
                'lab_units'             => $labUnits,
                'is_nstp'               => $isNstp,
                'is_pathfit'            => $isPathfit,
                'is_billable'           => $isBillable,
                'tuition_fee'           => $fees['tuition_fee'],
                'lab_fee'               => $fees['lab_fee'],
                'total_fee'             => $fees['total_fee'],
                'nstp_billing_units'    => $isNstp ? self::NSTP_MINIMUM_UNITS : 0.0,
                'sort_order'            => $sortOrder++,
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
        }

        return $rows;
    }

    /**
     * Build subject snapshot from explicit subject IDs (manual selection).
     * 
     * Used when Accounting manually selects subjects (including cross-course picks).
     * Bypasses the automatic curriculum lookup entirely.
     *
     * @param  array $subjectIds     Array of subject.id values to include
     * @param  array $rates          Fee rates (output of loadRates())
     * @param  int   $assessmentId   The assessment being created
     * @return array                 Rows ready for assessment_subjects insert
     */
    public static function buildSubjectSnapshotFromIds(
        array $subjectIds,
        array $rates,
        int   $assessmentId
    ): array {
        if (empty($subjectIds)) {
            return [];
        }

        $subjects = Subject::whereIn('id', $subjectIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $rows      = [];
        $sortOrder = 1;
        $now       = now();

        foreach ($subjectIds as $subjectId) {
            $subj = $subjects->get($subjectId);
            if (! $subj) {
                continue;
            }

            $isNstp    = self::isNstpSubject($subj->code, $subj->name);
            $isPathfit = self::isPathfitSubject($subj->code, $subj->name);
            $lecUnits  = (float) ($subj->lec_units ?? 0.0);
            $labUnits  = (int) ($subj->lab_units ?? 0);
            $isBillable = ! $isNstp && ! $isPathfit;

            $fees = self::computeSubjectFees($isNstp, $isPathfit, $lecUnits, $labUnits, $rates);

            $rows[] = [
                'student_assessment_id' => $assessmentId,
                'subject_id'            => $subj->id,
                'code'                  => $subj->code,
                'name'                  => $subj->name,
                'lec_units'             => $lecUnits,
                'lab_units'             => $labUnits,
                'is_nstp'               => $isNstp,
                'is_pathfit'            => $isPathfit,
                'is_billable'           => $isBillable,
                'tuition_fee'           => $fees['tuition_fee'],
                'lab_fee'               => $fees['lab_fee'],
                'total_fee'             => $fees['total_fee'],
                'nstp_billing_units'    => $isNstp ? self::NSTP_MINIMUM_UNITS : 0.0,
                'sort_order'            => $sortOrder++,
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
        }

        return $rows;
    }

    // ─── Fee Computation ──────────────────────────────────────────────────────

    /**
 * Compute the full assessment fee breakdown.
 *
 * ── DISCOUNT RULES (Option A — revised) ──────────────────────────────────
 *   discount < 100%:
 *     Discount applies to ALL lec units including NSTP.
 *     Formula: discounted_tuition = (lecUnits + nstpLecUnits) × rate × (1 - pct/100)
 *
 *   discount = 100%:
 *     All billable lec units → ₱0.
 *     NSTP (1.5 units) is excluded from the 100% discount and charged at full price.
 *     Formula: tuition = nstpLecUnits × rate (= ₱546)
 *
 *   Lab and miscellaneous fees are NEVER discounted regardless of discount type.
 *
 * @param  float      $lecUnits            Billable lec units (PATHFIT excluded, NSTP excluded)
 * @param  int        $labSubjects         Number of subjects with lab_units > 0
 * @param  float      $nstpLecUnits        NSTP units — clamped to 1.5 if > 0
 * @param  float      $discountPercentage  0–100. 0 = no discount.
 * @param  array|null $rates               Output of loadRates(). Loaded fresh if null.
    */
    public static function compute(
        float  $lecUnits,
        int    $labSubjects,
        float  $nstpLecUnits       = 0,
        float  $discountPercentage = 0.0,
        ?array $rates              = null
    ): array {
        $rates ??= self::loadRates();

        // NSTP billing safety clamp — always 1.5 units, never the DB value (3)
        if ($nstpLecUnits > 0) {
            $nstpLecUnits = self::NSTP_MINIMUM_UNITS; // 1.5
        }

        $tuitionPerUnit   = $rates['tuition_per_unit'];
        $labFeePerSubject = $rates['lab_fee_per_subject'];
        $entrepreneurFee  = $labSubjects > 0 ? $rates['entrepreneurship_fee'] : 0.0;

        // Lab and misc are NEVER discounted — all arithmetic in integer cents
        $labFeeCents  = MoneyService::roundToCents($labSubjects * $labFeePerSubject);
        $miscFeeCents = MoneyService::roundToCents($rates['misc_total']);

        // Raw tuition values before discount — in integer cents
        $rawBillableTuitionCents = MoneyService::roundToCents($lecUnits * $tuitionPerUnit);
        $rawNstpTuitionCents     = MoneyService::roundToCents($nstpLecUnits * $tuitionPerUnit);
        $rawTotalTuitionCents    = MoneyService::roundToCents(($lecUnits + $nstpLecUnits) * $tuitionPerUnit);

        // ── DISCOUNT COMPUTATION ─────────────────────────────────────────────
        if ($discountPercentage == 100.0) {
            // 100% discount: all billable lec units → ₱0
            // NSTP is excluded from the 100% discount — charged at full price
            $finalTuitionCents   = $rawNstpTuitionCents;              // only NSTP survives
            $discountSavingCents = $rawBillableTuitionCents;           // entire non-NSTP tuition waived
            $discountApplied     = 'full_100pct';

        } elseif ($discountPercentage > 0 && $discountPercentage < 100) {
            // Partial discount: applies to ALL lec units including NSTP
            $discountSavingCents = MoneyService::percent($rawTotalTuitionCents, $discountPercentage);
            $finalTuitionCents   = $rawTotalTuitionCents - $discountSavingCents;
            $discountApplied     = "percentage_{$discountPercentage}pct";

        } else {
            // No discount
            $discountSavingCents = 0;
            $finalTuitionCents   = $rawTotalTuitionCents;
            $discountApplied     = 'none';
        }
        // ─────────────────────────────────────────────────────────────────────

        $entrepreneurFeeCents = MoneyService::roundToCents($entrepreneurFee);
        $totalCents = $finalTuitionCents + $labFeeCents + $entrepreneurFeeCents + $miscFeeCents;

        return [
            'tuition_fee'          => MoneyService::toFloat($finalTuitionCents),
            'billable_tuition'     => MoneyService::toFloat($finalTuitionCents),   // same as tuition_fee under new rule
            'nstp_tuition'         => $discountPercentage == 100.0 ? MoneyService::toFloat($rawNstpTuitionCents) : 0.0,
            'lab_fee'              => MoneyService::toFloat($labFeeCents),
            'entrepreneurship_fee' => MoneyService::toFloat($entrepreneurFeeCents),
            'misc_fee'             => MoneyService::toFloat($miscFeeCents),
            'total'                => MoneyService::toFloat($totalCents),
            'discount_saving'      => MoneyService::toFloat($discountSavingCents),
            'discount_applied'     => $discountApplied,
            'raw_billable_tuition' => MoneyService::toFloat($rawTotalTuitionCents),          // total lec+nstp before discount
        ];
    }

    /**
     * Legacy wrapper — kept for backward compatibility.
     *
     * @deprecated Pass nstpLecUnits directly to compute() instead.
     */
    public static function computeWithNstpFlag(
        int    $lecUnits,
        int    $labSubjects,
        bool   $isTakingNstp       = false,
        float  $discountPercentage = 0.0,
        ?array $rates              = null
    ): array {
        $rates        ??= self::loadRates();
        // Pass 1 so compute() clamps to 1.5 via NSTP_MINIMUM_UNITS
        $nstpLecUnits   = $isTakingNstp ? 1 : 0;

        return self::compute($lecUnits, $labSubjects, $nstpLecUnits, $discountPercentage, $rates);
    }

    /**
     * Build payment term records from a total assessment amount.
     *
     * ✅ FIX: Status is now 'pending' (not 'unpaid') so that all
     * PaymentStatus::unpaidValues() queries find these terms immediately.
     *
     * ✅ FIX: Last term absorbs all rounding remainder so that
     *    SUM(term.amount) === total_assessment is always guaranteed.
     */
    /**
     * Build payment term records using the correct CCDI fee distribution rules:
     *
     *   Upon Registration = Miscellaneous Fee (₱4,700 fixed, one-time)
     *   Prelim            = 30% × (Tuition + Lab) + misc carryover if underpaid
     *   Midterm           = 30% × (Tuition + Lab)
     *   Pre-Final         = 25% × (Tuition + Lab)
     *   Final             = 15% × (Tuition + Lab)
     *
     * The misc carryover on Prelim only applies when a student underpays
     * Upon Registration — at assessment creation time balance = full amount,
     * so the carryover is tracked via StudentPaymentService when payments post.
     *
     * @param  float $total  Total assessment (tuition + lab + misc)
     * @param  array $rates  Output of loadRates()
     * @param  float $miscFee  Miscellaneous fee portion (defaults to rates misc_total)
     * @param  float $tuitionAndLabFee  Tuition + Lab base (defaults to total - misc)
     */
    public static function buildPaymentTerms(
        float  $total,
        array  $rates,
        ?float $miscFee          = null,
        ?float $tuitionAndLabFee = null
    ): array {
        // Resolve $miscFee once so the same value is used in both calculations.
        //
        // BUG FIXED: the original code used ($miscFee ?? 0) when computing
        // $tuitionAndLabFeeCents, but $miscFee is already null at that point
        // (no 3rd arg is passed at either call site). This caused:
        //   $tuitionAndLabFeeCents = total - 0 = total    ← WRONG
        // instead of:
        //   $tuitionAndLabFeeCents = total - miscFee       ← CORRECT
        //
        // With $tuitionAndLabFee = total, the four TL terms collectively sum to
        // $total (not $total - $misc), so the grand total becomes:
        //   Upon Registration (misc) + TL terms (total) = total + misc > total
        // The StudentPaymentTermObserver catches this and throws a ValidationException
        // partway through the foreach insert loop, aborting the transaction.
        $resolvedMiscFee       = $miscFee ?? round($rates['misc_total'], 2);
        $miscFeeCents          = MoneyService::roundToCents($resolvedMiscFee);
        $tuitionAndLabFeeCents = MoneyService::roundToCents($tuitionAndLabFee ?? round($total - $resolvedMiscFee, 2));

        $configuredTerms = $rates['payment_terms'] ?? [];

        if (!empty($configuredTerms)) {
            // First term (Upon Registration) is always misc-based
            $termPcts = array_map(function ($t, $i) {
                return [
                    'term_name'  => $t['term_name'],
                    'term_order' => $t['term_order'],
                    'percentage' => (float) $t['percentage'],
                    'base'       => $i === 0 ? 'misc' : 'tuition_lab',
                ];
            }, $configuredTerms, array_keys($configuredTerms));
        } else {
            throw new \RuntimeException(
                'buildPaymentTerms() called with empty payment_terms in $rates. ' .
                'Ensure fee_settings is seeded before creating assessments.'
            );
        }

        $terms        = [];
        $runningTLCents = 0;   // running total of tuition+lab terms in cents (exact integer math)
        $tlTerms      = array_filter($termPcts, fn($t) => $t['base'] === 'tuition_lab');
        $lastTLIndex  = array_key_last(array_values(array_filter($termPcts, fn($t) => $t['base'] === 'tuition_lab')));
        $tlCounter    = 0;

        foreach ($termPcts as $config) {
            if ($config['base'] === 'misc') {
                // Upon Registration = fixed misc fee
                $amountCents = $miscFeeCents;
            } else {
                // Tuition+Lab terms — last one absorbs rounding remainder
                if ($tlCounter === count(array_filter($termPcts, fn($t) => $t['base'] === 'tuition_lab')) - 1) {
                    $amountCents = $tuitionAndLabFeeCents - $runningTLCents;
                } else {
                    $amountCents = MoneyService::percent($tuitionAndLabFeeCents, $config['percentage']);
                    $runningTLCents += $amountCents;
                }
                $tlCounter++;
            }

            $terms[] = [
                'term_name'  => $config['term_name'],
                'term_order' => $config['term_order'],
                'percentage' => $config['percentage'],
                'amount'     => MoneyService::toFloat($amountCents),  // for backwards compat
                'balance'    => MoneyService::toPesos($amountCents),  // stored as decimal string in DB
                'status'     => 'pending',
                'due_date'   => null,
                'paid_date'  => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $terms;
    }

    // ─── Subject Classification Helpers ───────────────────────────────────────

    /**
     * Detect NSTP subjects for ALL 6 courses in ccdi_portal.
     *
     * Uses str_contains($code, 'NSTP') — NOT str_starts_with — because
     * every course prefixes the code before NSTP:
     *   ACT-NSTP1, ACT-NSTP2  → Associate in Computer Technology
     *   CS-NSTP1,  CS-NSTP2   → BS Computer Science
     *   EET-NSTP1, EET-NSTP2  → BS Engineering Technology - Electrical
     *   ECE-NSTP1, ECE-NSTP2  → BS Engineering Technology - Electronics
     *   IS-NSTP1,  IS-NSTP2   → BS Information Systems
     *   IT-NSTP1,  IT-NSTP2   → BS Information Technology
     *
     * All of the above return true from this method.
     * All of them will be billed at 1.5 units (not 3) via NSTP_MINIMUM_UNITS.
     */
    public static function isNstpSubject(string $code, string $name): bool
    {
        $code = strtoupper(trim($code));
        $name = strtoupper(trim($name));

        return str_contains($code, 'NSTP')
            || str_contains($name, 'NATIONAL SERVICE TRAINING');
    }

    /**
     * Alias for isNstpSubject() — used in API responses to avoid naming conflicts.
     */
    public static function isNstpSubjectPublic(string $code, string $name): bool
    {
        return self::isNstpSubject($code, $name);
    }

    /**
     * PATHFIT/PE subjects: excluded from tuition billing per CHED.
     */
    public static function isPathfitSubject(string $code, string $name): bool
    {
        $code = strtoupper(trim($code));
        $name = strtoupper(trim($name));

        // All subjects are billable — only NSTP is handled separately (fixed 1.5 units)
        // PE, PATHFIT, Rhythmic, etc. are all billed normally
        return false;
    }

    /**
     * Alias for isPathfitSubject() — used in API responses to avoid naming conflicts.
     */
    public static function isPathfitSubjectPublic(string $code, string $name): bool
    {
        return self::isPathfitSubject($code, $name);
    }

    /**
     * Normalize semester from form value ("1st") to DB format ("1st Sem").
     */
    public static function normalizeSemester(string $semester): string
    {
        return match ($semester) {
            '1st'    => '1st Sem',
            '2nd'    => '2nd Sem',
            'Summer' => 'Summer',
            default  => $semester,
        };
    }

    /**
     * Denormalize DB semester ("1st Sem") to form value ("1st").
     */
    public static function denormalizeSemester(string $semester): string
    {
        return match ($semester) {
            '1st Sem' => '1st',
            '2nd Sem' => '2nd',
            default   => $semester,
        };
    }

    /**
     * Build the fee rates payload for the Vue Create/Edit form.
     */
    public static function feeRatesForForm(): array
    {
        $rates = self::loadRates();

        return [
            'tuition_per_unit'     => $rates['tuition_per_unit'],
            'lab_fee_per_subject'  => $rates['lab_fee_per_subject'],
            'entrepreneurship_fee' => $rates['entrepreneurship_fee'],
            'misc_total'           => $rates['misc_total'],
            'misc_items'           => $rates['misc_items'],
            'payment_terms'        => $rates['payment_terms'],
        ];
    }
}