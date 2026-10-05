@extends('layouts.admin.master')

@section('title', 'Sending domain — ' . $tenant->name)

@section('content')
<div class="row g-5">
    <div class="col-lg-8">
        @if (session('success'))
            <div class="alert alert-success p-5 mb-5">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger p-5 mb-5">{{ session('error') }}</div>
        @endif

        <div class="card mb-5">
            <div class="card-header border-0 pt-6">
                <div class="card-title">
                    <h3 class="fw-bolder m-0">Send event mail from {{ $tenant->name }}'s own domain</h3>
                </div>
                <div class="card-toolbar">
                    <a href="{{ route('tenants.show', $tenant->uuid) }}" class="btn btn-sm btn-light">Back to organisation</a>
                </div>
            </div>

            <div class="card-body py-4">
                @unless ($canHaveSendingDomain)
                    <div class="alert alert-warning mb-7">
                        This is an Enterprise feature. Move the organisation to Enterprise before setting it up.
                        @if ($sendingDomain)
                            Their domain below is not used while they are on another plan.
                        @endif
                    </div>
                @endunless

                @if ($sendingDomain)
                    <div class="row mb-7">
                        <label class="col-lg-4 fw-bold text-muted">Status</label>
                        <div class="col-lg-8">
                            @if ($sendingDomain->isVerified())
                                <span class="badge badge-light-success fs-7">Verified — mail comes from {{ $sendingDomain->from_address }}</span>
                            @elseif ($sendingDomain->status === \App\Models\TenantSendingDomain::STATUS_FAILED)
                                <span class="badge badge-light-danger fs-7">Failed — records missing or wrong; mail comes from MiConvener</span>
                            @else
                                <span class="badge badge-light-warning fs-7">Waiting for DNS records; mail comes from MiConvener</span>
                            @endif
                            @if ($sendingDomain->last_checked_at)
                                <div class="fs-8 text-muted mt-1">Last checked {{ $sendingDomain->last_checked_at->diffForHumans() }}. Checked automatically every 15 minutes.</div>
                            @endif
                        </div>
                    </div>

                    <div class="mb-7">
                        <h4 class="fw-bolder mb-3">DNS records for the organiser</h4>
                        <p class="text-muted fs-7">
                            Ask the organiser to add these three CNAME records at their DNS provider for
                            <strong>{{ $sendingDomain->domain }}</strong>. Nothing else is needed. Changes usually show within an hour, sometimes up to 72.
                        </p>
                        <div class="table-responsive">
                            <table class="table table-row-bordered align-middle fs-7">
                                <thead>
                                    <tr class="fw-bold text-muted"><th>Type</th><th>Name</th><th>Value</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($sendingDomain->dkim_records ?? [] as $record)
                                        <tr>
                                            <td>{{ $record['type'] }}</td>
                                            <td><code class="user-select-all">{{ $record['name'] }}</code></td>
                                            <td><code class="user-select-all">{{ $record['value'] }}</code></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="d-flex gap-3">
                        <form method="POST" action="{{ route('tenants.sending-domain.check', $tenant->uuid) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-primary">Check now</button>
                        </form>
                        <form method="POST" action="{{ route('tenants.sending-domain.destroy', $tenant->uuid) }}"
                              onsubmit="return confirm('Stop sending from {{ $sendingDomain->domain }} and remove it from Amazon SES?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-light-danger">Remove domain</button>
                        </form>
                    </div>

                    <div class="separator my-7"></div>
                @endif

                @if ($canHaveSendingDomain)
                    <h4 class="fw-bolder mb-3">{{ $sendingDomain ? 'Change domain or address' : 'Set up a domain' }}</h4>
                    <form method="POST" action="{{ route('tenants.sending-domain.store', $tenant->uuid) }}">
                        @csrf
                        <div class="row mb-5">
                            <label class="col-lg-4 fw-bold text-muted" for="domain">Domain</label>
                            <div class="col-lg-8">
                                <input id="domain" name="domain" class="form-control form-control-solid" placeholder="events.example.com"
                                       value="{{ old('domain', $sendingDomain?->domain) }}" required>
                                <div class="fs-8 text-muted mt-1">A subdomain such as events.example.com keeps event mail apart from the organiser's everyday mail.</div>
                                @error('domain') <div class="text-danger fs-7 mt-1">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <div class="row mb-5">
                            <label class="col-lg-4 fw-bold text-muted" for="from_address">From address</label>
                            <div class="col-lg-8">
                                <input id="from_address" name="from_address" type="email" class="form-control form-control-solid" placeholder="hello@events.example.com"
                                       value="{{ old('from_address', $sendingDomain?->from_address) }}" required>
                                <div class="fs-8 text-muted mt-1">Replies still go to the event's contact email, or the organisation's.</div>
                                @error('from_address') <div class="text-danger fs-7 mt-1">{{ $message }}</div> @enderror
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">{{ $sendingDomain ? 'Save' : 'Register with Amazon SES' }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
