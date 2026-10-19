<?php

use App\Models\Package;
use App\Services\Pricing\Money;
use App\Services\Pricing\PriceBook;
use App\Services\Pricing\VisitorCurrency;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    public string $interval = 'month';

    #[Locked]
    public string $currency = 'GHS';

    public function mount(VisitorCurrency $visitorCurrency): void
    {
        $this->currency = $visitorCurrency->resolve(request());
    }

    /**
     * Seeded plans by slug: their prices are the ones checkout charges.
     *
     * @return \Illuminate\Support\Collection<string, Package>
     */
    #[Computed]
    public function packages(): \Illuminate\Support\Collection
    {
        return Package::query()->whereIn('slug', collect(config('product-page.plans', []))->pluck('slug'))->get()->keyBy('slug');
    }

    /**
     * A plan's price in the visitor's currency, from the price book when the
     * plan is seeded and from the page copy otherwise.
     *
     * @param  array<string, mixed>  $plan
     */
    public function price(array $plan, string $interval): Money
    {
        $package = $this->packages->get($plan['slug']);

        if ($package !== null) {
            return app(PriceBook::class)->plan($package, $interval, $this->currency);
        }

        return new Money((int) round((float) $plan[$interval === 'year' ? 'yearly_price' : 'monthly_price'] * 100), 'GHS');
    }

    /** A yearly price spread over twelve months, to the whole unit. */
    public function perMonth(Money $yearly): Money
    {
        return new Money((int) round($yearly->minor / 1200) * 100, $yearly->currency);
    }

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
                    $monthly    = $isFree || $priceLabel ? null : $this->price($plan, 'month');
                    $yearly     = $isFree || $priceLabel ? null : $this->price($plan, 'year');
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
                                    {{ ($interval === 'year' ? $this->perMonth($yearly) : $monthly)->format() }}
                                </div>
                                <div class="mb-1.5 text-sm {{ $isPopular ? 'text-blue-200' : 'text-slate-400' }}">/mo</div>
                            </div>
                            @if ($interval === 'year')
                                <div class="mt-1 text-xs font-medium {{ $isPopular ? 'text-blue-100' : 'text-emerald-600' }}">
                                    {{ $yearly->format() }} billed annually
                                </div>
                            @else
                                <div class="mt-1 text-xs {{ $isPopular ? 'text-blue-200/70' : 'text-slate-400' }}">
                                    {{ $this->perMonth($yearly)->format() }}/mo with annual billing
                                </div>
                            @endif
                            @if ($monthly->currency === 'USD')
                                <div class="mt-1 text-[11px] {{ $isPopular ? 'text-blue-200/70' : 'text-slate-400' }}">
                                    Billed in GHS at checkout
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

        {{-- Currency switch: a Ghanaian abroad, or a visitor on a VPN, can choose --}}
        <form method="POST" action="{{ route('currency.update') }}" class="mt-10 flex items-center justify-center gap-2 text-sm text-slate-500" data-currency-switch>
            @csrf
            <span>Prices in</span>
            @foreach (['GHS', 'USD'] as $option)
                <button type="submit" name="currency" value="{{ $option }}"
                    @if ($currency === $option) aria-current="true" @endif
                    class="rounded-full px-3 py-1 font-medium transition-colors {{ $currency === $option ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                    {{ $option }}
                </button>
            @endforeach
        </form>
        @if ($currency === 'USD')
            <p class="mt-2 text-center text-xs text-slate-400">
                Payments are taken in Ghana cedis (GHS) at the Bank of Ghana rate on the day you pay; your bank converts it to your card's currency.
            </p>
        @endif

        <p class="mt-4 text-center text-xs text-slate-400" data-commission-note>
            Commission is charged on ticket sales only. It does not include card and mobile money processing fees, which the payment provider charges and are passed on at cost.
        </p>

        {{-- Enterprise footer note --}}
        <div class="mt-6 text-center">
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
