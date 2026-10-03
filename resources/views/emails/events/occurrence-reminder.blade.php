<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $event->name }} - Upcoming Occurrence</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f8fafc; color: #1e293b; margin: 0; padding: 24px;">
    <table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
        <tr>
            <td align="center">
                <table width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; padding: 32px;" role="presentation">
                    <tr>
                        <td>
                            <h2 style="margin-top: 0; color: #0f172a; font-size: 20px; font-weight: 700;">{{ $event->name }}</h2>
                            <p style="font-size: 15px; color: #334155; line-height: 1.5;">
                                Hello {{ $registration->full_name ?? 'there' }},
                            </p>
                            <p style="font-size: 15px; color: #334155; line-height: 1.5;">
                                This is a friendly reminder for the upcoming occurrence in this series:
                            </p>

                            <div style="background-color: #f1f5f9; border-left: 4px solid #4f46e5; border-radius: 6px; padding: 16px; margin: 20px 0;">
                                <h3 style="margin: 0 0 8px 0; font-size: 16px; color: #0f172a;">{{ $session->title }}</h3>
                                <p style="margin: 0 0 4px 0; font-size: 14px; color: #475569;">
                                    <strong>When:</strong> {{ $session->starts_at?->timezone($event->timezone ?: 'UTC')->format('l, F j, Y \a\t g:i A') }}
                                </p>
                                @if($session->location || $event->address)
                                    <p style="margin: 0 0 4px 0; font-size: 14px; color: #475569;">
                                        <strong>Location:</strong> {{ $session->location ?: $event->address }}
                                    </p>
                                @endif
                            </div>

                            @if($session->description)
                                <div style="margin: 20px 0;">
                                    <h4 style="margin: 0 0 6px 0; font-size: 14px; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px;">About this Gathering</h4>
                                    <p style="margin: 0; font-size: 14px; color: #334155; line-height: 1.6;">{{ $session->description }}</p>
                                </div>
                            @endif

                            @if($session->notes)
                                <div style="margin: 20px 0; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px;">
                                    <h4 style="margin: 0 0 6px 0; font-size: 13px; text-transform: uppercase; color: #475569; letter-spacing: 0.5px;">Notes &amp; Study Guide</h4>
                                    <p style="margin: 0; font-size: 13px; color: #334155; white-space: pre-wrap; line-height: 1.5;">{{ $session->notes }}</p>
                                </div>
                            @endif

                            @if($session->presentation_url)
                                <div style="margin: 24px 0; text-align: center;">
                                    <a href="{{ $session->presentation_url }}" style="display: inline-block; background-color: #4f46e5; color: #ffffff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; font-size: 14px;">
                                        Download Slides / Study Deck
                                    </a>
                                </div>
                            @endif

                            <p style="margin-top: 24px; font-size: 13px; color: #64748b; line-height: 1.5; border-top: 1px solid #f1f5f9; padding-top: 16px;">
                                You received this reminder because you are registered for <strong>{{ $event->name }}</strong>.
                                <br>
                                Ticket Code: <code>{{ $registration->ticket_code }}</code>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
