<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Invoice {{ $invoice->number }}</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 14px;
            color: #333;
            line-height: 1.6;
            margin: 0;
            padding: 0;
        }
        .container {
            width: 100%;
            padding: 40px;
        }
        .header {
            margin-bottom: 40px;
        }
        .header table {
            width: 100%;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #009EF7;
        }
        .invoice-title {
            text-align: right;
            font-size: 32px;
            font-weight: bold;
            text-transform: uppercase;
            color: #181c32;
        }
        .info-section {
            width: 100%;
            margin-bottom: 40px;
        }
        .info-section table {
            width: 100%;
        }
        .info-label {
            font-size: 10px;
            font-weight: bold;
            color: #a1a5b7;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        .info-content {
            font-size: 14px;
            font-weight: bold;
            color: #3f4254;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 40px;
        }
        .items-table th {
            background-color: #f5f8fa;
            text-align: left;
            padding: 10px;
            font-size: 12px;
            text-transform: uppercase;
            color: #7e8299;
            border-bottom: 1px solid #eff2f5;
        }
        .items-table td {
            padding: 15px 10px;
            border-bottom: 1px solid #eff2f5;
            vertical-align: top;
        }
        .item-description {
            font-weight: bold;
            color: #3f4254;
        }
        .item-meta {
            font-size: 11px;
            color: #b5b5c3;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .totals-section {
            width: 100%;
        }
        .totals-table {
            width: 300px;
            float: right;
        }
        .totals-table td {
            padding: 5px 0;
        }
        .total-row td {
            border-top: 1px solid #eff2f5;
            padding-top: 15px;
            font-weight: bold;
            font-size: 18px;
            color: #181c32;
        }
        .footer {
            margin-top: 100px;
            text-align: center;
            font-size: 12px;
            color: #a1a5b7;
        }
        .clearfix::after {
            content: "";
            clear: both;
            display: table;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <table>
                <tr>
                    <td class="logo">
                        {{ config('app.name') }}
                    </td>
                    <td class="invoice-title">
                        INVOICE
                    </td>
                </tr>
            </table>
        </div>

        <div class="info-section">
            <table>
                <tr>
                    <td width="50%">
                        <div class="info-label">Issued By</div>
                        <div class="info-content">
                            {{ config('app.name') }} HQ<br>
                            123 Business Avenue<br>
                            Accra, Ghana
                        </div>
                    </td>
                    <td width="30%">
                        <div class="info-label">Billed To</div>
                        <div class="info-content">
                            {{ $invoice->tenant->name }}<br>
                            {{ $invoice->tenant->email }}
                        </div>
                    </td>
                    <td width="20%" class="text-right">
                        <div class="info-label">Invoice Number</div>
                        <div class="info-content">#{{ $invoice->number }}</div>
                        <div class="info-label" style="margin-top: 10px">Issue Date</div>
                        <div class="info-content">{{ $invoice->created_at->format('M d, Y') }}</div>
                    </td>
                </tr>
            </table>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th class="text-center" width="80">Qty</th>
                    <th class="text-right" width="100">Rate</th>
                    <th class="text-right" width="120">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->items as $item)
                    <tr>
                        <td>
                            <div class="item-description">{{ $item->description }}</div>
                            @if($item->metric)
                                <div class="item-meta">Metered Usage: {{ $item->metric->name }}</div>
                            @endif
                        </td>
                        <td class="text-center">{{ number_format((float)$item->quantity, 2) }}</td>
                        <td class="text-right">${{ number_format((float)$item->unit_price, 4) }}</td>
                        <td class="text-right">${{ number_format((float)$item->subtotal, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals-section clearfix">
            <table class="totals-table">
                <tr>
                    <td class="text-muted">Subtotal</td>
                    <td class="text-right">${{ number_format((float)$invoice->subtotal, 2) }}</td>
                </tr>
                @if($invoice->tax_details)
                    @foreach($invoice->tax_details as $tax)
                        <tr>
                            <td class="text-muted">{{ $tax['name'] }} ({{ $tax['rate'] }}%)</td>
                            <td class="text-right">${{ number_format((float)$tax['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                @endif
                <tr class="total-row">
                    <td>Total Due</td>
                    <td class="text-right">${{ number_format((float)$invoice->total, 2) }}</td>
                </tr>
            </table>
        </div>

        <div class="footer">
            Thank you for your business! If you have any questions about this invoice, please reach out to our support team.
        </div>
    </div>
</body>
</html>
