<?php

use Livewire\Component;

new class extends Component
{
    public string $interval = 'month';

    public function setInterval(string $interval): void
    {
        $this->interval = in_array($interval, ['month', 'year']) ? $interval : 'month';
    }
};

?>

<section id="pricing" class="py-20 md:py-28 bg-white">
    <div class="mx-auto max-w-7xl px-6">

        {{-- Section header --}}
        <div class="text-center mb-12">
            <div class="inline-flex items-center rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-medium text-blue-600 mb-5">
                Pricing
            </div>
            <h2 class="section-heading text-[38px] leading-tight font-bold tracking-tight text-slate-900 md:text-[48px]">
                Simple, transparent pricing
            </h2>
            <p class="mt-3 text-lg text-slate-500">Start free. Upgrade when your team needs more.</p>

            {{-- Billing interval toggle --}}
            <div class="mt-8 inline-flex items-center rounded-full border border-slate-200 bg-slate-100 p-1">
                <button
                    wire:click="setInterval('month')"
                    class="rounded-full px-5 py-2 text-sm font-medium transition-all {{ $interval === 'month' ? 'bg-white text-slate-900 shadow-sm border border-slate-200' : 'text-slate-500 hover:text-slate-700' }}">
                    Monthly
                </button>
                <button
                    wire:click="setInterval('year')"
                    class="rounded-full px-5 py-2 text-sm font-medium transition-all {{ $interval === 'year' ? 'bg-white text-slate-900 shadow-sm border border-slate-200' : 'text-slate-500 hover:text-slate-700' }}">
                    Annual
                    <span class="ml-1.5 text-[11px] font-semibold text-emerald-600">–17%</span>
                </button>
            </div>
        </div>

        {{-- Plans grid --}}
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            @foreach (config('product-page.plans', []) as $plan)
                @php
                    $isPopular  = $plan['most_popular'] ?? false;
                    $isFree     = $plan['monthly_price'] === 0;
                    $priceLabel = $plan['price_label'] ?? null;
                @endphp

                <div class="relative flex flex-col rounded-2xl border p-6 transition-all
                    {{ $isPopular
                        ? 'border-blue-400 bg-gradient-to-b from-blue-600 to-blue-700 shadow-xl shadow-blue-600/25'
                        : 'border-slate-200 bg-white hover:border-slate-300 hover:shadow-md shadow-sm' }}">

                    @if ($isPopular)
                        <div class="absolute -top-3.5 left-0 right-0 flex justify-center">
                            <span class="rounded-full bg-blue-500 px-4 py-1 text-xs font-semibold text-white shadow-md tracking-wide ring-2 ring-white">
                                Most Popular
                            </span>
                        </div>
                    @endif

                    {{-- Plan name & description --}}
                    <div class="mb-5 pt-1">
                        <div class="text-base font-semibold {{ $isPopular ? 'text-white' : 'text-slate-900' }}">{{ $plan['name'] }}</div>
                        <div class="mt-1.5 text-[13px] leading-relaxed {{ $isPopular ? 'text-blue-100' : 'text-slate-400' }}">{{ $plan['description'] }}</div>
                    </div>

                    {{-- Price --}}
                    <div class="mb-6">
                        @if ($priceLabel)
                            <div class="pricing-heading text-4xl font-bold {{ $isPopular ? 'text-white' : 'text-slate-900' }}">{{ $priceLabel }}</div>
                            <div class="mt-1 text-xs {{ $isPopular ? 'text-blue-200' : 'text-slate-400' }}">{{ $plan['price_note'] ?? '' }}</div>
                        @elseif ($isFree)
                            <div class="pricing-heading text-4xl font-bold {{ $isPopular ? 'text-white' : 'text-slate-900' }}">Free</div>
                            <div class="mt-1 text-xs {{ $isPopular ? 'text-blue-200' : 'text-slate-400' }}">No credit card required</div>
                        @else
                            <div class="flex items-end gap-1">
                                <div class="pricing-heading text-4xl font-bold {{ $isPopular ? 'text-white' : 'text-slate-900' }}">
                                    ${{ $interval === 'year' ? (int) round($plan['yearly_price'] / 12) : $plan['monthly_price'] }}
                                </div>
                                <div class="mb-1.5 text-sm {{ $isPopular ? 'text-blue-200' : 'text-slate-400' }}">/mo</div>
                            </div>
                            @if ($interval === 'year')
                                <div class="mt-1 text-xs font-medium {{ $isPopular ? 'text-blue-100' : 'text-emerald-600' }}">
                                    ${{ $plan['yearly_price'] }} billed annually
                                </div>
                            @else
                                <div class="mt-1 text-xs {{ $isPopular ? 'text-blue-200/70' : 'text-slate-400' }}">
                                    ${{ (int) round($plan['yearly_price'] / 12) }}/mo with annual billing
                                </div>
                            @endif
                        @endif
                    </div>

                    {{-- CTA button --}}
                    <a href="{{ $plan['cta']['href'] }}"
                        class="mb-6 block rounded-xl py-2.5 text-center text-sm font-semibold transition-all
                        {{ $isPopular
                            ? 'bg-white text-blue-700 hover:bg-blue-50 shadow-sm'
                            : 'border border-slate-200 bg-slate-50 text-slate-800 hover:bg-slate-100 hover:border-slate-300' }}">
                        {{ $plan['cta']['label'] }}
                    </a>

                    {{-- Feature list --}}
                    <ul class="flex-1 space-y-2.5">
                        @foreach ($plan['features'] as $feature)
                            <li class="flex items-start gap-2.5">
                                <svg class="mt-0.5 h-4 w-4 shrink-0 {{ $isPopular ? 'text-blue-200' : 'text-emerald-500' }}"
                                    viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd"
                                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                        clip-rule="evenodd" />
                                </svg>
                                <span class="text-[13px] leading-snug {{ $isPopular ? 'text-blue-100' : 'text-slate-600' }}">{{ $feature }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        {{-- Enterprise footer note --}}
        <div class="mt-10 text-center">
            <p class="text-sm text-slate-400">
                Need a custom contract or volume pricing?
                <a href="{{ route('product.enterprise') }}"
                    class="text-blue-600 hover:text-blue-700 underline underline-offset-4 transition-colors font-medium">
                    Talk to our Enterprise team →
                </a>
            </p>
        </div>

    </div>
</section>
