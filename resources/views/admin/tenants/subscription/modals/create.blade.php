<x-modal title="Add Credit" modalId="adminTopupTenantCreditModal" type="lg">

    <form action="{{ route('tenants.subscriptions.admin-add-credit', $subscription->uuid) }}" method="post" id="adminTopupTenantCreditForm">
        @csrf
        <div class="fv-row mb-10">
            <label for="amount" class="required fw-bold fs-6 mb-2">{{ __('locale.labels.amount') }} </label>
            <input 
                type="number" 
                name="amount" 
                id="amount" 
                class="form-control form-control-solid mb-3 mb-lg-0 @error('amount') is-invalid @enderror"  
                value="{{ old('amount') }}" 
                placeholder="Ex: 10" 
            />
        </div>
        <div class="fv-row mb-10">
            <label for="credit" class="required fw-bold fs-6 mb-2">{{ __('locale.labels.credit') }} </label>
            <input 
                type="number" 
                name="credit" 
                id="credit" 
                class="form-control form-control-solid mb-3 mb-lg-0 @error('credit') is-invalid @enderror"  
                value="{{ old('credit') }}" 
                placeholder="Ex: 1000" 
            />
        </div>
        <div class="fv-row mb-7">
            <button type="submit" class="btn btn-primary" id="adminTopupTenantCreditButton">
                {{ __('locale.labels.save_and_continue') }}
            </button>
        </div>

    </form>
</x-modal>