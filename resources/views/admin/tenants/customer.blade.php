@extends('layouts.admin.master')

@section('title', 'Customer — ' . $tenant->name)

@php($money = fn (int $pesewas): string => 'GHS '.number_format($pesewas / 100, 2))

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        <div class="d-flex flex-wrap flex-stack mb-6">
            <div>
                <h1 class="fw-bolder mb-1">{{ $tenant->name }}</h1>
                <div class="text-muted fs-7">{{ $tenant->slug }} · {{ $tenant->email }} · joined {{ $tenant->created_at?->format('j M Y') }}</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('tenants.show', $tenant->uuid) }}" class="btn btn-sm btn-light">Usage &amp; team</a>
                <a href="{{ route('tenants.edit', $tenant->uuid) }}" class="btn btn-sm btn-light">Edit</a>
                <a href="{{ route('tenants.addons.index', $tenant->uuid) }}" class="btn btn-sm btn-light">Add-ons &amp; credits</a>
                <a href="{{ route('tenants.agreed-price.show', $tenant->uuid) }}" class="btn btn-sm btn-light">Agreed price</a>
                <a href="{{ route('tenants.sending-domain.show', $tenant->uuid) }}" class="btn btn-sm btn-light">Sending domain</a>
                <a href="{{ route('admin.messages.index', ['q' => $owner?->email ?? $tenant->email]) }}" class="btn btn-sm btn-light">Owner's messages</a>
            </div>
        </div>

        <div class="row g-5">
            <div class="col-lg-4">
                <div class="card h-100"><div class="card-body">
                    <h3 class="fw-bolder mb-5">Account</h3>
                    <div class="fw-bold text-muted fs-8">Status</div>
                    <div class="mb-4">{{ $tenant->status?->label() ?? '—' }}</div>
                    <div class="fw-bold text-muted fs-8">Owner</div>
                    <div class="mb-4">{{ $owner?->displayName() ?? '—' }}<div class="text-muted fs-8">{{ $owner?->email }}</div></div>
                    <div class="fw-bold text-muted fs-8">Team</div>
                    <div class="mb-4">{{ $teamSize }} {{ \Illuminate\Support\Str::plural('member', $teamSize) }}</div>
                    <div class="fw-bold text-muted fs-8">Marketplace business</div>
                    <div>
                        @if ($tenant->shop)
                            {{ $tenant->shop->name }} ({{ $tenant->shop->verification_status }})
                        @else
                            None
                        @endif
                    </div>
                </div></div>
            </div>

            <div class="col-lg-4">
                <div class="card h-100"><div class="card-body">
                    <h3 class="fw-bolder mb-5">Plan and billing</h3>
                    <div class="fw-bold text-muted fs-8">Plan</div>
                    <div class="mb-4">{{ $tenant->package->name ?? 'Free' }}
                        @if ($agreedPrice) <span class="text-muted fs-8">· agreed {{ $money($agreedPrice['amount']) }} a {{ $agreedPrice['interval'] }}</span> @endif
                    </div>
                    <div class="fw-bold text-muted fs-8">Billing</div>
                    <div class="mb-4" data-billing-state>{{ $billing }}
                        @if ($subscription?->current_period_end)
                            <div class="text-muted fs-8">{{ $subscription->isPastDue() ? 'Was due' : 'Renews' }} {{ $subscription->current_period_end->format('j M Y') }}{{ $subscription->authorization_label ? ' · '.$subscription->authorization_label : '' }}</div>
                        @endif
                    </div>
                    <div class="fw-bold text-muted fs-8">Last payment to MiConvener</div>
                    <div class="mb-4">
                        @if ($lastPayment)
                            {{ $money((int) $lastPayment->amount) }} on {{ $lastPayment->created_at->format('j M Y') }}
                        @else
                            None
                        @endif
                    </div>
                    <div class="fw-bold text-muted fs-8">Active add-ons</div>
                    <div>{{ $activeAddons }}</div>
                </div></div>
            </div>

            <div class="col-lg-4">
                <div class="card h-100"><div class="card-body">
                    <h3 class="fw-bolder mb-5">Messaging</h3>
                    <div class="fw-bold text-muted fs-8">Credits left</div>
                    <div class="mb-4">
                        SMS: {{ $smsRemaining === null ? 'unlimited' : number_format($smsRemaining) }} ·
                        Email: {{ $emailRemaining === null ? 'unlimited' : number_format($emailRemaining) }}
                    </div>
                    <div class="fw-bold text-muted fs-8">Last 7 days</div>
                    <div class="mb-4">
                        @forelse ($messages as $line)
                            <div class="{{ $line->status === 'failed' ? 'text-danger' : '' }}">{{ strtoupper($line->channel) }} {{ str_replace('_', ' ', $line->status) }}: {{ $line->total }}</div>
                        @empty
                            <span class="text-muted">Nothing sent</span>
                        @endforelse
                    </div>
                    <div class="fw-bold text-muted fs-8">Sends from</div>
                    <div>
                        @if ($tenant->activeSendingDomain())
                            {{ $tenant->sendingDomain->from_address }} (own domain)
                        @elseif ($tenant->sendingDomain)
                            MiConvener address; own domain {{ $tenant->sendingDomain->domain }} is {{ $tenant->sendingDomain->status }}
                        @else
                            MiConvener address
                        @endif
                    </div>
                </div></div>
            </div>

            <div class="col-12">
                <div class="card"><div class="card-body">
                    <h3 class="fw-bolder mb-5">Events</h3>
                    <div class="d-flex flex-wrap gap-10">
                        <div><div class="fw-bold text-muted fs-8">Events</div><div class="fs-3 fw-bolder">{{ $eventCount }}</div></div>
                        <div><div class="fw-bold text-muted fs-8">Next</div><div>{{ $nextEvent ? $nextEvent->name.' · '.$nextEvent->starts_at?->format('j M Y') : 'None scheduled' }}</div></div>
                        <div><div class="fw-bold text-muted fs-8">Most recent</div><div>{{ $lastEvent ? $lastEvent->name.' · '.$lastEvent->starts_at?->format('j M Y') : 'None yet' }}</div></div>
                    </div>
                </div></div>
            </div>
        </div>
    </div>
</div>
@endsection
