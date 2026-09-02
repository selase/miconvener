@extends('layouts.auth')

@section('page-title', 'Create account')
@section('form-width', 'max-w-3xl')
@section('panel-align', 'items-start')

@section('content')

<div>
    <h2 class="brand-font text-[24px] font-bold text-slate-900 tracking-tight">Start your QNotify workspace</h2>
    <p class="mt-2 text-[14px] text-slate-500">Choose a paid plan, create your account, and launch your first branch queue.</p>

    @if ($errors->any())
        <div class="mt-5 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('register') }}" class="mt-8">
        @csrf

        <div class="grid gap-8 md:grid-cols-2">

            {{-- ── Left: Plan selection ──────────────────────────────── --}}
            <div>
                <div class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-4">Select your plan</div>

                <div class="space-y-3">
                    @foreach (config('product-page.plans', []) as $plan)
                        @php
                            $monthlyPrice = $plan['monthly_price'] ?? null;
                        @endphp
                        @if (! is_numeric($monthlyPrice) || $monthlyPrice <= 0)
                            @continue
                        @endif
                        @php $isPopular = $plan['most_popular'] ?? false; @endphp

                        <label class="block cursor-pointer group">
                            <input type="radio" name="plan" value="{{ $plan['slug'] }}" class="sr-only peer"
                                {{ old('plan', 'pro') === $plan['slug'] ? 'checked' : '' }} required>
                            <div class="relative rounded-2xl border-2 border-slate-200 p-4 transition-all
                                        peer-checked:border-blue-500 peer-checked:bg-blue-50/50
                                        hover:border-slate-300 hover:shadow-sm">

                                @if ($isPopular)
                                    <div class="absolute -top-2.5 right-4">
                                        <span class="rounded-full bg-blue-600 px-2.5 py-0.5 text-[10px] font-bold text-white tracking-wide">
                                            Most Popular
                                        </span>
                                    </div>
                                @endif

                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex-1">
                                        <div class="text-[14px] font-semibold text-slate-900">{{ $plan['name'] }}</div>
                                        <div class="mt-0.5 text-[12px] text-slate-400 leading-snug">{{ $plan['description'] }}</div>
                                    </div>
                                    <div class="flex-none text-right">
                                        <span class="brand-font text-[20px] font-bold text-slate-900">${{ $monthlyPrice }}</span>
                                        <span class="text-[12px] text-slate-400">/mo</span>
                                    </div>
                                </div>

                                <div class="mt-3 space-y-1">
                                    @foreach (array_slice($plan['features'], 0, 3) as $feat)
                                        <div class="flex items-start gap-1.5 text-[12px] text-slate-500">
                                            <svg class="mt-px h-3.5 w-3.5 flex-none text-emerald-500" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd"/>
                                            </svg>
                                            {{ $feat }}
                                        </div>
                                    @endforeach
                                    @if (count($plan['features']) > 3)
                                        <div class="text-[11px] text-slate-400 pl-5">+{{ count($plan['features']) - 3 }} more features</div>
                                    @endif
                                </div>

                                {{-- Selection indicator --}}
                                <div class="absolute top-4 right-4 h-5 w-5 rounded-full border-2 border-slate-200 bg-white
                                            peer-checked:border-blue-500 peer-checked:bg-blue-500 transition-all hidden
                                            [label:has(input:checked)_&]:flex items-center justify-center">
                                    <svg class="h-3 w-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                </div>

                            </div>
                        </label>
                    @endforeach
                </div>

                <p class="mt-4 text-[11px] text-slate-400 leading-snug">
                    Annual billing saves up to 17%. You can switch plans anytime after signing up.
                </p>
            </div>

            {{-- ── Right: Account form ───────────────────────────────── --}}
            <div>
                <div class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-4">Your account</div>

                <div class="space-y-4">

                    {{-- Name (two columns) --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5" for="first_name">First name</label>
                            <input id="first_name" type="text" name="first_name" value="{{ old('first_name') }}" required
                                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition"
                                placeholder="Ada" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5" for="last_name">Last name</label>
                            <input id="last_name" type="text" name="last_name" value="{{ old('last_name') }}" required
                                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition"
                                placeholder="Osei" />
                        </div>
                    </div>

                    {{-- Organization --}}
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5" for="organization_name">Organization name</label>
                        <input id="organization_name" type="text" name="organization_name" value="{{ old('organization_name') }}" required
                            class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition"
                            placeholder="Acme Corp" />
                    </div>

                    {{-- Email --}}
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5" for="email">Work email</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email"
                            class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition"
                            placeholder="ada@acmecorp.com" />
                    </div>

                    {{-- Password --}}
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5" for="password">Password</label>
                        <input id="password" type="password" name="password" required autocomplete="new-password"
                            class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition"
                            placeholder="8+ characters" />
                    </div>

                    {{-- Confirm password --}}
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5" for="password_confirmation">Confirm password</label>
                        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                            class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition"
                            placeholder="••••••••" />
                    </div>

                    {{-- Submit --}}
                    <button type="submit"
                        class="w-full rounded-xl bg-blue-600 px-4 py-3.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500 transition-all hover:shadow-md hover:shadow-blue-500/25 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 mt-2">
                        Continue to payment →
                    </button>

                    <p class="text-[11px] text-slate-400 text-center leading-snug">
                        By signing up you agree to our
                        <a href="/terms" class="underline hover:text-slate-600">Terms of Service</a>
                        and <a href="/privacy" class="underline hover:text-slate-600">Privacy Policy</a>.
                    </p>
                </div>
            </div>

        </div>
    </form>

    <p class="mt-8 text-center text-sm text-slate-500">
        Already have an account?
        <a href="{{ route('login') }}" class="text-blue-600 hover:text-blue-700 font-medium">Sign in →</a>
    </p>
</div>

@endsection
