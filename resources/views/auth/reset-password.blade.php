@extends('layouts.auth')

@section('page-title', 'Set new password')

@section('content')

<div>
    <h2 class="brand-font text-[26px] font-bold text-slate-900 tracking-tight">Set a new password</h2>
    <p class="mt-2 text-[14px] text-slate-500">
        Choose a strong password. You'll use it to sign in going forward.
    </p>

    @if ($errors->any())
        <div class="mt-5 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}" class="mt-8 space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5" for="email">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}" required autocomplete="email"
                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition"
                placeholder="you@company.com" />
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5" for="password">New password</label>
            <input id="password" type="password" name="password" required autocomplete="new-password"
                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition"
                placeholder="8+ characters" />
            <p class="mt-1.5 text-[12px] text-slate-400">Use 8+ characters with a mix of letters, numbers &amp; symbols.</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5" for="password_confirmation">Confirm new password</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition"
                placeholder="••••••••" />
        </div>

        <button type="submit"
            class="w-full rounded-xl bg-blue-600 px-4 py-3.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500 transition-all hover:shadow-md hover:shadow-blue-500/25 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
            Reset password →
        </button>
    </form>

    <p class="mt-8 text-center text-sm text-slate-500">
        <a href="{{ route('login') }}" class="text-blue-600 hover:text-blue-700 font-medium">← Back to sign in</a>
    </p>
</div>

@endsection
