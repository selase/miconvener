@extends('layouts.auth')

@section('page-title', 'Confirm subscription')

@section('content')

<div>
    {{-- Welcome header --}}
    <div class="mb-8">
        <div class="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700 mb-4">
            <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/>
            </svg>
            Account created
        </div>
        <h2 class="brand-font text-[24px] font-bold text-slate-900 tracking-tight">One last step</h2>
        <p class="mt-2 text-[14px] text-slate-500">
            Complete your payment to activate your <span class="font-semibold text-slate-700">{{ $package->name }}</span> subscription.
        </p>
    </div>

    {{-- Plan summary card --}}
    <div class="rounded-2xl border-2 border-blue-200 bg-blue-50/50 p-5 mb-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="text-[16px] font-bold text-slate-900">{{ $package->name }} Plan</div>
                <div class="mt-1 text-[13px] text-slate-500">{{ $package->description ?? 'Billed ' . $interval . 'ly' }}</div>
            </div>
            <div class="text-right flex-none">
                @if ($interval === 'year')
                    <div class="brand-font text-[22px] font-bold text-slate-900">${{ $package->yearly_price }}</div>
                    <div class="text-[11px] text-slate-400">per year</div>
                    <div class="text-[11px] text-emerald-600 font-semibold mt-0.5">Save 17%</div>
                @else
                    <div class="brand-font text-[22px] font-bold text-slate-900">${{ $package->price }}</div>
                    <div class="text-[11px] text-slate-400">per month</div>
                @endif
            </div>
        </div>
    </div>

    {{-- Billing interval toggle --}}
    <div class="mb-6">
        <div class="text-xs font-medium text-slate-500 mb-2">Billing cycle</div>
        <div class="flex gap-2">
            <a href="{{ route('billing.confirm', ['plan' => $package->slug, 'interval' => 'month']) }}"
                class="flex-1 rounded-xl border py-2.5 text-center text-sm font-medium transition-all
                    {{ $interval === 'month' ? 'border-blue-400 bg-blue-600 text-white shadow-sm' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300' }}">
                Monthly
            </a>
            <a href="{{ route('billing.confirm', ['plan' => $package->slug, 'interval' => 'year']) }}"
                class="flex-1 rounded-xl border py-2.5 text-center text-sm font-medium transition-all
                    {{ $interval === 'year' ? 'border-blue-400 bg-blue-600 text-white shadow-sm' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300' }}">
                Annual <span class="text-[10px] font-semibold {{ $interval === 'year' ? 'text-blue-200' : 'text-emerald-600' }} ml-1">–17%</span>
            </a>
        </div>
    </div>

    {{-- Pay button (POST to billing.checkout) --}}
    <form method="POST" action="{{ route('billing.checkout') }}">
        @csrf
        <input type="hidden" name="plan" value="{{ $package->slug }}">
        <input type="hidden" name="interval" value="{{ $interval }}">

        <button type="submit"
            class="w-full rounded-xl bg-blue-600 px-4 py-3.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500 transition-all hover:shadow-md hover:shadow-blue-500/25 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
            Pay with Paystack →
        </button>
    </form>

    <p class="mt-5 text-center text-[12px] text-slate-400">
        Secured by Paystack · Cancel anytime · No hidden fees
    </p>

    <div class="mt-6 border-t border-slate-100 pt-6">
        <a href="{{ route('register') }}" class="text-sm text-slate-500 hover:text-slate-700 flex items-center gap-1.5 justify-center">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Change plan
        </a>
    </div>
</div>

@endsection
