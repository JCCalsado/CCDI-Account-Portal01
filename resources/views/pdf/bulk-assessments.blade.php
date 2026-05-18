<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Student Assessments — {{ $schoolYear }} {{ $semester }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #000; padding: 16px; }

        /* ── Page header ── */
        .header { text-align: center; margin-bottom: 14px; border-bottom: 2px solid #1a3c5e; padding-bottom: 10px; }
        .header h1 { font-size: 13px; text-transform: uppercase; letter-spacing: 1px; color: #1a3c5e; }
        .header h2 { font-size: 10px; margin-top: 3px; color: #444; }
        .header .meta { font-size: 8px; color: #666; margin-top: 4px; }

        /* ── Per-student card ── */
        .page-break { page-break-after: always; }
        .student-block { margin-bottom: 18px; border: 1px solid #ccc; padding: 10px; border-radius: 4px; }
        .student-name { font-size: 11px; font-weight: bold; margin-bottom: 4px; }
        .student-meta { font-size: 8px; color: #555; margin-bottom: 10px; }
        .discount-badge {
            display: inline-block;
            padding: 1px 6px;
            font-size: 7.5px;
            font-weight: 700;
            border-radius: 3px;
            background: #dbeafe;
            color: #1e40af;
            margin-left: 4px;
        }

        /* ── Two-column split layout for fee breakdown ── */
        .fee-columns {
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px 0;
            margin-bottom: 8px;
        }
        .fee-columns > tr > td { vertical-align: top; width: 50%; }

        /* ── Section title ── */
        .section-title {
            font-size: 8.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #fff;
            background: #1a3c5e;
            padding: 3px 6px;
            margin-bottom: 0;
        }

        /* ── Tables inside each column ── */
        table.fee-table { width: 100%; border-collapse: collapse; margin-top: 0; }
        table.fee-table th {
            background: #e8f0f7;
            color: #1a3c5e;
            padding: 4px 6px;
            text-align: left;
            font-size: 8px;
            border-bottom: 1px solid #c5d5e8;
        }
        table.fee-table td { padding: 3px 6px; border-bottom: 1px solid #eee; font-size: 8.5px; }
        table.fee-table tr:nth-child(even) td { background: #f7f9fc; }
        table.fee-table tr.subtotal-row td {
            font-weight: bold;
            border-top: 2px solid #1a3c5e;
            background: #eef4fb;
        }

        /* ── Total assessment row spanning both columns ── */
        .total-banner {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
        }
        .total-banner td {
            background: #1a3c5e;
            color: #fff;
            font-weight: bold;
            font-size: 10px;
            padding: 5px 8px;
        }
        .total-banner td:last-child { text-align: right; }

        /* ── Payment terms table ── */
        table.terms-table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.terms-table th { background: #1a3c5e; color: #fff; padding: 4px 6px; text-align: left; font-size: 8px; }
        table.terms-table td { padding: 3px 6px; border-bottom: 1px solid #eee; font-size: 8.5px; }
        table.terms-table tr:nth-child(even) td { background: #f7f7f7; }
        table.terms-table .total-row td { font-weight: bold; border-top: 2px solid #000; background: #eef4fb; }
    </style>
</head>
<body>
    <div class="header">
        <table width="100%" style="border-collapse:collapse; margin-bottom:6px;">
            <tr>
                <td width="56" style="vertical-align:middle; text-align:center;">
                    <img src="file://{{ str_replace('\\', '/', public_path('images/logo.png')) }}"
                         width="48" height="48" style="display:block;">
                </td>
                <td style="vertical-align:middle; text-align:center;">
                    <h1>{{ config('school.name', 'Computer Communication Development Institute') }}</h1>
                </td>
                <td width="56"></td>
            </tr>
        </table>
        <h2>Student Assessments &mdash; {{ $schoolYear }} &bullet; {{ $semester }}</h2>
        <div class="meta">Generated {{ $generatedAt->format('F j, Y \a\t g:i A') }} &bullet; {{ $assessments->count() }} students</div>
    </div>

    @foreach ($assessments as $i => $assessment)
        @php
            $student = $assessment->user;
            $terms   = $assessment->paymentTerms->sortBy('term_order');
        @endphp

        <div class="student-block {{ $i > 0 && $i % 3 === 0 ? 'page-break' : '' }}">

            {{-- ── Student name + meta ── --}}
            <div class="student-name">
                {{ $student?->last_name }}, {{ $student?->first_name }}
                {{ $student?->middle_initial ? strtoupper($student->middle_initial).'.' : '' }}
            </div>
            <div class="student-meta">
                ID: {{ $student?->account_id ?? 'N/A' }} &nbsp;|&nbsp;
                {{ $assessment->course }} &nbsp;|&nbsp;
                Year {{ $assessment->year_level }} &nbsp;|&nbsp;
                Assessment No: {{ $assessment->assessment_number }}
                @if($assessment->discount_type && $assessment->discount_percentage > 0)
                    <span class="discount-badge">
                        {{ $assessment->discount_type }} {{ number_format($assessment->discount_percentage, 0) }}%
                    </span>
                @endif
            </div>

            {{-- ══ Fee Breakdown: Two columns ══
                 Left  → Units (Lec, Lab, NSTP, total units)
                 Right → Fees (Tuition, Lab Fee, Misc Fee, subtotals)
            ──────────────────────────────────────────── --}}
            <table class="fee-columns">
                <tr>
                    {{-- ── Left column: Units ── --}}
                    <td>
                        <div class="section-title">Units</div>
                        <table class="fee-table">
                            <thead>
                                <tr>
                                    <th>Unit Type</th>
                                    <th style="text-align:right">Units</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Lecture</td>
                                    <td style="text-align:right">{{ $assessment->lec_units }}</td>
                                </tr>
                                <tr>
                                    <td>Laboratory</td>
                                    <td style="text-align:right">{{ $assessment->lab_units }}</td>
                                </tr>
                                @if($assessment->nstp_lec_units > 0)
                                <tr>
                                    <td>NSTP</td>
                                    <td style="text-align:right">{{ $assessment->nstp_lec_units }}</td>
                                </tr>
                                @endif
                                <tr class="subtotal-row">
                                    <td>Total Units</td>
                                    <td style="text-align:right">
                                        {{ $assessment->lec_units + $assessment->lab_units + ($assessment->nstp_lec_units ?? 0) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>

                    {{-- ── Right column: Fees ── --}}
                    <td>
                        <div class="section-title">Fees</div>
                        <table class="fee-table">
                            <thead>
                                <tr>
                                    <th>Fee</th>
                                    <th style="text-align:right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Tuition Fee</td>
                                    <td style="text-align:right">&#8369;{{ number_format($assessment->tuition_fee, 2) }}</td>
                                </tr>
                                <tr>
                                    <td>Laboratory Fee</td>
                                    <td style="text-align:right">&#8369;{{ number_format($assessment->lab_fee, 2) }}</td>
                                </tr>
                                <tr>
                                    <td>Miscellaneous Fee</td>
                                    <td style="text-align:right">&#8369;{{ number_format($assessment->misc_fee, 2) }}</td>
                                </tr>
                                @if($assessment->discount_type && $assessment->discount_percentage > 0)
                                @php
                                    $discountAmount = ($assessment->tuition_fee * $assessment->discount_percentage / 100);
                                @endphp
                                <tr>
                                    <td style="color:#1e40af;">Discount ({{ $assessment->discount_type }})</td>
                                    <td style="text-align:right; color:#1e40af;">
                                        &minus;&#8369;{{ number_format($discountAmount, 2) }}
                                    </td>
                                </tr>
                                @endif
                                <tr class="subtotal-row">
                                    <td>Subtotal</td>
                                    <td style="text-align:right">
                                        &#8369;{{ number_format($assessment->tuition_fee + $assessment->lab_fee + $assessment->misc_fee, 2) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>
            </table>

            {{-- ── Total Assessment banner spanning full width ── --}}
            <table class="total-banner">
                <tr>
                    <td>Total Assessment</td>
                    <td>&#8369;{{ number_format($assessment->total_assessment, 2) }}</td>
                </tr>
            </table>

            {{-- ── Payment Terms ── --}}
            <div class="section-title" style="margin-top:8px;">Payment Terms</div>
            <table class="terms-table">
                <thead>
                    <tr>
                        <th>Term</th>
                        <th style="text-align:right">Amount Due</th>
                        <th style="text-align:right">Balance</th>
                        <th style="text-align:center">Status</th>
                        <th>Due Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($terms as $term)
                    <tr>
                        <td>{{ $term->term_name }}</td>
                        <td style="text-align:right">&#8369;{{ number_format($term->amount, 2) }}</td>
                        <td style="text-align:right; {{ $term->balance > 0 ? 'color:#dc2626;' : 'color:#059669;' }}">
                            &#8369;{{ number_format($term->balance, 2) }}
                        </td>
                        <td style="text-align:center;">{{ ucfirst($term->status) }}</td>
                        <td>{{ $term->due_date ? \Carbon\Carbon::parse($term->due_date)->format('M j, Y') : '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td>Total</td>
                        <td style="text-align:right">&#8369;{{ number_format($terms->sum('amount'), 2) }}</td>
                        <td style="text-align:right; {{ $terms->sum('balance') > 0 ? 'color:#dc2626;' : 'color:#059669;' }}">
                            &#8369;{{ number_format($terms->sum('balance'), 2) }}
                        </td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>

        </div>
    @endforeach
</body>
</html>