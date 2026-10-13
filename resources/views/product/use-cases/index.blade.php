@extends('layouts.product')

@section('title', $hub['title'].' - '.config('product-page.brand.name'))

@section('content')
    <section class="pt-20 md:pt-32 pb-12">
        <div class="mx-auto max-w-5xl px-6 text-center">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-700">{{ $hub['eyebrow'] }}</p>
            <h1 class="mt-4 text-[40px] leading-[44px] font-bold tracking-[-0.025em] text-slate-900 md:text-[56px] md:leading-[60px]">
                {{ $hub['title'] }}
            </h1>
            <p class="mx-auto mt-6 max-w-3xl text-[19px] leading-[30px] text-slate-500">{{ $hub['intro'] }}</p>
        </div>
    </section>

    @foreach ($groups as $groupTitle => $cases)
        <section class="pb-16">
            <div class="mx-auto max-w-7xl px-6">
                <h2 class="mb-6 text-sm font-semibold uppercase tracking-[0.14em] text-slate-400">{{ $groupTitle }}</h2>
                <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-{{ min(count($cases), 4) }}">
                    @foreach ($cases as $case)
                        <a href="{{ route('use-cases.show', $case['slug']) }}"
                           class="group flex flex-col rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-slate-300 hover:shadow-sm">
                            <span class="text-xs font-semibold uppercase tracking-wider text-blue-700">{{ $case['eyebrow'] }}</span>
                            <h3 class="mt-3 text-xl font-semibold leading-snug text-slate-900">{{ $case['title'] }}</h3>
                            <p class="mt-2 flex-1 text-[15px] leading-6 text-slate-500">{{ $case['summary'] }}</p>
                            <span class="mt-5 text-sm font-medium text-slate-900 group-hover:underline">See how it works &rarr;</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </section>
    @endforeach

    <section class="pb-24">
        <div class="mx-auto max-w-3xl px-6 text-center">
            <h2 class="text-3xl font-bold tracking-[-0.02em] text-slate-900">Not sure which fits?</h2>
            <p class="mt-3 text-slate-500">Most gatherings are a mix. Start free and set up the event the way you run it.</p>
            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ route('register', ['plan' => 'free']) }}" class="rounded-lg bg-slate-900 px-6 py-3 text-sm font-semibold text-white hover:bg-slate-800">Start free</a>
                <a href="{{ route('product.enterprise') }}" class="rounded-lg border border-slate-300 bg-white px-6 py-3 text-sm font-semibold text-slate-900 hover:bg-slate-50">Talk to us</a>
            </div>
        </div>
    </section>
@endsection
