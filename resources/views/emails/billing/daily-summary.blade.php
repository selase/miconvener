<x-mail::message>
# Billing today

@if ($summary['renewal_run']['stale'])
**The daily renewal run has not run since {{ $summary['renewal_run']['at'] }}.** Plans are not being renewed, reminded or ended until the scheduler is running again.
@elseif ($summary['renewal_run']['at'] === null)
The renewal run has not run yet.
@endif

@if ($summary['failed_jobs'] > 0 || $summary['webhook_failures']['processing'] > 0)
## Failures in the last 24 hours

@if ($summary['failed_jobs'] > 0)
- **{{ $summary['failed_jobs'] }}** queued job(s) failed. Emails such as receipts and reminders may not have been sent.
@endif
@if ($summary['webhook_failures']['processing'] > 0)
- **{{ $summary['webhook_failures']['processing'] }}** Paystack webhook(s) failed to process. A payment may not have been recorded.
@endif
@endif
@if ($summary['webhook_failures']['signature'] > 0)
- {{ $summary['webhook_failures']['signature'] }} Paystack webhook(s) were refused for a bad signature.
@endif

@if ($summary['past_due'] !== [])
## Overdue

@foreach ($summary['past_due'] as $row)
- **{{ $row['tenant'] }}**, {{ $row['plan'] }}: moves to Free on {{ $row['grace_ends'] ?? 'unknown' }} ({{ $row['method'] }})
@endforeach
@endif

@if ($summary['renewal_run']['actions'] !== [])
## This morning's renewal run

@foreach ($summary['renewal_run']['actions'] as $action)
- {{ $action }}
@endforeach
@endif

@if ($summary['renewing_soon'] !== [])
## Renewing in the next 7 days

@foreach ($summary['renewing_soon'] as $row)
- **{{ $row['tenant'] }}**, {{ $row['plan'] }}: {{ $row['amount'] }} on {{ $row['on'] }} ({{ $row['method'] }})
@endforeach
@endif

## Payments in the last 24 hours

@if ($summary['payments']['count'] > 0)
{{ $summary['payments']['count'] }} payment(s): {{ implode(', ', $summary['payments']['totals']) }}
@else
None.
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
