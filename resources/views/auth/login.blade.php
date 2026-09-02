@extends('layouts.auth')

@section('page-title', 'Sign in')

@section('content')

<div>
    <h2 class="brand-font text-[26px] font-bold text-slate-900 tracking-tight">Welcome back</h2>
    <p class="mt-2 text-[14px] text-slate-500">Sign in to your QNotify account.</p>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mt-5 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5">
        @csrf

        {{-- Email --}}
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5" for="email">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition"
                placeholder="you@company.com" />
        </div>

        {{-- Password --}}
        <div>
            <div class="flex items-center justify-between mb-1.5">
                <label class="block text-sm font-medium text-slate-700" for="password">Password</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-xs text-blue-600 hover:text-blue-700 font-medium">
                        Forgot password?
                    </a>
                @endif
            </div>
            <input id="password" type="password" name="password" required autocomplete="current-password"
                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition"
                placeholder="••••••••" />
        </div>

        {{-- Remember me --}}
        <label class="flex items-center gap-2.5 cursor-pointer">
            <input type="checkbox" name="remember" id="remember_me"
                class="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500 focus:ring-offset-0">
            <span class="text-sm text-slate-600">Keep me signed in</span>
        </label>

        {{-- Submit --}}
        <button type="submit"
            class="w-full rounded-xl bg-blue-600 px-4 py-3.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500 transition-all hover:shadow-md hover:shadow-blue-500/25 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
            Sign in →
        </button>
    </form>

    @if (config('app.system_setting.allow_registration'))
        <p class="mt-8 text-center text-sm text-slate-500">
            Don't have an account?
            <a href="{{ route('register') }}" class="text-blue-600 hover:text-blue-700 font-medium">Create one →</a>
        </p>
    @endif
</div>

@endsection
