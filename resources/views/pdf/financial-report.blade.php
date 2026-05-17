<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Report — {{ $schoolYear }} {{ $semester }}</title>
    <style>
        @page {
            margin: 14mm 16mm 14mm 16mm;
            size: A4 landscape;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #1a1a1a;
            width: 100%;
        }

        /* ── Header ── */
        .header {
            margin-bottom: 16px;
            border-bottom: 2px solid #4f46e5;
            padding-bottom: 12px;
        }
        .header .school-name  { font-size: 15px; font-weight: bold; color: #111827; }
        .header .report-title { font-size: 11px; color: #4f46e5; margin-top: 3px; font-weight: 600; }
        .header .meta         { font-size: 9px;  color: #9ca3af; margin-top: 3px; }

        /* ── Summary cards (4-column) ── */
        .summary-table { width: 100%; border-collapse: separate; border-spacing: 6px; margin-bottom: 18px; }
        .summary-cell {
            width: 25%;
            padding: 10px 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            text-align: center;
        }
        .summary-cell .label { font-size: 8px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
        .summary-cell .value { font-size: 15px; font-weight: bold; color: #111827; }
        .summary-cell .value.green { color: #059669; }
        .summary-cell .value.red   { color: #dc2626; }
        .summary-cell .sub { font-size: 8px; color: #9ca3af; margin-top: 2px; }

        /* ── Section title ── */
        .section-title {
            font-size: 10px;
            font-weight: bold;
            color: #374151;
            margin-bottom: 8px;
            margin-top: 18px;
            border-left: 3px solid #4f46e5;
            padding-left: 8px;
        }

        /* ── Generic data table ── */
        table.data-table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
        table.data-table thead tr { background: #f3f4f6; }
        table.data-table th {
            padding: 7px 10px;
            text-align: left;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            font-size: 8px;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #e5e7eb;
            white-space: nowrap;
        }
        table.data-table td {
            padding: 7px 10px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
            vertical-align: middle;
        }
        table.data-table tr:last-child td { border-bottom: none; }
        table.data-table tr:nth-child(even) td { background: #fafafa; }
        .text-right  { text-align: right; }
        .text-center { text-align: center; }

        /* ── Outstanding table columns ── */
        .col-acct   { width: 10%; white-space: nowrap; }
        .col-ref    { width: 13%; white-space: nowrap; font-family: monospace; font-size: 9px; color: #4f46e5; }
        .col-name   { width: 16%; }
        .col-course { width: 28%; }
        .col-amt    { width: 11%; }
        .col-paid   { width: 11%; }
        .col-bal    { width: 11%; }

        /* ── Term breakdown table columns ── */
        .col-term     { width: 22%; }
        .col-tamt     { width: 16%; }
        .col-tcol     { width: 16%; }
        .col-tout     { width: 16%; }
        .col-tprog    { width: 30%; }

        /* ── Progress bar (term breakdown) ── */
        .prog-wrap {
            width: 100%;
            background: #e5e7eb;
            border-radius: 3px;
            height: 7px;
            overflow: hidden;
        }
        .prog-fill {
            height: 7px;
            background: #10b981;
            border-radius: 3px;
        }

        .badge { display: inline-block; padding: 2px 8px; font-size: 8.5px; font-weight: 600; border-radius: 3px; }
        .badge-amber { background: #fef3c7; color: #92400e; }
        .badge-green { background: #d1fae5; color: #065f46; }
        .empty { text-align: center; padding: 20px; color: #9ca3af; font-size: 11px; }

        /* ── Footer ── */
        .footer {
            margin-top: 24px;
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
            width: 100%;
            font-size: 8px;
            color: #9ca3af;
        }
        .footer-left  { float: left; }
        .footer-right { float: right; }
        .clearfix::after { content: ''; display: block; clear: both; }
    </style>
</head>
<body>

    {{-- ══ School Header ══ --}}
    <div class="header">
        <table style="width:100%; border-collapse:collapse; margin-bottom:10px;">
            <tr>
                <td style="width:70px; vertical-align:middle; padding-right:10px;">
                    <img src="file://{{ str_replace('\\', '/', public_path('images/logo.png')) }}"
                         width="60" height="60" style="display:block;">
                </td>
                <td style="vertical-align:middle; text-align:center;">
                    <div class="school-name">{{ strtoupper(config('school.name', 'Computer Communication Development Institute')) }}</div>
                    <div class="report-title">Financial Report &mdash; {{ $schoolYear }} &bullet; {{ $semester }}</div>
                    <div class="meta">Generated {{ now()->format('F j, Y \a\t g:i A') }}</div>
                </td>
                <td style="width:70px;"></td>
            </tr>
        </table>
    </div>

    {{-- ══ Summary Cards ══ --}}
    @php
        $collectionRate = $summary['totalAssessmentAmount'] > 0
            ? min(round(($summary['totalPaid'] / $summary['totalAssessmentAmount']) * 100), 100)
            : 0;
    @endphp
    <table class="summary-table">
        <tr>
            <td class="summary-cell">
                <div class="label">Total Assessments</div>
                <div class="value">{{ $summary['totalAssessments'] }}</div>
                <div class="sub">students assessed</div>
            </td>
            <td class="summary-cell">
                <div class="label">Total Assessment Amount</div>
                <div class="value">&#8369;{{ number_format($summary['totalAssessmentAmount'], 2) }}</div>
                <div class="sub">{{ $collectionRate }}% collected</div>
            </td>
            <td class="summary-cell">
                <div class="label">Total Paid</div>
                <div class="value green">&#8369;{{ number_format($summary['totalPaid'], 2) }}</div>
                <div class="sub">collected so far</div>
            </td>
            <td class="summary-cell">
                <div class="label">Outstanding</div>
                <div class="value red">&#8369;{{ number_format($summary['totalOutstanding'], 2) }}</div>
                <div class="sub">remaining balance</div>
            </td>
        </tr>
    </table>

    {{-- ══ Payment Term Breakdown ══ --}}
    @if(count($termBreakdown) > 0)
    <div class="section-title">
        Payment Term Breakdown ({{ $semester }} &bullet; {{ $schoolYear }})
    </div>
    <table class="data-table">
        <thead>
            <tr>
                <th class="col-term">Term</th>
                <th class="col-tamt text-right">Due Amount</th>
                <th class="col-tcol text-right">Collected</th>
                <th class="col-tout text-right">Outstanding</th>
                <th class="col-tprog">Collection Progress</th>
            </tr>
        </thead>
        <tbody>
            @foreach($termBreakdown as $term)
                @php
                    $pct = $term['totalAmount'] > 0
                        ? min(round(($term['collected'] / $term['totalAmount']) * 100), 100)
                        : 0;
                @endphp
                <tr>
                    <td class="col-term" style="font-weight:600;">{{ $term['termName'] }}</td>
                    <td class="col-tamt text-right">&#8369;{{ number_format($term['totalAmount'], 2) }}</td>
                    <td class="col-tcol text-right" style="color:#059669; font-weight:700;">
                        &#8369;{{ number_format($term['collected'], 2) }}
                    </td>
                    <td class="col-tout text-right" style="color:#dc2626; font-weight:700;">
                        &#8369;{{ number_format($term['outstanding'], 2) }}
                    </td>
                    <td class="col-tprog">
                        <table style="width:100%; border-collapse:collapse;">
                            <tr>
                                <td style="width:80%;">
                                    <div class="prog-wrap">
                                        <div class="prog-fill" style="width:{{ $pct }}%;"></div>
                                    </div>
                                </td>
                                <td style="width:20%; text-align:right; font-size:8px; color:#6b7280; padding-left:6px;">
                                    {{ $pct }}%
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    {{-- ══ Outstanding Balances ══ --}}
    <div class="section-title">
        Outstanding Balances ({{ $semester }} &bullet; {{ $schoolYear }})
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th class="col-acct">Account ID</th>
                <th class="col-ref">Latest Reference</th>
                <th class="col-name">Student Name</th>
                <th class="col-course">Course</th>
                <th class="col-amt text-right">Total Assessment</th>
                <th class="col-paid text-right">Paid</th>
                <th class="col-bal text-right">Outstanding</th>
            </tr>
        </thead>
        <tbody>
            @forelse($students as $student)
                <tr>
                    <td class="col-acct" style="font-family: monospace; font-size:9px;">
                        {{ $student['accountId'] }}
                    </td>
                    <td class="col-ref">{{ $student['latestRef'] }}</td>
                    <td class="col-name">{{ $student['studentName'] }}</td>
                    <td class="col-course">{{ $student['course'] ?? 'N/A' }}</td>
                    <td class="col-amt text-right">
                        &#8369;{{ number_format($student['total'], 2) }}
                    </td>
                    <td class="col-paid text-right" style="color:#059669;">
                        &#8369;{{ number_format($student['paid'], 2) }}
                    </td>
                    <td class="col-bal text-right" style="color:#dc2626; font-weight:700;">
                        &#8369;{{ number_format($student['balance'], 2) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="empty">
                        No outstanding balances for {{ $semester }} {{ $schoolYear }}.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer clearfix">
        <div class="footer-left">
            {{ config('school.name', 'CCDI') }} &bullet; Financial Report &bullet; {{ $schoolYear }} {{ $semester }}
        </div>
        <div class="footer-right">
            Printed: {{ now()->format('Y-m-d H:i') }}
        </div>
    </div>

</body>
</html>