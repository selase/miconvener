@extends('layouts.admin.master')

@section('title', 'Commission destinations')

@section('content')
<div class="post d-flex flex-column-fluid" id="kt_post">
    <div id="kt_content_container" class="container-xxl">
        @if (session('success'))
            <div class="alert alert-success p-5 mb-5">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger p-5 mb-5">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger p-5 mb-5">{{ $errors->first() }}</div>
        @endif

        <div class="card mb-6">
            <div class="card-header border-0 pt-6">
                <div class="card-title flex-column">
                    <h3 class="fw-bolder mb-1">Commission destinations</h3>
                    <div class="text-muted fs-7">
                        Where our commission is paid when an organiser takes payments on their own Paystack account. Each organiser creates a Paystack subaccount that settles to one of these accounts and we check it before every payment.
                        <strong>{{ $organisersOnSplit }}</strong> organiser(s) have a verified commission subaccount now.
                    </div>
                    @if ($usingEnvironment)
                        <div class="text-warning fs-7 mt-2">No account is saved here yet, so the one in the server settings is in use. Add it below to manage it from this page; once you do, the server setting is ignored.</div>
                    @endif
                </div>
            </div>
            <div class="card-body py-4">
                <table class="table align-middle table-row-dashed fs-7 gy-4">
                    <thead>
                        <tr class="text-muted fw-bolder text-uppercase">
                            <th>Label</th><th>Currency</th><th>Bank</th><th>Account</th><th>Status</th><th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-700 fw-bold">
                        @forelse ($destinations as $destination)
                            <tr>
                                <td>{{ $destination->label }}<div class="text-muted fs-8">{{ $destination->business_name }}</div></td>
                                <td>{{ $destination->currency }}</td>
                                <td>{{ $destination->settlement_bank ?? '—' }}</td>
                                <td class="text-nowrap">{{ $destination->account_number }}</td>
                                <td>
                                    @if ($destination->isRetiring())
                                        <span class="badge badge-light-warning">Retiring</span>
                                        <div class="text-muted fs-8">Accepted until {{ $destination->accepted_until->format('j M Y') }}</div>
                                    @elseif ($destination->is_active && $destination->accepted_until === null)
                                        <span class="badge badge-light-success">Current</span>
                                    @else
                                        <span class="badge badge-light-secondary">Retired</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($destination->is_active && $destination->accepted_until === null)
                                        <form method="POST" action="{{ route('admin.billing.commission-destinations.retire', $destination->id) }}" class="d-flex gap-2 justify-content-end">
                                            @csrf
                                            <input type="number" name="days" min="1" max="365" value="{{ $retireDays }}" class="form-control form-control-sm form-control-solid w-75px" aria-label="Days to keep accepting {{ $destination->label }}">
                                            <button type="submit" class="btn btn-sm btn-light-warning" onclick="return confirm('Retire {{ e($destination->label) }}? Organisers pointing at it keep working for these days, then are flagged to move.')">Retire</button>
                                        </form>
                                    @else
                                        <div class="d-flex gap-2 justify-content-end">
                                            <form method="POST" action="{{ route('admin.billing.commission-destinations.restore', $destination->id) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-light">Make current</button>
                                            </form>
                                            @unless ($destination->isRetiring())
                                                <form method="POST" action="{{ route('admin.billing.commission-destinations.destroy', $destination->id) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-light-danger" onclick="return confirm('Remove {{ e($destination->label) }}?')">Remove</button>
                                                </form>
                                            @endunless
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted text-center py-10">No account saved yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-header border-0 pt-6"><div class="card-title"><h3 class="fw-bolder">Add an account</h3></div></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.billing.commission-destinations.store') }}" class="row g-4">
                    @csrf
                    <div class="col-md-3"><label class="form-label fs-7" for="label">Label</label><input id="label" name="label" class="form-control form-control-solid" placeholder="MTN MoMo" value="{{ old('label') }}" required></div>
                    <div class="col-md-2"><label class="form-label fs-7" for="currency">Currency</label><input id="currency" name="currency" class="form-control form-control-solid" placeholder="GHS" maxlength="3" value="{{ old('currency', 'GHS') }}" required></div>
                    <div class="col-md-3"><label class="form-label fs-7" for="business_name">Business name</label><input id="business_name" name="business_name" class="form-control form-control-solid" value="{{ old('business_name', config('services.commission_split.business_name')) }}" required></div>
                    <div class="col-md-2"><label class="form-label fs-7" for="settlement_bank">Bank <span class="text-muted">(as Paystack names it)</span></label><input id="settlement_bank" name="settlement_bank" class="form-control form-control-solid" placeholder="MTN or Ecobank Ghana Limited" value="{{ old('settlement_bank') }}" aria-describedby="settlement_bank_help"><div id="settlement_bank_help" class="text-muted fs-8 mt-1">Paystack reports mobile money by its code (MTN, VOD, ATL) but a bank by its full name, not its numeric code. We compare exactly, so copy it as Paystack shows it.</div></div>
                    <div class="col-md-2"><label class="form-label fs-7" for="account_number">Account number</label><input id="account_number" name="account_number" class="form-control form-control-solid" inputmode="numeric" value="{{ old('account_number') }}" required></div>
                    <div class="col-12 d-flex justify-content-end"><button type="submit" class="btn btn-primary">Add account</button></div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
