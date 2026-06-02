<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Account Statement — {{ $student->account_id ?? '' }} — {{ $schoolYear }} {{ $semester }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 16mm 18mm 16mm 18mm;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #1a1a1a;
        }

        /* ── Header ── */
        .header {
            border-bottom: 2px solid #1a3c5e;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .header table { width: 100%; border-collapse: collapse; }
        .school-name  { font-size: 14px; font-weight: bold; color: #1a3c5e; }
        .doc-title    { font-size: 11px; font-weight: bold; margin-top: 3px; color: #374151; }
        .doc-meta     { font-size: 8.5px; color: #9ca3af; margin-top: 2px; }

        /* ── Status stamp ── */
        .status-stamp {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        .stamp-paid      { background: #d1fae5; color: #065f46; border: 2px solid #065f46; }
        .stamp-partial   { background: #fef3c7; color: #92400e; border: 2px solid #d97706; }
        .stamp-underpaid { background: #fffbeb; color: #92400e; border: 2px solid #d97706; }
        .stamp-unpaid    { background: #fee2e2; color: #991b1b; border: 2px solid #dc2626; }

        /* ── Info sections ── */
        .section { margin-bottom: 14px; }
        .section-title {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 3px;
            margin-bottom: 7px;
        }
        .info-table { width: 100%; border-collapse: collapse; }
        .info-table td { padding: 3px 4px; vertical-align: top; font-size: 10px; }
        .info-table .lbl { font-weight: bold; width: 38%; color: #4b5563; }

        /* ── Assessment summary box ── */
        .summary-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 10px 14px;
            margin-bottom: 14px;
        }
        .summary-box table { width: 100%; border-collapse: collapse; }
        .summary-box td { padding: 3px 4px; font-size: 10px; }
        .summary-box .lbl { font-weight: bold; color: #4b5563; width: 50%; }
        .summary-box .val { text-align: right; }
        .summary-box .total-row td { border-top: 1px solid #cbd5e1; padding-top: 5px; font-weight: bold; font-size: 11px; }
        .summary-box .balance-row td { color: #dc2626; }
        .summary-box .paid-row td { color: #059669; }

        /* ── Transaction table ── */
        table.txn-table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
        table.txn-table thead tr { background: #1a3c5e; }
        table.txn-table th {
            padding: 6px 8px;
            text-align: left;
            color: #fff;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
        }
        table.txn-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        table.txn-table tr:nth-child(even) td { background: #f9fafb; }
        table.txn-table tfoot td {
            padding: 6px 8px;
            border-top: 2px solid #1a3c5e;
            font-weight: bold;
        }
        .text-right { text-align: right; }
        .mono { font-family: monospace; font-size: 9px; color: #4f46e5; }
        .empty { text-align: center; padding: 18px; color: #9ca3af; }

        /* ── Footer ── */
        .footer {
            margin-top: 20px;
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
            font-size: 8px;
            color: #9ca3af;
        }
        .footer table { width: 100%; border-collapse: collapse; }
        .footer td:last-child { text-align: right; }
    </style>
</head>
<body>

    {{-- ══ Header ══ --}}
    <div class="header">
        <table>
            <tr>
                <td style="width:56px; vertical-align:middle;">
                    {{-- ⚠ Do NOT use file:// prefix. dompdf reads directly from the filesystem
                         via public_path(). Adding file:// breaks rendering on Hostinger.
                         Canonical filename: ccdilogo.png — matches receipt.blade.php. --}}
                    <img src="{{ public_path('images/ccdilogo.png') }}"
                         width="48" height="48" style="display:block;">
                </td>
                <td style="vertical-align:middle; padding-left:10px;">
                    <div class="school-name">{{ strtoupper(config('school.name', 'Computer Communication Development Institute')) }}</div>
                    <div class="doc-title">Student Account Statement</div>
                    <div class="doc-meta">{{ $semester }} Semester &bullet; {{ $schoolYear }} &bullet; Generated {{ $generatedAt->format('F j, Y \a\t g:i A') }}</div>
                </td>
                <td style="vertical-align:middle; text-align:right; width:90px;">
                    @php
                        $stampClass = match($status) {
                            'Fully Paid' => 'stamp-paid',
                            'Partial'    => 'stamp-partial',
                            'Underpaid'  => 'stamp-underpaid',
                            default      => 'stamp-unpaid',
                        };
                    @endphp
                    <span class="status-stamp {{ $stampClass }}">{{ $status }}</span>
                </td>
            </tr>
        </table>
    </div>

    {{-- ══ Student Information ══ --}}
    <div class="section">
        <div class="section-title">Student Information</div>
        <table class="info-table">
            <tr>
                <td class="lbl">Full Name:</td>
                <td>
                    @php
                        $lastName  = $student->last_name  ?? '';
                        $firstName = $student->first_name ?? '';
                        $mi        = $student->middle_initial ? strtoupper($student->middle_initial) . '.' : '';
                        $fullName  = trim("{$lastName}, {$firstName}" . ($mi ? " {$mi}" : ''));
                    @endphp
                    {{ $fullName ?: 'N/A' }}
                </td>
                <td class="lbl">Account ID:</td>
                <td style="font-family:monospace;">{{ $student->account_id ?? 'N/A' }}</td>
            </tr>
            <tr>
                <td class="lbl">Course:</td>
                <td>{{ $assessment?->course ?? $student->course ?? 'N/A' }}</td>
                <td class="lbl">Year Level:</td>
                <td>{{ $assessment?->year_level ?? 'N/A' }}</td>
            </tr>
            @if($student->email)
            <tr>
                <td class="lbl">Email:</td>
                <td colspan="3">{{ $student->email }}</td>
            </tr>
            @endif
        </table>
    </div>

    {{-- ══ Assessment Summary ══ --}}
    @if($assessment)
    <div class="section">
        <div class="section-title">Assessment Summary</div>
        <div class="summary-box">
            <table>
                <tr>
                    <td class="lbl">Tuition Fee:</td>
                    <td class="val">&#8369;{{ number_format($assessment->tuition_fee, 2) }}</td>
                    <td class="lbl">Lecture Units:</td>
                    <td class="val">{{ $assessment->lec_units }}</td>
                </tr>
                <tr>
                    <td class="lbl">Laboratory Fee:</td>
                    <td class="val">&#8369;{{ number_format($assessment->lab_fee, 2) }}</td>
                    <td class="lbl">Lab Units:</td>
                    <td class="val">{{ $assessment->lab_units }}</td>
                </tr>
                <tr>
                    <td class="lbl">Miscellaneous Fee:</td>
                    <td class="val">&#8369;{{ number_format($assessment->misc_fee, 2) }}</td>
                    <td class="lbl">Assessment No:</td>
                    <td class="val">{{ $assessment->assessment_number ?? 'N/A' }}</td>
                </tr>
                @if($assessment->discount_type && $assessment->discount_percentage > 0)
                <tr>
                    <td class="lbl" style="color:#1e40af;">Discount:</td>
                    <td class="val" style="color:#1e40af;">{{ $assessment->discount_type }} ({{ number_format($assessment->discount_percentage, 0) }}%)</td>
                    <td></td><td></td>
                </tr>
                @endif
                <tr class="total-row">
                    <td class="lbl">Total Assessment:</td>
                    <td class="val">&#8369;{{ number_format($totalAmount, 2) }}</td>
                    <td></td><td></td>
                </tr>
                <tr class="paid-row">
                    <td class="lbl">Total Paid:</td>
                    <td class="val">&#8369;{{ number_format($totalPaid, 2) }}</td>
                    <td></td><td></td>
                </tr>
                <tr class="balance-row">
                    <td class="lbl">Outstanding Balance:</td>
                    <td class="val">&#8369;{{ number_format($totalBalance, 2) }}</td>
                    <td></td><td></td>
                </tr>
            </table>
        </div>
    </div>
    @endif

    {{-- ══ Payment Transactions ══ --}}
    <div class="section">
        <div class="section-title">
            Payment Transactions &mdash; {{ $semester }} {{ $schoolYear }}
            @if(count($transactions) > 0)
                ({{ count($transactions) }} payment{{ count($transactions) !== 1 ? 's' : '' }})
            @endif
        </div>

        <table class="txn-table">
            <thead>
                <tr>
                    <th>Date Paid</th>
                    <th>Reference</th>
                    <th>OR Number</th>
                    <th>Term</th>
                    <th>Method</th>
                    <th class="text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($transactions as $txn)
                <tr>
                    <td style="white-space:nowrap; color:#6b7280;">{{ $txn->paid_at }}</td>
                    <td><span class="mono">{{ $txn->reference }}</span></td>
                    <td style="font-family:monospace; font-size:9px;">{{ $txn->or_number ?? '—' }}</td>
                    <td>{{ $txn->term_name }}</td>
                    <td style="color:#4b5563;">{{ $txn->method }}</td>
                    <td class="text-right" style="color:#059669; font-weight:bold;">
                        &#8369;{{ number_format($txn->amount, 2) }}
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="empty">No payments recorded for this period.</td></tr>
                @endforelse
            </tbody>
            @if(count($transactions) > 0)
            <tfoot>
                <tr>
                    <td colspan="5" class="text-right" style="color:#1a1a1a;">Total Paid this Period:</td>
                    <td class="text-right" style="color:#059669;">
                        &#8369;{{ number_format($totalPaid, 2) }}
                    </td>
                </tr>
                @if($totalBalance > 0)
                <tr>
                    <td colspan="5" class="text-right" style="color:#1a1a1a;">Remaining Balance:</td>
                    <td class="text-right" style="color:#dc2626;">
                        &#8369;{{ number_format($totalBalance, 2) }}
                    </td>
                </tr>
                @endif
            </tfoot>
            @endif
        </table>
    </div>

    {{-- ══ Footer ══ --}}
    <div class="footer">
        <table>
            <tr>
                <td>{{ config('school.name', 'CCDI') }} &bullet; Account Statement &bullet; {{ $semester }} {{ $schoolYear }}</td>
                <td>Printed: {{ $generatedAt->format('Y-m-d H:i') }}</td>
            </tr>
        </table>
    </div>

</body>
</html>