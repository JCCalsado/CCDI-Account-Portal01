<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Receipt — {{ $assessment->assessment_number ?? $assessment->id }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #222;
            margin: 0;
            padding: 24px;
        }

        /* ── School Header ── */
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #222;
            padding-bottom: 14px;
        }
        .header h1 {
            margin: 0 0 4px;
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .header .address {
            font-size: 9px;
            color: #555;
            margin: 2px 0;
        }
        .header .doc-title {
            margin-top: 10px;
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ── PAID stamp ── */
        .paid-stamp {
            display: inline-block;
            border: 3px solid #065f46;
            color: #065f46;
            font-size: 20px;
            font-weight: bold;
            letter-spacing: 4px;
            padding: 4px 14px;
            border-radius: 4px;
            transform: rotate(-6deg);
            margin-top: 6px;
            opacity: 0.85;
        }

        /* ── Sections ── */
        .section { margin-bottom: 18px; }
        .section-title {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 4px;
            margin-bottom: 8px;
            color: #333;
        }
        .section-subtitle {
            font-size: 10px;
            color: #555;
            margin-bottom: 10px;
        }

        /* ── Student Info Table ── */
        /*
         * Tightened label column: width reduced from 30% → 18%
         * and white-space: nowrap keeps labels on one line.
         * Padding reduced from 4px 6px → 3px 4px to close the
         * horizontal gap between the label and its value.
         */
        .info-table { width: 100%; border-collapse: collapse; }
        .info-table td { padding: 3px 4px; vertical-align: top; }
        .info-table .lbl {
            font-weight: bold;
            width: 18%;
            white-space: nowrap;
            color: #444;
            padding-right: 6px;
        }

        /* ── Payment Box (one per transaction) ── */
        .payment-box {
            border: 2px solid #059669;
            border-radius: 6px;
            background: #f0fff4;
            padding: 12px 16px;
            margin-bottom: 10px;
        }
        .payment-box .pay-for { font-size: 11px; color: #374151; margin-bottom: 3px; }
        .payment-box .pay-label {
            font-size: 11px; font-weight: bold; color: #065f46;
            text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;
        }
        .payment-box .pay-amount {
            font-size: 26px; font-weight: bold; color: #065f46; margin-bottom: 6px;
        }
        .payment-box .pay-meta { font-size: 10px; color: #555; line-height: 1.9; }
        .payment-box .pay-meta span { font-weight: bold; color: #222; }

        /* ── Account Balance Box ── */
        .balance-box {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 10px 14px;
            background: #f9fafb;
        }
        .balance-row {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            font-size: 10px;
        }
        .balance-row.total {
            border-top: 1px solid #9ca3af;
            margin-top: 4px;
            padding-top: 6px;
            font-size: 12px;
            font-weight: bold;
        }
        .balance-row.credit { color: #065f46; }

        /* ── Badges ── */
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 9px;
            font-weight: bold;
        }
        .badge-paid     { background: #d1fae5; color: #065f46; }
        .badge-pending  { background: #fef9c3; color: #713f12; }
        .badge-awaiting { background: #dbeafe; color: #1e40af; }

        /* ── Footer ── */
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 9px;
            color: #888;
            border-top: 1px solid #eee;
            padding-top: 8px;
        }
        .footer .note { font-style: italic; margin-top: 4px; }
    </style>
</head>
<body>

{{-- ══ School Header ══ --}}
<div class="header">
    <table style="width:100%; border-collapse:collapse; margin-bottom:10px;">
        <tr>
            <td style="width:70px; vertical-align:middle; padding-right:10px;">
                <img src="file://{{ str_replace('\\', '/', public_path('images/ccdilogo.png')) }}"
                     width="60" height="60" style="display:block;">
            </td>
            <td style="vertical-align:middle; text-align:center;">
                <h1>{{ strtoupper(config('school.name', 'Computer Communication Development Institute')) }}</h1>
                <p class="address">
                    {{ config('school.main_address') }}
                    @if(config('school.annex_address'))
                        &nbsp;|&nbsp; {{ config('school.annex_address') }}
                    @endif
                </p>
                <p class="address">
                    Website: {{ config('school.website') }}
                    &nbsp;|&nbsp; Hotline: {{ config('school.hotline') }}
                    &nbsp;|&nbsp; CP: {{ config('school.mobile') }}
                </p>
            </td>
            <td style="width:70px;"></td>
        </tr>
    </table>

    <p class="doc-title">Official Payment Receipt</p>
    {{--
        Reference No. removed from header per requirement.
        Each transaction's OR/Ref No. is shown inside its own payment box below.
    --}}
    <div><span class="paid-stamp">PAID</span></div>
</div>

{{-- ══ Student Information ══ --}}
<div class="section">
    <div class="section-title">Student Information</div>
    {{--
        Removed: Student No. row.
        Remaining fields: Account ID, Full Name, Course, Year Level, Email.
        Restructured into 3 rows to keep the 2-column layout balanced.
        Label width tightened to 18% + nowrap; padding reduced to close
        the horizontal gap between label and value.
    --}}
    <table class="info-table">
        <tr>
            <td class="lbl">Account ID:</td>
            <td>{{ $student->account_id ?? '—' }}</td>
            <td class="lbl">Course:</td>
            <td>{{ $student->course ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Full Name:</td>
            <td>{{ $student->name }}</td>
            <td class="lbl">Year Level:</td>
            <td>{{ $student->year_level ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Email:</td>
            <td>{{ $student->email }}</td>
            <td class="lbl"></td>
            <td></td>
        </tr>
    </table>
</div>

{{-- ══ Payment Details — one box per transaction ══ --}}
<div class="section">
    <div class="section-title">Payment Details</div>

    {{-- Academic Term shown once at section level, not repeated per transaction --}}
    <p class="section-subtitle">Academic Term: <strong>{{ $academicTerm }}</strong></p>

    @php
        $methodLabels = [
            'cash'          => 'Cash',
            'gcash'         => 'GCash',
            'bank_transfer' => 'Bank Transfer',
            'credit_card'   => 'Credit Card',
            'debit_card'    => 'Debit Card',
            'paymaya'       => 'Maya',
            'maya'          => 'Maya',
            'paymongo'      => 'Online Payment',
        ];
    @endphp

    @foreach ($transactions as $txn)
        @php
            $paymentFor  = $txn->meta['term_name']
                ?? $txn->meta['description']
                ?? $txn->type
                ?? 'General Payment';

            $paymentDesc = $txn->meta['description'] ?? null;

            $methodRaw   = strtolower($txn->payment_channel ?? $txn->payment_method ?? '');
            $method      = $methodLabels[$methodRaw]
                ?? strtoupper(str_replace('_', ' ', $methodRaw))
                ?: 'N/A';

            /*
             * OR / Ref No. logic (mirrors Show.vue lines 1646–1650):
             *   cash → or_number  → label "OR No."
             *   other → reference → label "Ref No."
             */
            $isCash     = $methodRaw === 'cash';
            $orRefValue = $isCash
                ? ($txn->or_number ?? '—')
                : ($txn->reference ?? '—');
            $orRefLabel = $isCash ? 'OR No.' : 'Ref No.';

            $paidDate = $txn->paid_at
                ? $txn->paid_at->format('F d, Y')
                : $txn->created_at->format('F d, Y');
        @endphp

        <div class="payment-box">
            <p class="pay-for">Payment for:</p>
            <p class="pay-label">{{ $paymentFor }}</p>
            <p class="pay-amount">&#8369;{{ number_format($txn->amount, 2) }}</p>
            <p class="pay-meta">
                {{ $orRefLabel }}: <span style="font-family:monospace;">{{ $orRefValue }}</span><br>
                Payment Method: <span>{{ $method }}</span><br>
                Date Paid: <span>{{ $paidDate }}</span>
            </p>

            @if ($paymentDesc && $paymentDesc !== $paymentFor)
                <p class="pay-meta" style="margin-top:6px; border-top:1px solid #a7f3d0; padding-top:6px;">
                    Note: <span>{{ $paymentDesc }}</span>
                </p>
            @endif
        </div>
    @endforeach
</div>

{{-- ══ Account Balance ══ --}}
<div class="section">
    <div class="section-title">Account Balance</div>
    <div class="balance-box">
        <div class="balance-row">
            <span>Total Assessment:</span>
            <span>&#8369;{{ number_format($totalAssessment, 2) }}</span>
        </div>
        <div class="balance-row" style="color:#065f46; font-weight:bold;">
            <span>Total Paid (This Assessment):</span>
            <span>&#8369;{{ number_format($totalPaid, 2) }}</span>
        </div>

        @if ($remainingBalance < 0)
            <div class="balance-row total credit">
                <span>Remaining Balance (Credit):</span>
                <span>&#8369;{{ number_format(abs($remainingBalance), 2) }}</span>
            </div>
            <p style="font-size:9px; color:#065f46; margin-top:4px; font-style:italic;">
                ✔ You have a credit of &#8369;{{ number_format(abs($remainingBalance), 2) }} that will be applied to your next assessment.
            </p>
        @elseif ($remainingBalance == 0)
            <div class="balance-row total" style="color:#065f46;">
                <span>Remaining Balance:</span>
                <span>&#8369;0.00 — Fully Paid</span>
            </div>
        @else
            <div class="balance-row total">
                <span>Remaining Balance:</span>
                <span>&#8369;{{ number_format($remainingBalance, 2) }}</span>
            </div>
        @endif
    </div>
</div>

{{-- ══ Footer ══ --}}
<div class="footer">
    <p>Generated on {{ now()->format('F d, Y h:i A') }}</p>
    <p class="note">This is a computer-generated receipt. No signature is required.</p>
    <p class="note">Please keep this for your records.</p>
</div>

</body>
</html>