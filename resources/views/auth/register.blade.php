@extends('layouts.auth')

@section('page-title', 'Create account')
@section('form-width', 'max-w-3xl')
@section('panel-align', 'items-start')

@section('content')

<div>
    <h2 class="brand-font text-[24px] text-slate-900">Start your {{ config('app.name') }} workspace</h2>
    <p class="mt-2 text-[14px] text-slate-500">Pick a plan, create your account, and we'll set up your workspace.</p>

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
                        @if (! is_numeric($monthlyPrice))
                            @continue
                        @endif
                        @php
                            $isPopular = $plan['most_popular'] ?? false;
                            $isFreePlan = $monthlyPrice <= 0;
                        @endphp

                        <label class="block cursor-pointer group">
                            <input type="radio" name="plan" value="{{ $plan['slug'] }}" class="sr-only peer"
                                data-price="{{ $monthlyPrice }}"
                                {{ old('plan', request('plan', 'starter')) === $plan['slug'] ? 'checked' : '' }} required>
                            <div class="relative rounded-2xl border-2 border-slate-200 p-4 transition-all
                                        peer-checked:border-[#155dfc] peer-checked:bg-blue-50/50
                                        hover:border-slate-300 hover:shadow-sm">

                                @if ($isPopular)
                                    <div class="absolute -top-2.5 right-4">
                                        <span class="rounded-full bg-[#155dfc] px-2.5 py-0.5 text-[10px] font-bold text-white tracking-wide">
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
                                        @if ($isFreePlan)
                                            <span class="brand-font text-[20px] font-bold text-slate-900">Free</span>
                                        @else
                                            <span class="brand-font text-[20px] font-bold text-slate-900">{{ config('services.paystack.currency', 'GHS') }} {{ $monthlyPrice }}</span>
                                            <span class="text-[12px] text-slate-400">/mo</span>
                                        @endif
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
                                            peer-checked:border-[#155dfc] peer-checked:bg-[#155dfc] transition-all hidden
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
                    The free plan runs free events only. Annual billing saves up to 17% on paid plans, and you can
                    switch plans anytime after signing up.
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

                    {{-- Workspace address --}}
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5" for="slug">Workspace address</label>
                        <div class="flex items-stretch rounded-xl border border-slate-200 bg-slate-50 focus-within:bg-white focus-within:border-blue-400 focus-within:ring-2 focus-within:ring-blue-100 transition">
                            <input id="slug" type="text" name="slug" value="{{ old('slug') }}" required
                                minlength="{{ App\Support\TenantHandle::MIN_LENGTH }}"
                                maxlength="{{ App\Support\TenantHandle::MAX_LENGTH }}"
                                pattern="[a-z0-9]+(-[a-z0-9]+)*"
                                autocapitalize="none" autocorrect="off" spellcheck="false"
                                class="min-w-0 flex-1 rounded-l-xl bg-transparent px-3.5 py-3 text-sm text-slate-900 placeholder-slate-400 focus:outline-none"
                                placeholder="accra-tech-week" />
                            <span class="flex items-center rounded-r-xl border-l border-slate-200 bg-slate-100 px-3 text-[13px] text-slate-500 whitespace-nowrap">
                                .{{ Illuminate\Support\Str::of(config('session.domain'))->ltrim('.') }}
                            </span>
                        </div>
                        <p class="mt-1.5 text-[11px] text-slate-400 leading-snug">
                            Guests reach your events here, and it appears on their tickets. Lowercase letters, numbers and hyphens.
                        </p>
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
                    <button type="submit" id="submit-button"
                        class="w-full rounded-xl bg-[#155dfc] px-4 py-3.5 text-sm font-semibold text-white shadow-sm shadow-[#155dfc]/25 hover:bg-[#0b3ea8] transition-all hover:shadow-md hover:shadow-blue-500/25 focus:outline-none focus:ring-2 focus:ring-[#155dfc] focus:ring-offset-2 mt-2">
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
        <a href="{{ route('login') }}" class="text-[#155dfc] hover:text-[#0b3ea8] font-medium">Sign in →</a>
    </p>
</div>

<script>
    (function () {
        // The button promises payment. On the free plan that promise is false.
        const submit = document.getElementById('submit-button');
        const planInputs = document.querySelectorAll('input[name="plan"]');

        function syncSubmitLabel() {
            if (!submit) return;
            const chosen = document.querySelector('input[name="plan"]:checked');
            const isFree = chosen && Number(chosen.dataset.price) <= 0;
            submit.textContent = isFree ? 'Create my workspace' : 'Continue to payment →';
        }

        planInputs.forEach(function (input) {
            input.addEventListener('change', syncSubmitLabel);
        });
        syncSubmitLabel();

        // Most organizers should never have to think about the handle, so it fills
        // itself from the organization name -- and stops the moment the visitor
        // edits it, so a deliberate choice is never overwritten as they keep typing.
        const org = document.getElementById('organization_name');
        const handle = document.getElementById('slug');
        if (!org || !handle) return;

        let touched = handle.value.trim() !== '';
        handle.addEventListener('input', function () { touched = true; });

        org.addEventListener('input', function () {
            if (touched) return;
            handle.value = org.value
                .toLowerCase()
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+/, '')
                .slice(0, {{ App\Support\TenantHandle::MAX_LENGTH }})
                .replace(/-+$/, '');
        });
    })();
</script>

@endsection
