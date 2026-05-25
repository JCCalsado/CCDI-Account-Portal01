<?php

/**
 * CCDI Fee Configuration — AY 2025-2026
 *
 * Source: Rate of Conduct of Consultation, March 4, 2025
 * Approved increase of 15% from AY 2024-2025 rates.
 *
 * ⚠️  IMPORTANT: The fee_settings table is the PRIMARY source of truth for all
 * fees. This file serves as a fallback for AssessmentService and billing
 * calculations when the DB is unavailable. Keep values in sync with FeeSettingsSeeder.
 *
 * To update rates for a new school year:
 *   1. Update FeeSettingsSeeder.php (fee_settings table — primary)
 *   2. Update values below (fallback reference)
 *   3. Run: php artisan config:clear && php artisan db:seed --class=FeeSettingsSeeder
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Tuition Rate (Lecture Only)
    |--------------------------------------------------------------------------
    | Charged per BILLABLE lecture unit enrolled.
    | NSTP and PATHFIT/PE subjects are excluded from billing per CHED rules.
    | AY 2024-2025: ₱317.00  ->  AY 2025-2026: ₱364.00 (+15%)
    */
    'tuition_per_lec_unit' => env('CCDI_TUITION_PER_UNIT', 364.00),

    /*
    |--------------------------------------------------------------------------
    | Laboratory Fees
    |--------------------------------------------------------------------------
    | Charged ONCE per SUBJECT that has a lab component (lab_units > 0).
    | NOT per individual lab unit — per subject with a lab component.
    |
    | AY 2024-2025: ₱1,440.00  ->  AY 2025-2026: ₱1,656.00 (+15%)
    |
    | entrepreneurship_fee:
    |   A fixed ₱600 charge applied ONCE per assessment whenever the student
    |   has at least one subject with a lab component. Displayed separately
    |   under the Laboratory section in assessments and PDFs.
    |
    | Effective cost per semester:
    |   lab fee     = (count of subjects with lab_units > 0) × ₱1,656
    |   entrep fee  = ₱600 flat (once, if any lab subjects exist)
    */
    'lab' => [
        'per_subject'          => env('CCDI_LAB_FEE_PER_SUBJECT', 1656.00),
        'entrepreneurship_fee' => 600.00,
    ],

    /*
    |--------------------------------------------------------------------------
    | Miscellaneous Fees (Fixed Per Semester)
    |--------------------------------------------------------------------------
    | Total is ₱5,050.00 per semester (AY 2025-2026).
    | Athletic Fee was raised to ₱900 this school year.
    |
    | ⚠️  Do NOT recompute from misc_items below — use this constant directly.
    | The canonical value lives in fee_settings (misc_* + other categories).
    */
    'misc_fee_fixed' => env('CCDI_MISC_FEE', 5050.00),

    /*
    |--------------------------------------------------------------------------
    | Miscellaneous Fee Breakdown (Display / PDF Reference Only)
    |--------------------------------------------------------------------------
    | Used for UI and PDF rendering only.
    | Must match fee_settings rows exactly. Sum = ₱5,050.00.
    |
    | Verified: AY 2025-2026 (Athletic Fee = ₱900, confirmed from live DB)
    */
    'misc_items' => [
        ['label' => 'Registration Fee',      'amount' => 600.00],
        ['label' => 'LMS',                   'amount' => 450.00],
        ['label' => 'Library Fee',           'amount' => 450.00],
        ['label' => 'Athletic Fee',          'amount' => 900.00], // ₱900 — AY 2025-2026
        ['label' => 'PRISAA',                'amount' => 300.00],
        ['label' => 'Publication Fee',       'amount' => 200.00],
        ['label' => 'Audio-Visual Fee',      'amount' => 250.00],
        ['label' => 'ID',                    'amount' => 300.00],
        ['label' => 'BICCS/PCCL/League Fee', 'amount' => 150.00],
        ['label' => 'Faculty Development',   'amount' => 250.00],
        ['label' => 'Guidance Services',     'amount' => 225.00],
        ['label' => 'Medical',               'amount' => 300.00],
        ['label' => 'Insurance Fee',         'amount' => 100.00],
        ['label' => 'Cultural Arts Fee',     'amount' => 175.00],
        ['label' => 'Maintenance Fee',       'amount' => 400.00],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Terms
    |--------------------------------------------------------------------------
    | DO NOT define payment_terms here.
    | The single source of truth is the fee_settings table (term_1_pct … term_5_pct).
    | Run: php artisan db:seed --class=FeeSettingsSeeder
    */

];