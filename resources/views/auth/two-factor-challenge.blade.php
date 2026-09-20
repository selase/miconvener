@extends('layouts.auth')

@section('page-title', 'Two-factor authentication')

@section('content')

<div>
    <div class="mb-6 h-12 w-12 rounded-2xl bg-blue-50 border border-blue-100 flex items-center justify-center">
        <svg class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
        </svg>
    </div>

    <h2 class="brand-font text-[26px] font-bold text-slate-900 tracking-tight">Two-factor authentication</h2>
    <p class="mt-2 text-[14px] text-slate-500">
        Enter the 6-digit code from your authenticator app. If you can't reach it, enter one of your recovery codes instead.
    </p>

    @if ($errors->any())
        <div class="mt-5 rounded-xl bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('two-factor.challenge.store') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5" for="one_time_password">Authentication or recovery code</label>
            {{-- No numeric pattern here: it would refuse to submit a recovery code. --}}
            <input id="one_time_password" type="text" name="one_time_password" required autofocus
                autocomplete="one-time-code"
                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-center font-mono text-base tracking-[0.3em] text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition"
                placeholder="000000" />
        </div>

        <button type="submit"
            class="w-full rounded-xl bg-blue-600 px-4 py-3.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500 transition-all hover:shadow-md hover:shadow-blue-500/25 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
            Verify →
        </button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-6 text-center">
        @csrf
        <button type="submit" class="text-sm font-medium text-slate-500 hover:text-slate-700 underline underline-offset-2">
            Cancel and sign out
        </button>
    </form>
</div>

@endsection
