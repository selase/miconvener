@extends('layouts.auth')

@section('page-title', 'Confirm password')

@section('content')

<div>
    <div class="mb-6 h-12 w-12 rounded-2xl bg-amber-50 border border-amber-100 flex items-center justify-center">
        <svg class="h-6 w-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
        </svg>
    </div>

    <h2 class="brand-font text-[26px] font-bold text-slate-900 tracking-tight">Security check</h2>
    <p class="mt-2 text-[14px] text-slate-500">
        This area requires your password to continue.
    </p>

    @if ($errors->any())
        <div class="mt-5 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.confirm') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5" for="password">Your password</label>
            <input id="password" type="password" name="password" required autocomplete="current-password"
                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition"
                placeholder="••••••••" />
        </div>

        <button type="submit"
            class="w-full rounded-xl bg-blue-600 px-4 py-3.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500 transition-all hover:shadow-md hover:shadow-blue-500/25 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
            Confirm →
        </button>
    </form>
</div>

@endsection
