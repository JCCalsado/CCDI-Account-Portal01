<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Certificate of Matriculation — {{ $student->account_id }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
            color: #000;
            padding: 14px 16px;
        }

        /* ── Header ──────────────────────────────────────────────────── */
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .header-logo-cell {
            width: 60px;
            vertical-align: middle;
            text-align: center;
        }
        .header-logo {
            width: 50px;
            height: 50px;
        }
        .header-text-cell {
            vertical-align: middle;
            text-align: center;
        }
        .header-school-name {
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .header-branch {
            font-size: 9px;
            margin-top: 1px;
        }
        .header-form-title {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            margin-top: 2px;
        }

        /* ── Student Info Bar ────────────────────────────────────────── */
        .student-info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
        }
        .student-info-table td {
            padding: 2px 4px;
            font-size: 9px;
        }
        .field-underline {
            border-bottom: 1px solid #000;
            min-width: 160px;
            display: inline-block;
            padding-bottom: 1px;
            font-weight: bold;
        }
        .field-label {
            font-weight: normal;
            white-space: nowrap;
        }

        /* ── Main Body: Subjects (left) + Fees (right) ───────────────── */
        .body-table {
            width: 100%;
            border-collapse: collapse;
        }
        .subjects-cell {
            vertical-align: top;
            width: 60%;
            padding-right: 8px;
        }
        .fees-cell {
            vertical-align: top;
            width: 40%;
            border-left: 1px solid #000;
            padding-left: 8px;
        }

        /* ── Subject Table ───────────────────────────────────────────── */
        table.subjects {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #000;
        }
        table.subjects th,
        table.subjects td {
            border: 1px solid #000;
            padding: 3px 4px;
            font-size: 8px;
        }
        table.subjects th {
            background-color: #e8e8e8;
            font-weight: bold;
            text-align: center;
            font-size: 8px;
        }
        table.subjects td.code-col   { width: 14%; text-align: center; }
        table.subjects td.title-col  { width: 44%; }
        table.subjects td.units-col  { width: 10%; text-align: center; }
        table.subjects td.time-col   { width: 16%; text-align: center; }
        table.subjects td.day-col    { width: 16%; text-align: center; }
        table.subjects .total-row td {
            font-weight: bold;
            background: #f0f0f0;
            text-align: right;
            font-size: 8px;
        }
        table.subjects .total-row td.total-units {
            text-align: center;
        }

        /* ── Fees Section ────────────────────────────────────────────── */
        .fees-section {
            width: 100%;
        }
        .fees-header {
            text-align: center;
            font-weight: bold;
            font-size: 10px;
            text-transform: uppercase;
            border-bottom: 1px solid #000;
            padding-bottom: 3px;
            margin-bottom: 4px;
        }
        .fee-row-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2px;
        }
        .fee-row-table td {
            padding: 2px 2px;
            font-size: 9px;
            vertical-align: top;
        }
        .fee-label-col {
            width: 62%;
        }
        .fee-amount-col {
            width: 38%;
            text-align: right;
            border-bottom: 1px solid #555;
            font-weight: bold;
        }
        .fee-amount-col.no-underline {
            border-bottom: none;
        }
        .fee-total-row td {
            font-weight: bold;
            font-size: 9px;
            border-top: 1px solid #000;
            padding-top: 3px;
        }

        /* ── Terms of Payment ────────────────────────────────────────── */
        .terms-header {
            text-align: center;
            font-weight: bold;
            font-size: 9px;
            text-decoration: underline;
            text-transform: uppercase;
            margin: 8px 0 4px 0;
        }
        .term-row-table {
            width: 100%;
            border-collapse: collapse;
        }
        .term-row-table td {
            padding: 2px 2px;
            font-size: 9px;
            vertical-align: top;
        }
        .term-label-col {
            width: 55%;
        }
        .term-amount-col {
            width: 45%;
            text-align: right;
            border-bottom: 1px solid #555;
            font-weight: bold;
        }

        /* ── Student Copy Label ──────────────────────────────────────── */
        .footer-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        .footer-table td {
            vertical-align: bottom;
            font-size: 8px;
            padding: 0 4px;
        }
        .student-copy-cell {
            width: 33%;
        }
        .signature-cell {
            width: 34%;
            text-align: center;
        }
        .approved-cell {
            width: 33%;
            text-align: center;
        }
        .sig-line {
            border-top: 1px solid #000;
            margin-bottom: 2px;
            margin-top: 22px;
        }
        .sig-name {
            font-weight: bold;
            font-size: 8px;
        }
        .sig-title {
            font-size: 7.5px;
        }
    </style>
</head>
<body>

