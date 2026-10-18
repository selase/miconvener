@extends('layouts.product')

@section('title', $case['title'].' - '.config('product-page.brand.name'))

@section('content')
    @include('product.use-cases.partials.nav', ['current' => $case['slug']])

    {{-- Hero: black band, the page's words on the left, what the product shows on the right. --}}
    <section class="bg-[#08090b] text-white" data-use-case-hero>
        <div class="mx-auto grid max-w-7xl items-center gap-10 px-6 py-12 md:py-16 lg:grid-cols-[1.05fr_.95fr]">
            <div>
                <p class="mono text-[11px] uppercase tracking-[0.18em] text-[#6f9bff]">{{ $case['eyebrow'] }}</p>
                <h1 class="display mt-4 text-[34px] leading-[40px] tracking-[-0.03em] text-white md:text-[44px] md:leading-[50px]">{{ $case['headline'] }}</h1>
                <p class="mt-5 max-w-xl text-[17px] leading-[27px] text-slate-300">{{ $case['intro'] }}</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('register', ['plan' => 'free']) }}" class="rounded-md bg-[var(--field)] px-5 py-3 text-sm font-semibold text-white hover:bg-[var(--field-deep)]">Start for free</a>
                    <a href="{{ route('product.enterprise') }}" class="rounded-md border border-white/20 px-5 py-3 text-sm font-semibold text-white hover:bg-white/5">Book an enterprise walkthrough</a>
                </div>
            </div>
            <div class="w-full">
                @include('product.use-cases.panels.'.$case['slug'])
            </div>
        </div>
    </section>

    {{-- What you get --}}
    <section class="py-20 md:py-24">
        <div class="mx-auto max-w-7xl px-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="mono text-[11px] uppercase tracking-[0.16em] text-slate-400">What you get</p>
                    <h2 class="display mt-3 max-w-xl text-[32px] leading-[38px] text-slate-900 md:text-[38px] md:leading-[44px]">{{ $case['value']['title'] }}</h2>
                </div>
                <a href="{{ route('features') }}" class="text-sm text-slate-600 underline-offset-4 hover:text-slate-900 hover:underline">See all features &rarr;</a>
            </div>
            <div class="mt-10 grid border-l border-t border-slate-200 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($case['features'] as $feature)
                    <div class="border-b border-r border-slate-200 p-6">
                        <div class="flex items-center justify-between gap-3">
                            <span class="mono text-xs text-[var(--field)]">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            <span class="mono rounded border border-slate-200 px-1.5 py-0.5 text-[10px] uppercase tracking-wider text-slate-500">{{ $feature['tag'] }}</span>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold leading-snug text-slate-900">{{ $feature['title'] }}</h3>
                        <p class="mt-2 text-[15px] leading-6 text-slate-500">{{ $feature['text'] }}</p>
                    </div>
                @endforeach
            </div>
            @isset($case['note'])
                <p class="mt-6 text-sm text-slate-500">{{ $case['note'] }}</p>
            @endisset
        </div>
    </section>

    {{-- How it runs: one tab per stage, each with what the console shows at that point. --}}
    @php($stages = array_keys($case['timeline']))
    <section class="border-y border-slate-200 bg-slate-50 py-20 md:py-24" x-data="{ stage: 0 }">
        <div class="mx-auto max-w-7xl px-6">
            <p class="mono text-[11px] uppercase tracking-[0.16em] text-slate-400">How it runs</p>
            <h2 class="display mt-3 text-[32px] leading-[38px] text-slate-900 md:text-[38px] md:leading-[44px]">From the first invitation to the last thank-you.</h2>

            <div class="mt-10 flex gap-8 overflow-x-auto border-b border-slate-200" role="tablist">
                @foreach ($stages as $index => $stage)
                    <button type="button" role="tab" id="stage-tab-{{ $index }}" aria-controls="stage-{{ $index }}"
                        x-on:click="stage = {{ $index }}"
                        x-bind:aria-selected="stage === {{ $index }} ? 'true' : 'false'"
                        x-bind:class="stage === {{ $index }} ? 'border-[var(--field)] text-slate-900' : 'border-transparent text-slate-500 hover:text-slate-800'"
                        class="-mb-px shrink-0 border-b-2 pb-3 text-[15px] {{ $index === 0 ? 'border-[var(--field)] text-slate-900' : 'border-transparent text-slate-500' }}">
                        <span class="mono mr-1.5 text-[11px] text-slate-400">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>{{ $stage }}
                    </button>
                @endforeach
            </div>

            @foreach ($stages as $index => $stage)
                @php($panel = $case['panels'][$stage] ?? null)
                <div id="stage-{{ $index }}" role="tabpanel" aria-labelledby="stage-tab-{{ $index }}"
                    x-show="stage === {{ $index }}" @if ($index > 0) x-cloak style="display: none" @endif
                    class="mt-8 grid gap-10 lg:grid-cols-[.85fr_1.15fr]" data-use-case-stage>
                    <ul class="divide-y divide-slate-200 border-b border-slate-200">
                        @foreach ($case['timeline'][$stage] as $step)
                            <li class="flex gap-3 py-4 text-[15px] text-slate-800"><span class="text-[var(--field)]">&rarr;</span><span>{{ $step }}</span></li>
                        @endforeach
                    </ul>
                    @if ($panel)
                        <div class="overflow-x-auto rounded-md border border-slate-200 bg-white">
                            <div class="border-b border-slate-200 px-5 py-3.5 text-sm font-semibold text-slate-900">{{ $panel['title'] }}</div>
                            <table class="w-full text-left text-[13px]">
                                <thead>
                                    <tr>
                                        @foreach ($panel['columns'] as $column)
                                            <th scope="col" class="mono px-5 pb-2 pt-3 text-[10px] font-normal uppercase tracking-wider text-slate-400">{{ $column }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($panel['rows'] as $row)
                                        <tr class="border-t border-slate-100">
                                            @foreach ($row as $cell)
                                                <td class="px-5 py-3 {{ $loop->first ? 'text-slate-900' : 'text-slate-600' }}">{{ $cell }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endforeach
            <p class="mt-6 text-xs text-slate-400">Sample figures. Ticket prices are examples of what an organiser might charge.</p>
        </div>
    </section>

    {{-- The point of it, in one sentence. No customer quote until there are real customers. --}}
    <section class="py-20 md:py-28">
        <div class="mx-auto grid max-w-7xl gap-6 px-6 lg:grid-cols-[12rem_1fr]">
            <p class="mono text-[11px] uppercase tracking-[0.16em] text-slate-400">On the ground</p>
            @php($statement = e($case['value']['text']))
            @php($highlight = e($case['value']['highlight']))
            <p class="display text-[28px] leading-[36px] text-slate-900 md:text-[40px] md:leading-[50px]" data-use-case-statement>
                {!! str_replace($highlight, '<span class="text-[var(--field)]">'.$highlight.'</span>', $statement) !!}
            </p>
        </div>
    </section>

    {{-- Closing call to action --}}
    <section class="border-y border-slate-200 bg-slate-50 py-16" data-use-case-cta>
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-8 px-6">
            <div class="max-w-xl">
                <h2 class="display text-[30px] leading-[36px] text-slate-900">{{ $case['cta'] }}</h2>
                <p class="mt-3 text-[15px] leading-6 text-slate-500">Start free and set up your first event today, or talk to us about running it at scale.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('register', ['plan' => 'free']) }}" class="rounded-md bg-[var(--field)] px-5 py-3 text-sm font-semibold text-white hover:bg-[var(--field-deep)]">Start for free</a>
                <a href="{{ route('product.enterprise') }}" class="rounded-md border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-900 hover:bg-slate-50">Talk to us about Enterprise</a>
            </div>
        </div>
    </section>

    @if ($related !== [])
        <section class="py-16 md:py-20">
            <div class="mx-auto max-w-7xl px-6">
                <p class="mono text-[11px] uppercase tracking-[0.16em] text-slate-400">Related use cases</p>
                <div class="mt-5 grid border-l border-t border-slate-200 md:grid-cols-2">
                    @foreach ($related as $other)
                        <a href="{{ route('use-cases.show', $other['slug']) }}" class="group border-b border-r border-slate-200 p-6 transition hover:bg-slate-50">
                            <h3 class="text-lg font-semibold text-slate-900">{{ $other['title'] }}</h3>
                            <p class="mt-1 text-[15px] text-slate-500">{{ $other['summary'] }}</p>
                            <span class="mt-4 inline-block text-sm text-[var(--field)] group-hover:underline">See how it works &rarr;</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection
