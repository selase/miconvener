@extends('layouts.product')

@section('title', $case['title'].' - '.config('product-page.brand.name'))

@section('content')
    <section class="pt-20 md:pt-28 pb-14">
        <div class="mx-auto max-w-7xl px-6">
            <a href="{{ route('use-cases.index') }}" class="text-sm text-slate-500 hover:text-slate-900">&larr; All use cases</a>
            <div class="mt-8 max-w-3xl">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-700">{{ $case['eyebrow'] }}</p>
                <h1 class="mt-4 text-[40px] leading-[44px] font-bold tracking-[-0.025em] text-slate-900 md:text-[56px] md:leading-[60px]">{{ $case['headline'] }}</h1>
                <p class="mt-6 text-[19px] leading-[30px] text-slate-500">{{ $case['intro'] }}</p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <a href="{{ route('register', ['plan' => 'free']) }}" class="rounded-lg bg-slate-900 px-6 py-3 text-sm font-semibold text-white hover:bg-slate-800">Start free</a>
                    <a href="{{ route('product.enterprise') }}" class="rounded-lg border border-slate-300 bg-white px-6 py-3 text-sm font-semibold text-slate-900 hover:bg-slate-50">Talk to us</a>
                </div>
            </div>
        </div>
    </section>

    <section class="pb-16">
        <div class="mx-auto max-w-7xl px-6">
            <h2 class="text-sm font-semibold uppercase tracking-[0.14em] text-slate-400">Who it is for</h2>
            <ul class="mt-4 grid gap-3 md:grid-cols-2">
                @foreach ($case['for'] as $audience)
                    <li class="rounded-xl border border-slate-200 bg-white px-5 py-4 text-slate-700">{{ $audience }}</li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="pb-16">
        <div class="mx-auto max-w-7xl px-6">
            <h2 class="text-3xl font-bold tracking-[-0.02em] text-slate-900">What you get</h2>
            <div class="mt-8 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($case['features'] as $feature)
                    <div class="rounded-2xl border border-slate-200 bg-white p-6">
                        <h3 class="text-lg font-semibold text-slate-900">{{ $feature['title'] }}</h3>
                        <p class="mt-2 text-[15px] leading-6 text-slate-500">{{ $feature['text'] }}</p>
                    </div>
                @endforeach
            </div>
            @isset($case['note'])
                <p class="mt-6 text-sm text-slate-500">{{ $case['note'] }}</p>
            @endisset
        </div>
    </section>

    <section class="pb-16">
        <div class="mx-auto max-w-7xl px-6">
            <h2 class="text-3xl font-bold tracking-[-0.02em] text-slate-900">How it runs</h2>
            <div class="mt-8 grid gap-5 md:grid-cols-3">
                @foreach ($case['timeline'] as $stage => $steps)
                    <div class="rounded-2xl bg-slate-50 p-6">
                        <h3 class="text-sm font-semibold uppercase tracking-wider text-blue-700">{{ $stage }}</h3>
                        <ul class="mt-4 space-y-3 text-[15px] leading-6 text-slate-700">
                            @foreach ($steps as $step)
                                <li class="flex gap-3"><span class="mt-2 h-1.5 w-1.5 flex-none rounded-full bg-slate-400"></span><span>{{ $step }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="pb-16">
        <div class="mx-auto max-w-3xl px-6 text-center">
            <h2 class="text-3xl font-bold tracking-[-0.02em] text-slate-900">{{ $case['value']['title'] }}</h2>
            <p class="mt-4 text-[19px] leading-[30px] text-slate-500">{{ $case['value']['text'] }}</p>
        </div>
    </section>

    @if ($related !== [])
        <section class="pb-24">
            <div class="mx-auto max-w-7xl px-6">
                <h2 class="text-sm font-semibold uppercase tracking-[0.14em] text-slate-400">Also see</h2>
                <div class="mt-4 grid gap-5 md:grid-cols-2">
                    @foreach ($related as $other)
                        <a href="{{ route('use-cases.show', $other['slug']) }}" class="group rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-slate-300 hover:shadow-sm">
                            <h3 class="text-lg font-semibold text-slate-900">{{ $other['title'] }}</h3>
                            <p class="mt-1 text-[15px] text-slate-500">{{ $other['summary'] }}</p>
                            <span class="mt-4 inline-block text-sm font-medium text-slate-900 group-hover:underline">See how it works &rarr;</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection
