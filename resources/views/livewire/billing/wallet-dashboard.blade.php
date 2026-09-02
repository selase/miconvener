<div>
    @if(session('message'))
        <div class="alert alert-success d-flex align-items-center p-5 mb-7">
            <i class="fas fa-check-circle text-success me-4 fs-2"></i>
            <span class="fw-bold">{{ session('message') }}</span>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger d-flex align-items-center p-5 mb-7">
            <i class="fas fa-exclamation-circle text-danger me-4 fs-2"></i>
            <span class="fw-bold">{{ session('error') }}</span>
        </div>
    @endif

    <div class="row g-5 g-xl-8 mb-5">
        {{-- Balance Card --}}
        <div class="col-xl-4">
            <div class="card card-flush shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fas fa-wallet me-3 fs-2 text-primary"></i>
                        <span class="text-gray-400 fw-bold fs-6">Wallet Balance</span>
                    </div>
                    <div class="fs-2x fw-boldest {{ $isLowBalance ? 'text-warning' : 'text-dark' }}">
                        {{ number_format($balance) }}
                        <span class="fs-6 fw-bold text-muted">credits</span>
                    </div>
                    @if($isLowBalance)
                        <div class="text-warning fs-7 mt-1">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            Balance is below the warning threshold
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Top-Up Packs --}}
        @foreach($topupPacks as $key => $pack)
            <div class="col-xl {{ $loop->count > 3 ? 'col-md-4' : '' }}">
                <div class="card card-flush shadow-sm border {{ $selectedPack === $key ? 'border-primary' : 'border-transparent' }} cursor-pointer"
                     wire:click="$set('selectedPack', '{{ $key }}')">
                    <div class="card-body text-center py-6">
                        <h5 class="fw-boldest mb-1">{{ $pack['name'] }}</h5>
                        <div class="fs-2 fw-boldest text-primary mb-1">{{ number_format($pack['credits']) }}</div>
                        <div class="text-muted fs-7 mb-3">credits</div>
                        <div class="fw-bold fs-5">${{ number_format($pack['price'], 2) }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if($selectedPack)
        <div class="d-flex justify-content-end mb-5">
            <form method="POST" action="{{ route('billing.wallet-checkout') }}">
                @csrf
                <input type="hidden" name="pack" value="{{ $selectedPack }}">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-credit-card me-2"></i>
                    Purchase {{ $topupPacks[$selectedPack]['name'] ?? '' }} Pack
                </button>
            </form>
        </div>
    @endif

    {{-- Transaction History --}}
    <div class="card card-flush shadow-sm">
        <div class="card-header border-0 pt-6">
            <h4 class="card-title fw-boldest">Transaction History</h4>
        </div>
        <div class="card-body pt-0">
            @if(method_exists($transactions, 'count') && $transactions->count() > 0)
                <div class="table-responsive">
                    <table class="table table-row-dashed table-row-gray-200 align-middle gs-0 gy-4">
                        <thead>
                            <tr class="fw-boldest text-muted">
                                <th>Date</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($transactions as $tx)
                                <tr>
                                    <td class="text-muted fs-7">{{ $tx->created_at->format('M d, Y H:i') }}</td>
                                    <td>
                                        @php
                                            $typeBadge = match($tx->type->value) {
                                                'deposit' => 'badge-light-success',
                                                'deduction' => 'badge-light-danger',
                                                'refund' => 'badge-light-info',
                                                default => 'badge-light',
                                            };
                                        @endphp
                                        <span class="badge {{ $typeBadge }}">{{ ucfirst($tx->type->value) }}</span>
                                    </td>
                                    <td class="fs-7">{{ $tx->description ?? '-' }}</td>
                                    <td class="text-end fw-bold {{ $tx->type->value === 'deduction' ? 'text-danger' : 'text-success' }}">
                                        {{ $tx->type->value === 'deduction' ? '-' : '+' }}{{ number_format($tx->amount) }}
                                    </td>
                                    <td class="text-end text-muted">{{ number_format($tx->balance_after) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if(method_exists($transactions, 'links'))
                    <div class="mt-4">
                        {{ $transactions->links() }}
                    </div>
                @endif
            @else
                <div class="text-center text-muted py-10">
                    <i class="fas fa-receipt fs-2 mb-3 d-block"></i>
                    No transactions yet. Top up your wallet to get started.
                </div>
            @endif
        </div>
    </div>
</div>
