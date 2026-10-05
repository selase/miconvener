@extends('layouts.admin.master')

@section('title', 'Add-ons — ' . $tenant->name)

@section('content')
<div class="row g-5">
    <div class="col-12">
        @if (session('success'))
            <div class="alert alert-success p-5 mb-5">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-danger p-5 mb-5">{{ session('error') }}</div>
        @endif
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header border-0 pt-6">
                <h3 class="card-title fw-bolder">Grant without charge</h3>
            </div>
            <div class="card-body py-4">
                <p class="text-muted fs-7">
                    Works exactly like a bought add-on, but no payment is recorded, so it does not count as earnings.
                    Who granted it and why are kept on the add-on and in the activity log.
                </p>
                <form method="POST" action="{{ route('tenants.addons.store', $tenant->uuid) }}">
                    @csrf
                    <div class="mb-5">
                        <label class="form-label fw-bold" for="addon_key">Add-on</label>
                        <select id="addon_key" name="addon_key" class="form-select form-select-solid">
                            @foreach ($catalog as $key => $item)
                                @php($isPromotion = in_array($item['addon_type'], \App\Models\TenantAddon::SHOP_TYPES, true))
                                <option value="{{ $key }}" @selected(old('addon_key') === $key) @disabled($isPromotion && ! $hasShop)>
                                    {{ $item['name'] }} (GHS {{ number_format($item['unit_price'] / 100, 2) }} value){{ $isPromotion && ! $hasShop ? ' — no marketplace business' : '' }}
                                </option>
                            @endforeach
                        </select>
                        @error('addon_key') <div class="text-danger fs-7 mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-5">
                        <label class="form-label fw-bold" for="packs">How many</label>
                        <input id="packs" name="packs" type="number" min="1" max="50" value="{{ old('packs', 1) }}" class="form-control form-control-solid">
                        <div class="fs-8 text-muted mt-1">For a pack, 2 means two packs (e.g. 1,000 SMS from the 500 pack).</div>
                        @error('packs') <div class="text-danger fs-7 mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-5">
                        <label class="form-label fw-bold" for="reason">Why</label>
                        <textarea id="reason" name="reason" rows="3" class="form-control form-control-solid" placeholder="e.g. Goodwill after the 3 Oct SMS outage">{{ old('reason') }}</textarea>
                        @error('reason') <div class="text-danger fs-7 mt-1">{{ $message }}</div> @enderror
                    </div>
                    <button type="submit" class="btn btn-primary">Grant</button>
                    <a href="{{ route('tenants.show', $tenant->uuid) }}" class="btn btn-light ms-2">Back to organisation</a>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header border-0 pt-6">
                <h3 class="card-title fw-bolder">{{ $tenant->name }}'s add-ons</h3>
            </div>
            <div class="card-body py-4">
                <table class="table align-middle table-row-dashed fs-7 gy-4">
                    <thead>
                        <tr class="text-muted fw-bolder text-uppercase">
                            <th>Add-on</th><th class="text-end">Qty</th><th>Period</th><th>How</th><th></th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-700 fw-bold">
                        @forelse ($addons as $addon)
                            @php($granted = isset($addon->meta['granted_by']))
                            <tr>
                                <td>
                                    {{ $addon->name }}
                                    <div class="text-muted fs-8">{{ ucfirst($addon->status) }}</div>
                                </td>
                                <td class="text-end">{{ number_format($addon->quantity) }}</td>
                                <td>
                                    {{ $addon->period_start?->format('j M Y') ?? '—' }}
                                    @if ($addon->period_end) to {{ $addon->period_end->format('j M Y') }} @else (until used) @endif
                                </td>
                                <td>
                                    @if ($granted)
                                        <span class="badge badge-light-info">Granted</span>
                                        <div class="text-muted fs-8">{{ $addon->meta['granted_by_name'] ?? '' }}: {{ $addon->meta['grant_reason'] ?? '' }}</div>
                                    @else
                                        <span class="badge badge-light-success">Paid</span>
                                        <div class="text-muted fs-8">GHS {{ number_format($addon->total_price / 100, 2) }}</div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($granted && $addon->status === \App\Models\TenantAddon::STATUS_ACTIVE)
                                        <form method="POST" action="{{ route('tenants.addons.destroy', [$tenant->uuid, $addon->id]) }}"
                                              onsubmit="return confirm('Withdraw this granted add-on now?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-light-danger">Withdraw</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-10">No add-ons yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