{{-- ══════════════════════════════════════════════════════════
     HEADER
══════════════════════════════════════════════════════════ --}}
<table class="header-table">
    <tr>
        <td class="header-logo-cell">
            {{--
                If you have a logo file, use:
                <img src="{{ public_path('images/ccdi-logo.png') }}" class="header-logo" alt="CCDI">
                For now we render a text placeholder.
            --}}
<img src="{{ public_path('images/ccdilogo.png') }}" style="width:60px; height:60px;" alt="CCDI">
        </td>
        <td class="header-text-cell">
            <div class="header-school-name">
                {{ strtoupper(config('school.name', 'Computer Communication Development Institute')) }}
            </div>
            <div class="header-branch">
                {{ config('school.branch', 'Sorsogon Branch') }}
            </div>
            <div class="header-form-title">Certificate of Matriculation Form</div>
        </td>
        <td style="width: 60px;">&nbsp;</td>{{-- balances the logo cell --}}
    </tr>
</table>

{{-- ══════════════════════════════════════════════════════════
     STUDENT INFO BAR
══════════════════════════════════════════════════════════ --}}
<table class="student-info-table">
    <tr>
        <td style="width:55%;">
            <span class="field-label">Name: </span>
            <span class="field-underline">{{ $student->name }}</span>
        </td>
        <td style="width:22%;">
            <span class="field-label">Semester/Summer: </span>
            <span class="field-underline">{{ $assessment->semester }}</span>
        </td>
        <td style="width:23%;">
            <span class="field-label">School Year: </span>
            <span class="field-underline">{{ $assessment->school_year }}</span>
        </td>
    </tr>
    <tr>
        <td>
            <span class="field-label">Course &amp; Yr.: </span>
            <span class="field-underline">{{ strtoupper($student->course ?? '—') }}&nbsp;&nbsp;{{ $assessment->year_level }}</span>
        </td>
        <td>
            <span class="field-label">Registration Date: </span>
            <span class="field-underline">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
        </td>
        <td></td>
    </tr>
    <tr>
        <td>
            <span class="field-label">Major: </span>
            <span class="field-underline">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
        </td>
        <td colspan="2"></td>
    </tr>
</table>

