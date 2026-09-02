@extends('layouts.auth')

@section('page-title', 'Verify email')

@section('content')

<div class="text-center">
    {{-- Icon --}}
    <div class="mx-auto mb-6 h-14 w-14 rounded-2xl bg-blue-50 border border-blue-100 flex items-center justify-center">
        <svg class="h-7 w-7 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
        </svg>
    </div>

    <h2 class="brand-font text-[24px] font-bold text-slate-900 tracking-tight">Check your inbox</h2>
    <p class="mt-3 text-[14px] text-slate-500 leading-[22px]">
        Thanks for signing up! Before you continue, please verify your email address by clicking the link we just sent you.
    </p>

    @if (session('status') == 'verification-link-sent')
        <div class="mt-5 rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-700">
            A new verification link has been sent to your email address.
        </div>
    @endif

    <div class="mt-8 flex flex-col gap-3">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit"
                class="w-full rounded-xl bg-blue-600 px-4 py-3.5 text-sm font-semibold text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500 transition-all focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                Resend verification email
            </button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit"
                class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3.5 text-sm font-medium text-slate-700 hover:bg-slate-50 hover:border-slate-300 transition-all focus:outline-none focus:ring-2 focus:ring-slate-300 focus:ring-offset-2">
                Sign out
            </button>
        </form>
    </div>
</div>

@endsection
