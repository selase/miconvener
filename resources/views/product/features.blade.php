@extends('layouts.product')

@section('title', 'Full features - '.config('product-page.brand.name'))

@section('content')
    <section class="pt-20 md:pt-28 pb-6">
        <div class="mx-auto max-w-7xl px-6">
            <h1 class="text-[40px] leading-[44px] font-bold tracking-[-0.025em] text-slate-900 md:text-[52px] md:leading-[56px]">Full features</h1>
            <p class="mt-4 max-w-3xl text-[19px] leading-[30px] text-slate-500">{{ config('product-page.capabilities.subtitle') }}</p>
        </div>
    </section>
    <section class="pb-24">
        <div class="mx-auto max-w-7xl px-6">
            @include('product.partials.feature-groups')
        </div>
    </section>
@endsection