{{-- ══════════════════════════════════════════════════════════
     MAIN BODY: SUBJECTS (LEFT) + FEES (RIGHT)
══════════════════════════════════════════════════════════ --}}
<table class="body-table">
    <tr>

        {{-- ── SUBJECTS COLUMN ─────────────────────────────────────── --}}
        <td class="subjects-cell">
            <table class="subjects">
                <thead>
                    <tr>
                        <th style="width:14%;">SUBJ.<br>CODE</th>
                        <th style="width:44%;">SUBJECT TITLE</th>
                        <th style="width:10%;">UNITS</th>
                        <th style="width:16%;">TIME</th>
                        <th style="width:16%;">DAY</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        // Use subjects passed from controller (from subjects table)
                        $subjectRows = collect($subjects ?? [])->map(function($s) {
                            return [
                                'code'  => $s->code,
                                'name'  => $s->name,
                                'units' => (float)($s->lec_units ?? $s->units ?? 0) + (float)($s->lab_units ?? 0),
                            ];
                        });

                        $totalUnits = (float)$assessment->lec_units + (float)($assessment->nstp_lec_units ?? 0) + (float)$assessment->lab_units;
                        $minRows    = 12;
                        $emptyRows  = max(0, $minRows - $subjectRows->count());
                    @endphp

                    @foreach($subjectRows as $row)
                    <tr>
                        <td class="code-col">{{ $row['code'] }}</td>
                        <td class="title-col">{{ $row['name'] }}</td>
                        <td class="units-col">{{ $row['units'] > 0 ? number_format((float)$row['units'], 1) : '' }}</td>
                        <td class="time-col"></td>
                        <td class="day-col"></td>
                    </tr>
                    @endforeach

                    @for($i = 0; $i < $emptyRows; $i++)
                    <tr>
                        <td class="code-col">&nbsp;</td>
                        <td class="title-col">&nbsp;</td>
                        <td class="units-col">&nbsp;</td>
                        <td class="time-col">&nbsp;</td>
                        <td class="day-col">&nbsp;</td>
                    </tr>
                    @endfor

                    {{-- Total row --}}
                    <tr class="total-row">
                        <td colspan="2" style="text-align:right; padding-right:6px;">Total:</td>
                        <td class="total-units">{{ number_format((float)$totalUnits, 1) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tbody>
            </table>

            <div style="margin-top: 5px; font-size: 8px; font-style: italic;">
                Student's Copy
            </div>
        </td>

        {{-- ── FEES COLUMN ──────────────────────────────────────────── --}}
        <td class="fees-cell">

            @php
                /*
                 * Parse fee_breakdown into display buckets.
                 *
                 * The real CCDI form shows four lines:
                 *   Registration Fee  → category = 'Miscellaneous', name contains 'Registration'
                 *   Tuition Fee       → category = 'Tuition'
                 *   Lab. Fee          → category = 'Laboratory'
                 *   Misc. Fee         → everything else in Miscellaneous / Other
                 *
                 * We sum each bucket from fee_breakdown so the numbers always
                 * match what was stored at assessment creation time.
                 */
                $breakdown = collect($assessment->fee_breakdown ?? []);

                $tuitionFee      = (float) ($assessment->tuition_fee ?? 0);
                $labFee          = (float) ($assessment->lab_fee ?? 0);
                $miscFee         = (float) ($assessment->misc_fee ?? 0);
                $otherFees       = (float) ($assessment->other_fees ?? 0);
                $registrationFee = 0; // Not stored separately
                $totalAssessment = (float) $assessment->total_assessment;

                $fmt = fn(float $v) => $v > 0
                    ? number_format($v, 2)
                    : '&nbsp;';
            @endphp

            {{-- FEES Header --}}
            <div class="fees-header">FEES</div>

            <table class="fee-row-table">
                <tr>
                    <td class="fee-label-col">Registration Fee:</td>
                    <td class="fee-amount-col {{ $registrationFee == 0 ? 'no-underline' : '' }}">
                        {!! $fmt($registrationFee) !!}
                    </td>
                </tr>
                <tr>
                    <td class="fee-label-col">Tuition Fee:</td>
                    <td class="fee-amount-col {{ $tuitionFee == 0 ? 'no-underline' : '' }}">
                        {!! $fmt($tuitionFee) !!}
                    </td>
                </tr>
                <tr>
                    <td class="fee-label-col">Lab. Fee:</td>
                    <td class="fee-amount-col {{ $labFee == 0 ? 'no-underline' : '' }}">
                        {!! $fmt($labFee) !!}
                    </td>
                </tr>
                <tr>
                    <td class="fee-label-col">Misc. Fee:</td>
                    <td class="fee-amount-col {{ $miscFee == 0 ? 'no-underline' : '' }}">
                        {!! $fmt($miscFee) !!}
                    </td>
                </tr>
                <tr class="fee-total-row">
                    <td class="fee-label-col"><strong>Total Assessment Fee:</strong></td>
                    <td class="fee-amount-col">
                        {{ number_format($totalAssessment, 2) }}
                    </td>
                </tr>
            </table>

            {{-- TERMS OF PAYMENT --}}
            <div class="terms-header">TERMS OF PAYMENT</div>

            @php
                /*
                 * $paymentTerms is passed by StudentFeeController::exportPdf()
                 * as a collection of StudentPaymentTerm models.
                 * We display them in term_order ASC (already sorted by controller).
                 */
                $terms = isset($paymentTerms) ? $paymentTerms : collect();
            @endphp

            <table class="term-row-table">
                @forelse($terms as $term)
                <tr>
                    <td class="term-label-col">{{ $term->term_name }}</td>
                    <td class="term-amount-col">
                        {{ number_format((float)$term->amount, 2) }}
                    </td>
                </tr>
                @empty
                {{-- Fallback: show standard term names with no amounts --}}
                @foreach(['Upon Registration', 'Prelim', 'Midterm', 'Semi-Final', 'Final'] as $tName)
                <tr>
                    <td class="term-label-col">{{ $tName }}</td>
                    <td class="term-amount-col">&nbsp;</td>
                </tr>
                @endforeach
                @endforelse
            </table>

        </td>{{-- end fees-cell --}}

    </tr>
</table>

{{-- ══════════════════════════════════════════════════════════
     FOOTER: SIGNATURES
══════════════════════════════════════════════════════════ --}}
<table class="footer-table">
    <tr>
        <td class="student-copy-cell">
            &nbsp;
        </td>
        <td class="signature-cell">
            <div class="sig-line"></div>
            <div style="text-align:center; font-size:8px;">Student Signature</div>
        </td>
        <td style="width:5%;">&nbsp;</td>
        <td class="approved-cell">
            <div style="font-size: 8px; margin-bottom: 2px;">Approved by:</div>
            <div class="sig-line"></div>
            <div class="sig-name" style="text-align:center;">
                {{ strtoupper(config('school.registrar_name', 'LEAH SANTA M. DETERA')) }}
            </div>
            <div class="sig-title" style="text-align:center;">Registrar</div>
        </td>
    </tr>
</table>

</body>
</html>