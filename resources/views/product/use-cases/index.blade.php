@extends('layouts.product')

@section('title', $hub['title'].' - '.config('product-page.brand.name'))

@section('content')
    {{-- Hero: black band, the words on the left, the seven gatherings around one core on the right. --}}
    <section class="bg-[#08090b] text-white" data-use-case-hub-hero>
        <div class="mx-auto grid max-w-7xl items-center gap-10 px-6 py-12 md:py-16 lg:grid-cols-[.9fr_1.1fr]">
            <div>
                <p class="mono text-[11px] uppercase tracking-[0.18em] text-[#6f9bff]">{{ $hub['eyebrow'] }}</p>
                <h1 class="display mt-4 text-[34px] leading-[40px] tracking-[-0.03em] text-white md:text-[48px] md:leading-[54px]">{{ $hub['title'] }}</h1>
                <p class="mt-5 max-w-xl text-[17px] leading-[27px] text-slate-300">{{ $hub['intro'] }}</p>
            </div>
            <div class="w-full [&>svg]:block [&>svg]:h-auto [&>svg]:w-full">
                @include('product.use-cases.art.hub')
            </div>
        </div>
    </section>

    {{-- Grouped cards, with a filter --}}
    <div x-data="{ show: 'all' }">
        <div class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-7xl items-center gap-6 overflow-x-auto px-6" role="tablist" aria-label="Show">
                <span class="mono hidden shrink-0 border-r border-slate-200 py-3.5 pr-6 text-[11px] uppercase tracking-[0.14em] text-slate-400 md:block">Show</span>
                @php($filters = ['all' => ['All', count($cases)]] + collect($groups)->mapWithKeys(fn ($items, $title) => [Str::slug($title) => [$groupMeta[$title]['filter'], count($items)]])->all())
                @foreach ($filters as $key => [$label, $count])
                    <button type="button" role="tab" x-on:click="show = '{{ $key }}'"
                        x-bind:aria-selected="show === '{{ $key }}' ? 'true' : 'false'"
                        x-bind:class="show === '{{ $key }}' ? 'border-[var(--field)] text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-900'"
                        class="-mb-px shrink-0 whitespace-nowrap border-b-2 py-3.5 text-sm {{ $key === 'all' ? 'border-[var(--field)] text-slate-900' : 'border-transparent text-slate-500' }}">
                        {{ $label }} <span class="mono ml-1 text-[11px] text-slate-400">{{ $count }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        @php($number = 0)
        @foreach ($groups as $groupTitle => $items)
            <section class="pt-16 last:pb-20" x-show="show === 'all' || show === '{{ Str::slug($groupTitle) }}'" data-use-case-group>
                <div class="mx-auto max-w-7xl px-6">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="display text-[24px] text-slate-900">{{ $groupTitle }}</h2>
                        <p class="text-sm text-slate-500">{{ $groupMeta[$groupTitle]['note'] }}</p>
                    </div>
                    <div class="mt-6 grid border-l border-t border-slate-200 sm:grid-cols-2 {{ count($items) >= 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3' }}">
                        @foreach ($items as $item)
                            @php($number++)
                            <a href="{{ route('use-cases.show', $item['slug']) }}" class="group flex flex-col border-b border-r border-slate-200 p-6 transition hover:bg-slate-50">
                                <div class="flex items-center justify-between">
                                    <span class="mono text-xs text-[var(--field)]">{{ str_pad((string) $number, 2, '0', STR_PAD_LEFT) }}</span>
                                    <span class="text-slate-400 transition group-hover:text-[var(--field)]" aria-hidden="true">&nearr;</span>
                                </div>
                                <h3 class="mt-5 text-lg font-semibold text-slate-900">{{ $item['eyebrow'] }}</h3>
                                <p class="mt-2 flex-1 text-[15px] leading-6 text-slate-500">{{ $item['summary'] }}</p>
                                <ul class="mt-6 flex flex-wrap gap-1.5 border-t border-slate-200 pt-4">
                                    @foreach ($item['tags'] as $tag)
                                        <li class="mono rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">{{ $tag }}</li>
                                    @endforeach
                                </ul>
                            </a>
                        @endforeach
                    </div>
                </div>
            </section>
        @endforeach
    </div>

    {{-- One platform underneath --}}
    <section class="border-y border-slate-200 bg-slate-50 py-20 md:py-24">
        <div class="mx-auto grid max-w-7xl gap-12 px-6 lg:grid-cols-[.8fr_1.2fr]">
            <div>
                <p class="mono text-[11px] uppercase tracking-[0.16em] text-slate-400">One platform underneath</p>
                <h2 class="display mt-3 text-[32px] leading-[38px] text-slate-900 md:text-[38px] md:leading-[44px]">Every gathering runs on the same core.</h2>
                <p class="mt-4 text-[15px] leading-6 text-slate-500">Whatever you are hosting, the same tools handle the people, the money and the door. Only the words change to match your event.</p>
                <div class="mt-8 rounded-md border border-slate-200 bg-white">
                    <div class="mono border-b border-slate-200 px-4 py-2.5 text-[10px] uppercase tracking-wider text-slate-400">Your event, your words</div>
                    @foreach ($words as [$event, $word])
                        <div class="flex justify-between gap-4 border-t border-slate-100 px-4 py-2.5 text-sm first-of-type:border-t-0">
                            <span class="text-slate-500">{{ $event }}</span><span class="font-medium text-slate-900">{{ $word }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="grid self-start border-l border-t border-slate-200 bg-white sm:grid-cols-2">
                @foreach ($core as $item)
                    <div class="border-b border-r border-slate-200 p-6">
                        <span class="block h-1.5 w-1.5 bg-[var(--field)]" aria-hidden="true"></span>
                        <h3 class="mt-4 text-[15px] font-semibold text-slate-900">{{ $item['title'] }}</h3>
                        <p class="mt-1.5 text-sm leading-6 text-slate-500">{{ $item['text'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="py-16 md:py-20" data-use-case-cta>
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-8 px-6">
            <div class="max-w-xl">
                <h2 class="display text-[30px] leading-[36px] text-slate-900">Not sure which fits?</h2>
                <p class="mt-3 text-[15px] leading-6 text-slate-500">Most gatherings mix several of these. Start free and shape it as you go, or tell us what you are planning and we will help you set it up.</p>
            </div>
            <div>
                <div class="flex flex-wrap gap-3">
                    <a href="{{ route('register', ['plan' => 'free']) }}" class="rounded-md bg-[var(--field)] px-5 py-3 text-sm font-semibold text-white hover:bg-[var(--field-deep)]">Start free</a>
                    <a href="{{ route('product.enterprise') }}" class="rounded-md border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 hover:bg-slate-50">Talk to us</a>
                </div>
                <p class="mt-2 text-xs text-slate-400">No card needed.</p>
            </div>
        </div>
    </section>
@endsection
