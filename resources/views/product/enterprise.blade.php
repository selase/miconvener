@extends('layouts.product')

@section('title', 'Talk to our sales team - ' . config('product-page.brand.name'))

@push('styles')
<style>
    @@keyframes fade-up {
        from { opacity: 0; transform: translateY(18px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .fade-up { animation: fade-up 0.65s ease-out forwards; opacity: 0; }
</style>
@endpush

@section('content')
    <section class="pt-20 md:pt-32 pb-20">
        <div class="mx-auto max-w-7xl px-6">
            <div class="grid gap-16 lg:grid-cols-2 lg:items-center">

                {{-- Left Side: Text & Quote --}}
                <div class="max-w-2xl fade-up" style="animation-delay:0.1s">
                    <div class="inline-flex items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700 mb-6">
                        Enterprise
                    </div>

                    <h1 class="text-[48px] leading-[52px] font-bold tracking-[-0.025em] text-slate-900 md:text-[64px] md:leading-17">
                        Talk to our<br>sales team
                    </h1>

                    <p class="mt-6 text-[19px] leading-[30px] text-slate-500">
                        {{ config('product-page.brand.name') }} is purpose-built to help you scale your SaaS with
                        confidence. We can help if you process billions of events, have specific security requirements,
                        or need custom enterprise support.
                    </p>

                    {{-- Trust signals --}}
                    <div class="mt-10 grid grid-cols-2 gap-4">
                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                            <div class="text-2xl font-bold text-slate-900">SOC 2</div>
                            <div class="mt-0.5 text-sm text-slate-500">Type II certified</div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                            <div class="text-2xl font-bold text-slate-900">99.9%</div>
                            <div class="mt-0.5 text-sm text-slate-500">Uptime SLA</div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                            <div class="text-2xl font-bold text-slate-900">GDPR</div>
                            <div class="mt-0.5 text-sm text-slate-500">Fully compliant</div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                            <div class="text-2xl font-bold text-slate-900">24 / 7</div>
                            <div class="mt-0.5 text-sm text-slate-500">Dedicated support</div>
                        </div>
                    </div>

                    {{-- Testimonial --}}
                    <div class="mt-10 border-l-4 border-blue-500 pl-6">
                        <p class="text-slate-600 italic text-[17px] leading-7">
                            "The automated tenant isolation and KMS-backed encryption allowed us to meet our compliance requirements in weeks, not months. A game-changer for enterprise readiness."
                        </p>
                        <div class="mt-4 flex items-center gap-3">
                            <div class="h-9 w-9 rounded-full bg-slate-200 flex items-center justify-center text-slate-600 text-sm font-bold">M</div>
                            <div>
                                <div class="text-sm font-semibold text-slate-900">Marc G.</div>
                                <div class="text-xs text-slate-500">CTO at SecureLogix</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Right Side: Form Card --}}
                <div class="fade-up" style="animation-delay:0.25s">
                    <div class="rounded-3xl border border-slate-200 bg-white p-8 md:p-10 shadow-xl shadow-slate-200/60">
                        <h3 class="text-[22px] font-bold text-slate-900 mb-2">Tell us how we can help</h3>
                        <p class="text-sm text-slate-500 mb-8">We typically respond within one business day.</p>

                        @if (session('success'))
                            <div class="mb-6 rounded-xl bg-emerald-50 border border-emerald-200 p-4 text-emerald-700 text-sm">
                                {{ session('success') }}
                            </div>
                        @endif

                        @if (session('error'))
                            <div class="mb-6 rounded-xl bg-red-50 border border-red-200 p-4 text-red-700 text-sm">
                                {{ session('error') }}
                            </div>
                        @endif

                        <form action="{{ route('product.enterprise.lead') }}" method="POST" class="space-y-5">
                            @csrf

                            <div>
                                <label for="name" class="block text-sm font-medium text-slate-700 mb-1.5">Full name</label>
                                <input type="text" name="name" id="name" placeholder="Jane Smith" value="{{ old('name') }}" required
                                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition">
                                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="email" class="block text-sm font-medium text-slate-700 mb-1.5">Company email</label>
                                <input type="email" name="email" id="email" placeholder="jane@company.com" value="{{ old('email') }}" required
                                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition">
                                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="company" class="block text-sm font-medium text-slate-700 mb-1.5">Company name</label>
                                <input type="text" name="company" id="company" placeholder="Acme Corp" value="{{ old('company') }}"
                                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition">
                                @error('company') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label for="message" class="block text-sm font-medium text-slate-700 mb-1.5">How can we help?</label>
                                <textarea name="message" id="message" rows="4" required
                                    placeholder="I'm interested in {{ config('product-page.brand.name') }}. I'd like to learn more about..."
                                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition resize-none">{{ old('message') }}</textarea>
                                @error('message') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <button type="submit"
                                class="w-full rounded-xl bg-blue-600 px-6 py-3.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500 transition-all hover:shadow-md hover:shadow-blue-500/25 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                Send message →
                            </button>

                            <p class="text-center text-xs text-slate-400 mt-4">
                                By clicking send, you agree to our
                                <a href="#" class="text-slate-600 underline hover:text-slate-900 transition">Privacy Policy</a>.
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
