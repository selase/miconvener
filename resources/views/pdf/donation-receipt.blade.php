<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Receipt RCP-{{ $contribution->payment_reference }} — {{ $event?->name ?? 'Donation' }}</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #1b1b1b;
            margin: 0;
            padding: 0;
            font-size: 13px;
            line-height: 1.55;
        }
        .sheet {
            padding: 44px 48px;
        }
        .header-table {
            width: 100%;
            margin-bottom: 24px;
        }
        .header-table td {
            vertical-align: top;
        }
        .org-name {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 3px;
        }
        .org-sub {
            font-size: 11px;
            color: #4b5563;
        }
        .receipt-badge {
            text-align: right;
        }
        .receipt-title {
            font-size: 24px;
            font-weight: 700;
            color: #4f46e5;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin: 0 0 4px;
        }
        .receipt-number {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
        }
        .receipt-date {
            font-size: 11.5px;
            color: #6b7280;
            margin-top: 3px;
        }
        .rule {
            border: 0;
            border-top: 1px solid #e5e7eb;
            margin: 18px 0 24px;
        }
        .info-grid {
            width: 100%;
            margin-bottom: 28px;
        }
        .info-grid td {
            vertical-align: top;
            width: 50%;
        }
        .section-label {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 8px;
        }
        .info-item {
            margin-bottom: 6px;
        }
        .info-item .k {
            color: #6b7280;
            font-size: 11px;
        }
        .info-item .v {
            color: #111827;
            font-size: 13px;
            font-weight: 600;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        .items-table th {
            background-color: #f9fafb;
            border-top: 1px solid #e5e7eb;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            padding: 10px 12px;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #4b5563;
        }
        .items-table td {
            padding: 14px 12px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
        }
        .item-desc {
            font-weight: 600;
            color: #111827;
            font-size: 13.5px;
        }
        .item-sub {
            font-size: 11.5px;
            color: #6b7280;
            margin-top: 3px;
        }
        .text-right {
            text-align: right;
        }
        .total-box {
            width: 100%;
            margin-bottom: 28px;
        }
        .total-table {
            width: 280px;
            float: right;
            border-collapse: collapse;
        }
        .total-table td {
            padding: 6px 12px;
        }
        .total-table .grand-total {
            border-top: 2px solid #e5e7eb;
            border-bottom: 2px solid #e5e7eb;
            font-size: 16px;
            font-weight: 700;
            color: #111827;
            padding: 10px 12px;
        }
        .tribute-box {
            background-color: #f9fafb;
            border-left: 3px solid #6366f1;
            padding: 14px 18px;
            margin-bottom: 30px;
            border-radius: 4px;
        }
        .tribute-title {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #4338ca;
            margin-bottom: 5px;
        }
        .tribute-text {
            font-style: italic;
            font-size: 12.5px;
            color: #374151;
            line-height: 1.6;
        }
        .status-pill {
            display: inline-block;
            background-color: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
            padding: 3px 8px;
            border-radius: 9999px;
            font-size: 10.5px;
            font-weight: 600;
        }
        .footer {
            margin-top: 36px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 10.5px;
            line-height: 1.6;
        }
        .issuer-table {
            width: 100%;
            margin-top: 14px;
        }
        .issuer-table td {
            vertical-align: middle;
        }
        .issuer-logo img {
            height: 18px;
            width: auto;
        }
        .clearfix::after {
            content: "";
            clear: both;
            display: table;
        }
    </style>
</head>
<body>
<div class="sheet">
    <table class="header-table">
        <tr>
            <td>
                <div class="org-name">{{ $tenant?->name ?? config('app.name') }}</div>
                <div class="org-sub">
                    @if ($tenant?->email)
                        {{ $tenant->email }}<br>
                    @endif
                    Verified Event Beneficiary &amp; Organizer
                </div>
            </td>
            <td class="receipt-badge">
                <div class="receipt-title">Official Receipt</div>
                <div class="receipt-number">RCP-{{ $contribution->payment_reference }}</div>
                <div class="receipt-date">
                    Issued: {{ ($contribution->paid_at ?? $contribution->created_at)?->format('j M Y, g:i A') }}
                </div>
            </td>
        </tr>
    </table>

    <hr class="rule">

    <table class="info-grid">
        <tr>
            <td style="padding-right: 20px;">
                <div class="section-label">Donor Information</div>
                <div class="info-item">
                    <div class="k">Donor Name</div>
                    <div class="v">
                        @if ($contribution->is_anonymous)
                            Anonymous Donor <span style="font-size: 11px; font-weight: normal; color: #6b7280;">(Record Verified)</span>
                        @else
                            {{ $contribution->contributor_name ?: 'Supporter' }}
                        @endif
                    </div>
                </div>
                @if ($contribution->contributor_email)
                    <div class="info-item">
                        <div class="k">Email Address</div>
                        <div class="v">{{ $contribution->contributor_email }}</div>
                    </div>
                @endif
                @if ($contribution->contributor_phone)
                    <div class="info-item">
                        <div class="k">Phone Number</div>
                        <div class="v">{{ $contribution->contributor_phone }}</div>
                    </div>
                @endif
            </td>
            <td style="padding-left: 20px;">
                <div class="section-label">Event &amp; Beneficiary</div>
                <div class="info-item">
                    <div class="k">Event Title</div>
                    <div class="v">{{ $event?->name ?? 'Event Contribution' }}</div>
                </div>
                <div class="info-item">
                    <div class="k">Category / Cause</div>
                    <div class="v">
                        {{ $event?->contribution_title ?: 'Voluntary Contribution' }}
                        @if ($event?->event_category)
                            <span style="font-weight: normal; color: #6b7280;">({{ ucfirst($event->event_category) }})</span>
                        @endif
                    </div>
                </div>
                <div class="info-item">
                    <div class="k">Payment Status</div>
                    <div class="v">
                        <span class="status-pill">Payment Confirmed &amp; Settled</span>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th>Description</th>
                <th>Payment Channel</th>
                <th>Reference</th>
                <th class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <div class="item-desc">
                        {{ $event?->contribution_title ?: 'Voluntary Donation' }}
                    </div>
                    <div class="item-sub">
                        {{ $event?->name }} &middot; Direct Contribution
                    </div>
                </td>
                <td>
                    {{ strtoupper($contribution->provider ?: 'Mobile Money / Card') }}
                </td>
                <td style="font-family: 'Courier New', monospace; font-size: 12px;">
                    {{ $contribution->payment_reference }}
                </td>
                <td class="text-right" style="font-weight: 700; font-size: 14px;">
                    {{ $contribution->formattedAmount() }}
                </td>
            </tr>
        </tbody>
    </table>

    <div class="total-box clearfix">
        <table class="total-table">
            <tr>
                <td style="color: #6b7280;">Subtotal</td>
                <td class="text-right" style="font-weight: 600;">{{ $contribution->formattedAmount() }}</td>
            </tr>
            <tr>
                <td style="color: #6b7280;">Processing / Taxes</td>
                <td class="text-right" style="font-weight: 600;">{{ $contribution->currency }} 0.00</td>
            </tr>
            <tr class="grand-total">
                <td>Total Received</td>
                <td class="text-right" style="color: #4f46e5;">{{ $contribution->formattedAmount() }}</td>
            </tr>
        </table>
    </div>

    @if (! empty($contribution->tribute_message))
        <div class="tribute-box">
            <div class="tribute-title">Attached Tribute / Note</div>
            <div class="tribute-text">
                &ldquo;{{ $contribution->tribute_message }}&rdquo;
            </div>
        </div>
    @endif

    <div class="footer">
        <div>
            This official electronic receipt is issued by {{ config('app.name') }} on behalf of <strong>{{ $tenant?->name }}</strong> for donor records, charitable gift tracking, and accounting verification. No commercial goods or services were provided in exchange for this voluntary contribution.
        </div>

        <table class="issuer-table">
            <tr>
                <td style="font-size: 10px; color: #9ca3af;">
                    Secured by {{ config('app.name') }} Payments &middot; Paystack Settlement Ref: {{ $contribution->paystack_reference ?? $contribution->payment_reference }}
                </td>
                @if ($brandDataUri)
                    <td class="text-right issuer-logo">
                        <img src="{{ $brandDataUri }}" alt="{{ config('app.name') }}">
                    </td>
                @endif
            </tr>
        </table>
    </div>
</div>
</body>
</html>

