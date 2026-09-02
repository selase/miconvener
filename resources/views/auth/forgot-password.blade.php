@extends('layouts.auth')

@section('page-title', 'Reset password')

@section('content')

<div>
    <h2 class="brand-font text-[26px] font-bold text-slate-900 tracking-tight">Forgot your password?</h2>
    <p class="mt-2 text-[14px] text-slate-500">
        Enter your email address and we'll send you a link to reset your password.
    </p>

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

    <form method="POST" action="{{ route('password.email') }}" class="mt-8 space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5" for="email">Email address</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"
                class="block w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-blue-400 focus:ring-2 focus:ring-blue-100 focus:outline-none transition"
                placeholder="you@company.com" />
        </div>

        <button type="submit"
            class="w-full rounded-xl bg-blue-600 px-4 py-3.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500 transition-all hover:shadow-md hover:shadow-blue-500/25 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
            Send reset link →
        </button>
    </form>

    <p class="mt-8 text-center text-sm text-slate-500">
        Remembered it?
        <a href="{{ route('login') }}" class="text-blue-600 hover:text-blue-700 font-medium">Back to sign in →</a>
    </p>
</div>

@endsection
