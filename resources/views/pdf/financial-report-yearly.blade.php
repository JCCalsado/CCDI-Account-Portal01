<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Full Year Financial Report — {{ $schoolYear }}</title>
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

        /* ── Header ─────────────────────────────────────────────────────── */
        .header {
            margin-bottom: 16px;
            border-bottom: 2px solid #4f46e5;
            padding-bottom: 12px;
        }
        .header .school-name  { font-size: 15px; font-weight: bold; color: #111827; }
        .header .report-title { font-size: 11px; color: #4f46e5; margin-top: 3px; font-weight: 600; }
        .header .meta         { font-size: 9px; color: #9ca3af; margin-top: 3px; }

        /* ── Year summary cards ──────────────────────────────────────────── */
        .summary-table { width: 100%; border-collapse: separate; border-spacing: 6px; margin-bottom: 14px; }
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
        .summary-cell .value.blue  { color: #4f46e5; }
        .summary-cell .sub { font-size: 8px; color: #9ca3af; margin-top: 2px; }

        /* ── Per-semester breakdown bar ──────────────────────────────────── */
        .sem-breakdown {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
            font-size: 9px;
        }
        .sem-breakdown th {
            background: #ede9fe;
            color: #4338ca;
            padding: 6px 10px;
            text-align: center;
            font-weight: 700;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            border: 1px solid #c7d2fe;
        }
        .sem-breakdown td {
            padding: 6px 10px;
            text-align: center;
            border: 1px solid #e5e7eb;
            background: #fafafa;
        }
        .sem-breakdown td.label-cell {
            text-align: left;
            font-weight: 600;
            color: #374151;
            background: #f3f4f6;
        }

        /* ── Section titles ──────────────────────────────────────────────── */
        .section-title {
            font-size: 10px;
            font-weight: bold;
            color: #374151;
            margin-bottom: 8px;
            margin-top: 14px;
            border-left: 3px solid #4f46e5;
            padding-left: 8px;
        }

        /* ── Main data table ─────────────────────────────────────────────── */
        table.data-table { width: 100%; border-collapse: collapse; font-size: 9px; }
        table.data-table thead tr { background: #f3f4f6; }
        table.data-table th {
            padding: 6px 8px;
            text-align: left;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            font-size: 7.5px;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #e5e7eb;
            white-space: nowrap;
        }
        table.data-table th.sem-header {
            background: #ede9fe;
            color: #4338ca;
            text-align: center;
        }
        table.data-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
            vertical-align: middle;
        }
        table.data-table tr:last-child td { border-bottom: none; }
        table.data-table tr:nth-child(even) td { background: #fafafa; }
        .text-right  { text-align: right; }
        .text-center { text-align: center; }

        /* Fixed column widths — landscape A4 gives ~257mm usable */
        .col-acct    { width: 9%; white-space: nowrap; }
        .col-name    { width: 14%; }
        .col-course  { width: 16%; }
        /* Semester columns share middle space — calculated dynamically via inline style */
        .col-yr-total { width: 11%; }
        .col-yr-bal   { width: 10%; }
        .col-status   { width: 8%; }

        .badge { display: inline-block; padding: 2px 7px; font-size: 8px; font-weight: 600; border-radius: 3px; }
        .badge-amber { background: #fef3c7; color: #92400e; }
        .badge-green { background: #d1fae5; color: #065f46; }
        .badge-red   { background: #fee2e2; color: #991b1b; }

        .empty { text-align: center; padding: 20px; color: #9ca3af; font-size: 11px; }

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

        .sem-cell-head {
            font-size: 7px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
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
                    <div class="report-title">
                        Full Academic Year Financial Report &mdash; {{ $schoolYear }}
                        &nbsp;&bullet;&nbsp;
                        {{ implode(', ', $semesters) }} Semester{{ count($semesters) > 1 ? 's' : '' }}
                    </div>
                    <div class="meta">Generated {{ $generatedAt->format('F j, Y \a\t g:i A') }}</div>
                </td>
                <td style="width:70px;"></td>
            </tr>
        </table>
    </div>

    {{-- ══ Year-Level Summary Cards ══ --}}
    <table class="summary-table">
        <tr>
            <td class="summary-cell">
                <div class="label">Students Assessed</div>
                <div class="value blue">{{ number_format($summary['studentCount']) }}</div>
                <div class="sub">unique students · {{ count($semesters) }} semester{{ count($semesters) > 1 ? 's' : '' }}</div>
            </td>
            <td class="summary-cell">
                <div class="label">Total Assessment</div>
                <div class="value">&#8369;{{ number_format($summary['totalAssessed'], 2) }}</div>
                <div class="sub">full year billed</div>
            </td>
            <td class="summary-cell">
                <div class="label">Total Collected</div>
                <div class="value green">&#8369;{{ number_format($summary['totalPaid'], 2) }}</div>
                @php
                    $collectionRate = $summary['totalAssessed'] > 0
                        ? round(($summary['totalPaid'] / $summary['totalAssessed']) * 100)
                        : 0;
                @endphp
                <div class="sub">{{ $collectionRate }}% collection rate</div>
            </td>
            <td class="summary-cell">
                <div class="label">Outstanding Balance</div>
                <div class="value red">&#8369;{{ number_format($summary['totalOutstanding'], 2) }}</div>
                <div class="sub">remaining unpaid</div>
            </td>
        </tr>
    </table>

    {{-- ══ Per-Semester Breakdown Bar ══ --}}
    @if(count($semesters) > 1)
    <div class="section-title">Semester Breakdown — {{ $schoolYear }}</div>
    <table class="sem-breakdown">
        <thead>
            <tr>
                <th style="width:18%; text-align:left;">Semester</th>
                <th>Students</th>
                <th>Assessed</th>
                <th>Collected</th>
                <th>Outstanding</th>
                <th>Collection Rate</th>
            </tr>
        </thead>
        <tbody>
            @foreach($semesters as $sem)
            @php $sd = $semesterSummaries[$sem]; @endphp
            <tr>
                <td class="label-cell">{{ $sem }} Semester</td>
                <td style="text-align:center;">{{ number_format($sd['count']) }}</td>
                <td style="text-align:right;">&#8369;{{ number_format($sd['assessed'], 2) }}</td>
                <td style="text-align:right; color:#059669; font-weight:700;">&#8369;{{ number_format($sd['paid'], 2) }}</td>
                <td style="text-align:right; color:#dc2626; font-weight:700;">&#8369;{{ number_format($sd['outstanding'], 2) }}</td>
                <td style="text-align:center;">
                    @php $rate = $sd['assessed'] > 0 ? round(($sd['paid'] / $sd['assessed']) * 100) : 0; @endphp
                    {{ $rate }}%
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    {{-- ══ Student Account Status Table ══ --}}
    <div class="section-title">
        Student Account Status &mdash; Full Year {{ $schoolYear }}
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th class="col-acct">Account ID</th>
                <th class="col-name">Student Name</th>
                <th class="col-course">Course</th>
                {{-- Dynamic semester columns --}}
                @foreach($semesters as $sem)
                <th class="sem-header text-right" style="width:{{ max(8, floor(32 / count($semesters))) }}%;">
                    {{ $sem }} Assessed
                </th>
                <th class="sem-header text-right" style="width:{{ max(7, floor(28 / count($semesters))) }}%;">
                    {{ $sem }} Balance
                </th>
                @endforeach
                <th class="col-yr-total text-right">Year Total</th>
                <th class="col-yr-bal text-right">Year Balance</th>
                <th class="col-status text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($students as $student)
            <tr>
                <td class="col-acct" style="font-family: monospace; font-size:8.5px;">
                    {{ $student['accountId'] }}
                </td>
                <td class="col-name">{{ $student['studentName'] }}</td>
                <td class="col-course">{{ $student['course'] ?? 'N/A' }}</td>

                @foreach($semesters as $sem)
                @php $sd = $student['semesters'][$sem] ?? null; @endphp
                <td class="text-right">
                    @if($sd)
                        &#8369;{{ number_format($sd['total'], 2) }}
                    @else
                        <span style="color:#d1d5db;">—</span>
                    @endif
                </td>
                <td class="text-right">
                    @if($sd)
                        <span style="{{ $sd['balance'] <= 0 ? 'color:#059669; font-weight:700;' : 'color:#dc2626; font-weight:700;' }}">
                            &#8369;{{ number_format($sd['balance'], 2) }}
                        </span>
                    @else
                        <span style="color:#d1d5db;">—</span>
                    @endif
                </td>
                @endforeach

                <td class="col-yr-total text-right" style="font-weight:700;">
                    &#8369;{{ number_format($student['yearTotal'], 2) }}
                </td>
                <td class="col-yr-bal text-right" style="{{ $student['yearBalance'] <= 0 ? 'color:#059669; font-weight:700;' : 'color:#dc2626; font-weight:700;' }}">
                    &#8369;{{ number_format($student['yearBalance'], 2) }}
                </td>
                <td class="col-status text-center">
                    @php
                        $badgeClass = match($student['yearStatus']) {
                            'Fully Paid' => 'badge badge-green',
                            'Partial'    => 'badge badge-amber',
                            default      => 'badge badge-red',
                        };
                    @endphp
                    <span class="{{ $badgeClass }}">{{ $student['yearStatus'] }}</span>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="{{ 3 + (count($semesters) * 2) + 3 }}" class="empty">
                    No students assessed for {{ $schoolYear }}.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer clearfix">
        <div class="footer-left">
            {{ config('school.name', 'CCDI') }} &bullet; Full Year Financial Report &bullet; {{ $schoolYear }}
        </div>
        <div class="footer-right">
            Printed: {{ now()->format('Y-m-d H:i') }}
        </div>
    </div>

</body>
</html>