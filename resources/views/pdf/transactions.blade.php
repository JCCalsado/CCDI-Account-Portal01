<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Transaction Report — {{ $student->name ?? 'Student' }}</title>
    <style>
        /* ── Reset & Base ──────────────────────────────────────────────────── */
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DejaVu Sans', 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #1a1a2e;
            background: #ffffff;
            line-height: 1.5;
        }

        /* ── Page layout ───────────────────────────────────────────────────── */
        .page {
            padding: 32px 36px 28px;
            max-width: 780px;
            margin: 0 auto;
        }

        /* ── School Header ─────────────────────────────────────────────────── */
        .school-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            border-bottom: 3px solid #1e3a8a;
            padding-bottom: 14px;
            margin-bottom: 18px;
        }

        .school-name {
            font-size: 18px;
            font-weight: 700;
            color: #1e3a8a;
            letter-spacing: -0.3px;
        }

        .school-sub {
            font-size: 10px;
            color: #6b7280;
            margin-top: 2px;
        }

        .doc-meta { text-align: right; }

        .doc-title {
            font-size: 13px;
            font-weight: 700;
            color: #1e3a8a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .doc-date {
            font-size: 9.5px;
            color: #6b7280;
            margin-top: 3px;
        }

        .doc-ref {
            font-size: 9px;
            color: #9ca3af;
            margin-top: 1px;
            font-family: 'Courier New', Courier, monospace;
        }

        /* ── Student Info Card ─────────────────────────────────────────────── */
        .info-card {
            background: #f0f4ff;
            border: 1px solid #c7d7f8;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 16px;
        }

        .info-grid   { display: table; width: 100%; }
        .info-row    { display: table-row; }

        .info-label {
            display: table-cell;
            font-size: 9.5px;
            font-weight: 700;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            width: 130px;
            padding: 2px 0;
        }

        .info-value {
            display: table-cell;
            font-size: 10.5px;
            color: #111827;
            padding: 2px 0;
        }

        .info-value strong { font-weight: 700; }

        /* ── Term Badge ────────────────────────────────────────────────────── */
        .term-badge {
            display: inline-block;
            background: #1e3a8a;
            color: #ffffff;
            font-size: 10px;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            margin-bottom: 12px;
            letter-spacing: 0.3px;
        }

        /* ── Transaction Table ─────────────────────────────────────────────── */
        .tx-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .tx-table thead tr {
            background: #1e3a8a;
            color: #ffffff;
        }

        .tx-table th {
            padding: 8px 10px;
            font-size: 9.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: left;
        }

        .tx-table th.right  { text-align: right; }
        .tx-table th.center { text-align: center; }

        .tx-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
        }

        .tx-table tbody tr:nth-child(even) { background: #f9fafb; }

        .tx-table tbody tr:last-child { border-bottom: 2px solid #d1d5db; }

        .tx-table td {
            padding: 7px 10px;
            font-size: 10.5px;
            color: #374151;
            vertical-align: middle;
        }

        .tx-table td.right  { text-align: right; }
        .tx-table td.center { text-align: center; }

        .tx-table td.amount {
            text-align: right;
            font-weight: 600;
            color: #065f46;
            font-size: 11px;
        }

        .tx-table td.ref {
            font-family: 'Courier New', Courier, monospace;
            font-size: 9.5px;
            color: #4b5563;
        }

        /* ── Status Badges ─────────────────────────────────────────────────── */
        .badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 10px;
            font-size: 8.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .badge-paid    { background: #d1fae5; color: #065f46; }
        .badge-pending { background: #fef9c3; color: #854d0e; }
        .badge-failed  { background: #fee2e2; color: #991b1b; }
        .badge-waiting { background: #dbeafe; color: #1e40af; }
        .badge-default { background: #f3f4f6; color: #374151; }

        /* ── Financial Summary ─────────────────────────────────────────────── */
        .summary-box {
            border: 1.5px solid #e5e7eb;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 24px;
        }

        .summary-header {
            background: #f3f4f6;
            border-bottom: 1px solid #e5e7eb;
            padding: 7px 14px;
        }

        .summary-header span {
            font-size: 9.5px;
            font-weight: 700;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-rows { padding: 0; }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 14px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 10.5px;
        }

        .summary-row:last-child { border-bottom: none; }

        .summary-row .s-label { color: #6b7280; }
        .summary-row .s-value { font-weight: 600; color: #111827; }

        .summary-row.total-row {
            background: #eff6ff;
            border-top: 2px solid #bfdbfe;
        }

        .summary-row.total-row .s-label {
            font-size: 11px;
            font-weight: 700;
            color: #1e40af;
        }

        .summary-row.total-row .s-value {
            font-size: 13px;
            font-weight: 800;
            color: #1e40af;
        }

        .summary-row.balance-zero .s-value { color: #059669; }
        .summary-row.balance-owed .s-value { color: #dc2626; }

        /* ── Footer ────────────────────────────────────────────────────────── */
        .footer {
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
            text-align: center;
        }

        .footer p {
            font-size: 8.5px;
            color: #9ca3af;
            margin-bottom: 2px;
        }

        .footer .disclaimer {
            font-size: 8px;
            color: #d1d5db;
            margin-top: 4px;
        }

        /* ── Utility ───────────────────────────────────────────────────────── */
        .text-muted   { color: #9ca3af; }
        .text-right   { text-align: right; }
        .no-transactions {
            text-align: center;
            padding: 28px;
            color: #9ca3af;
            font-size: 11px;
            font-style: italic;
        }
    </style>
</head>
<body>
<div class="page">

    {{-- ── School Header ───────────────────────────────────────────────────── --}}
    <div class="school-header">
        <div>
            <div class="school-name">CCDI Account Portal</div>
            <div class="school-sub">Student Financial Transaction Report</div>
        </div>
        <div class="doc-meta">
            <div class="doc-title">Transaction Report</div>
            <div class="doc-date">Generated: {{ now()->format('F d, Y \a\t h:i A') }}</div>
            @if($termKey && $termKey !== 'All Terms')
                <div class="doc-date">Term: {{ $termKey }}</div>
            @endif
            <div class="doc-ref">Ref: TXN-RPT-{{ now()->format('YmdHis') }}</div>
        </div>
    </div>

    {{-- ── Student Info ─────────────────────────────────────────────────────── --}}
    <div class="info-card">
        <div class="info-grid">
            <div class="info-row">
                <div class="info-label">Student Name</div>
                <div class="info-value"><strong>{{ $student->name ?? '—' }}</strong></div>
            </div>
            <div class="info-row">
                <div class="info-label">Student No.</div>
                <div class="info-value">{{ $student->account_id ?? '—' }}</div>
            </div>
            <div class="info-row">
                <div class="info-label">Email</div>
                <div class="info-value">{{ $student->email ?? '—' }}</div>
            </div>
            @if(isset($student->student) && $student->student)
                @if($student->student->course ?? false)
                <div class="info-row">
                    <div class="info-label">Course</div>
                    <div class="info-value">{{ $student->student->course }}</div>
                </div>
                @endif
                @if($student->student->year_level ?? false)
                <div class="info-row">
                    <div class="info-label">Year Level</div>
                    <div class="info-value">{{ $student->student->year_level }}</div>
                </div>
                @endif
            @endif
            <div class="info-row">
                <div class="info-label">Report Period</div>
                <div class="info-value">{{ $termKey !== 'All Terms' ? $termKey : 'All Academic Terms' }}</div>
            </div>
        </div>
    </div>

    {{-- ── Term Badge ────────────────────────────────────────────────────────── --}}
    <div>
        <span class="term-badge">
            {{ $termKey !== 'All Terms' ? '📅 ' . $termKey : '📋 All Terms' }}
        </span>
    </div>

    {{-- ── Transaction Table ─────────────────────────────────────────────────── --}}
    @if($transactions->isEmpty())
        <div class="no-transactions">No confirmed transactions found for this period.</div>
    @else
        <table class="tx-table">
            <thead>
                <tr>
                    <th style="width:28px">#</th>
                    <th>Date</th>
                    <th>OR / Reference</th>
                    <th>Payment For</th>
                    <th>Method</th>
                    <th class="right">Amount</th>
                    <th class="center">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($transactions as $i => $transaction)
                    @php
                        $channel  = strtolower($transaction->payment_channel ?? '');
                        $isCash   = in_array($channel, ['cash', 'cash_payment', 'over_the_counter']);
                        $refLabel = $isCash ? 'OR' : 'Ref';
                        $refValue = $isCash
                            ? ($transaction->or_number ?? $transaction->reference ?? '—')
                            : ($transaction->reference ?? '—');

                        $methodLabels = [
                            'cash'          => 'Cash',
                            'gcash'         => 'GCash',
                            'bank_transfer' => 'Bank Transfer',
                            'credit_card'   => 'Credit Card',
                            'debit_card'    => 'Debit Card',
                            'paymaya'       => 'Maya',
                            'maya'          => 'Maya',
                            'paymongo'      => 'Online',
                        ];
                        $method = $methodLabels[$channel] ?? ucwords(str_replace('_', ' ', $channel ?: '—'));

                        $termName = $transaction->meta['term_name']
                            ?? $transaction->meta['description']
                            ?? $transaction->type
                            ?? '—';

                        $statusBadge = match(strtolower($transaction->status ?? '')) {
                            'paid'              => 'badge-paid',
                            'pending'           => 'badge-pending',
                            'failed'            => 'badge-failed',
                            'awaiting_approval',
                            'awaiting_proof'    => 'badge-waiting',
                            default             => 'badge-default',
                        };

                        $statusLabel = match(strtolower($transaction->status ?? '')) {
                            'awaiting_approval' => 'Pending',
                            'awaiting_proof'    => 'Proof Needed',
                            default             => ucfirst($transaction->status ?? '—'),
                        };

                        $paidAt = $transaction->paid_at ?? $transaction->created_at;
                    @endphp
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>
                            {{ \Carbon\Carbon::parse($paidAt)->format('M d, Y') }}
                            <br>
                            <span class="text-muted" style="font-size:8.5px">
                                {{ \Carbon\Carbon::parse($paidAt)->format('h:i A') }}
                            </span>
                        </td>
                        <td class="ref">
                            {{ $refValue }}
                            <br>
                            <span class="text-muted" style="font-size:8.5px">{{ $refLabel }} No.</span>
                        </td>
                        <td>{{ $termName }}</td>
                        <td>{{ $method }}</td>
                        <td class="amount">₱{{ number_format($transaction->amount, 2) }}</td>
                        <td class="center">
                            <span class="badge {{ $statusBadge }}">{{ $statusLabel }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- ── Financial Summary ─────────────────────────────────────────────────── --}}
    <div class="summary-box">
        <div class="summary-header">
            <span>Financial Summary</span>
        </div>
        <div class="summary-rows">
            <div class="summary-row">
                <span class="s-label">Total Assessed</span>
                <span class="s-value">₱{{ number_format($totalCharges, 2) }}</span>
            </div>
            <div class="summary-row">
                <span class="s-label">Total Payments Recorded</span>
                <span class="s-value" style="color:#059669">₱{{ number_format($totalPaid, 2) }}</span>
            </div>
            <div class="summary-row {{ $netBalance <= 0 ? 'balance-zero' : 'balance-owed' }}">
                <span class="s-label">{{ $netBalance <= 0 ? 'Credit / Overpayment' : 'Outstanding Balance' }}</span>
                <span class="s-value">₱{{ number_format(abs($netBalance), 2) }}</span>
            </div>
            <div class="summary-row total-row">
                <span class="s-label">Net Balance</span>
                <span class="s-value">{{ $netBalance <= 0 ? '✓ Fully Paid' : '₱' . number_format($netBalance, 2) }}</span>
            </div>
        </div>
    </div>

    {{-- ── Footer ───────────────────────────────────────────────────────────── --}}
    <div class="footer">
        <p>This document is computer-generated. For inquiries, contact the Accounting Office.</p>
        <p>{{ config('app.name', 'CCDI Account Portal') }} · Printed: {{ now()->format('F d, Y') }}</p>
        <p class="disclaimer">
            This report reflects transactions verified up to the generation date.
            Awaiting-approval payments are not included.
        </p>
    </div>

</div>
</body>
</html>