<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $certificate->recipient_name }} - {{ $template?->title ?? 'Certificate' }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 0;
        }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #1e293b;
            margin: 0;
            padding: 24px;
            background-color: #ffffff;
            -webkit-print-color-adjust: exact;
        }
        .outer-border {
            border: 4px solid #1e3a8a;
            padding: 6px;
            height: 94%;
            box-sizing: border-box;
            background: #ffffff;
        }
        .middle-border {
            border: 1px solid #d97706;
            padding: 6px;
            height: 100%;
            box-sizing: border-box;
        }
        .inner-content {
            border: 1px solid #e2e8f0;
            padding: 30px 48px;
            height: 100%;
            box-sizing: border-box;
            text-align: center;
            position: relative;
            background: radial-gradient(circle at center, #ffffff 0%, #f8fafc 100%);
        }
        .header-logo {
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #1e3a8a;
            margin-bottom: 8px;
        }
        .certificate-category {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: #d97706;
            margin-bottom: 6px;
        }
        .title {
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 16px 0;
            line-height: 1.2;
            letter-spacing: -0.5px;
        }
        .presented-to {
            font-size: 13px;
            font-style: italic;
            color: #64748b;
            margin-bottom: 12px;
        }
        .recipient-name {
            font-size: 32px;
            font-weight: 800;
            color: #1e3a8a;
            margin-bottom: 14px;
            padding-bottom: 6px;
            border-bottom: 2px solid #e2e8f0;
            display: inline-block;
            min-width: 380px;
        }
        .body-text {
            font-size: 14px;
            line-height: 1.65;
            color: #334155;
            max-width: 680px;
            margin: 0 auto 20px auto;
        }
        .cpd-badge {
            display: inline-block;
            background-color: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 14px;
            border-radius: 9999px;
            margin-bottom: 16px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .bottom-section {
            margin-top: 24px;
            width: 100%;
        }
        .bottom-table {
            width: 100%;
            border-collapse: collapse;
        }
        .bottom-table td {
            vertical-align: bottom;
            padding: 0 10px;
        }
        .sign-area {
            text-align: left;
            width: 35%;
        }
        .sign-line {
            width: 200px;
            border-bottom: 1.5px solid #475569;
            margin-bottom: 6px;
        }
        .issuer-name {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
        }
        .issuer-title {
            font-size: 11px;
            color: #64748b;
        }
        .qr-area {
            text-align: right;
            width: 35%;
        }
        .qr-image {
            width: 72px;
            height: 72px;
            margin-bottom: 4px;
        }
        .verify-text {
            font-size: 9px;
            color: #64748b;
            line-height: 1.3;
        }
        .verify-code {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            color: #0f172a;
        }
        .center-seal {
            text-align: center;
            width: 30%;
        }
        .seal-circle {
            width: 60px;
            height: 60px;
            border: 2px dashed #d97706;
            border-radius: 50%;
            margin: 0 auto;
            line-height: 56px;
            font-size: 9px;
            font-weight: 700;
            color: #d97706;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
    </style>
</head>
<body>
    <div class="outer-border">
        <div class="middle-border">
            <div class="inner-content">
                <div class="header-logo">{{ $event->tenant->name ?? 'MICONVENER' }}</div>
                <div class="certificate-category">{{ strtoupper($certificate->role) }} ACCREDITATION</div>
                <h1 class="title">{{ $template?->title ?? 'Certificate of Participation' }}</h1>

                <div class="presented-to">This is to certify that</div>
                <div class="recipient-name">{{ $certificate->recipient_name }}</div>

                <div class="body-text">
                    {{ $bodyText }}
                </div>

                @if($certificate->cpd_hours > 0)
                    <div class="cpd-badge">
                        Accredited for {{ number_format($certificate->cpd_hours, 1) }} Continuing Education (CPD/CME) Contact Hours
                    </div>
                @endif

                <div class="bottom-section">
                    <table class="bottom-table">
                        <tr>
                            <td class="sign-area">
                                <div class="sign-line"></div>
                                <div class="issuer-name">{{ $template?->issuer_name ?? 'Academic Board & Convener' }}</div>
                                <div class="issuer-title">{{ $template?->issuer_title ?? 'Organizing Committee Chair' }}</div>
                            </td>
                            <td class="center-seal">
                                <div class="seal-circle">VERIFIED</div>
                            </td>
                            <td class="qr-area">
                                @if(!empty($qrDataUri))
                                    <img src="{{ $qrDataUri }}" class="qr-image" alt="Verification QR">
                                @endif
                                <div class="verify-text">
                                    Credential ID: <span class="verify-code">{{ $certificate->verification_code }}</span><br>
                                    Issued: {{ $certificate->issued_at?->format('F j, Y') ?? date('F j, Y') }}
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
