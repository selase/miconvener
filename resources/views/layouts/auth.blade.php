<!doctype html>
<html lang="en">
<head>
    @php
        $brandName = 'QNotify';
        $brandMark = 'QN';
        $favicon = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%230f172a'/%3E%3Cpath d='M20 18h16c9.941 0 18 8.059 18 18s-8.059 18-18 18H20V18zm14 10H30v16h4c4.418 0 8-3.582 8-8s-3.582-8-8-8z' fill='%23fff'/%3E%3C/svg%3E";
    @endphp
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>@yield('page-title', 'Sign in') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ $favicon }}" />

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:ital,wght@0,400;0,500;0,600;1,400&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .brand-font { font-family: 'Space Grotesk', sans-serif; }
        body { font-family: 'Instrument Sans', sans-serif; }
        @@keyframes flow-dash {
            from { stroke-dashoffset: 20; }
            to   { stroke-dashoffset: 0; }
        }
        @@keyframes float-in {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .flow-dash { animation: flow-dash 0.6s linear infinite; }
        .float-in  { animation: float-in 0.5s ease-out forwards; }
    </style>
</head>

<body class="min-h-screen bg-white text-slate-900 selection:bg-blue-100">
<div class="flex min-h-screen">

    {{-- ── Left branding panel (desktop only) ───────────────────────── --}}
    <div class="hidden lg:flex lg:w-[420px] xl:w-[480px] flex-none flex-col bg-gradient-to-br from-slate-900 via-blue-950 to-slate-900 p-10 xl:p-12 overflow-hidden relative">

        {{-- Background glow --}}
        <div class="pointer-events-none absolute inset-0">
            <div class="absolute -top-20 -left-20 h-64 w-64 rounded-full bg-blue-500/10 blur-3xl"></div>
            <div class="absolute bottom-20 -right-20 h-64 w-64 rounded-full bg-blue-400/8 blur-3xl"></div>
        </div>

        {{-- Logo --}}
        <a href="{{ route('product.template') }}" class="relative flex items-center gap-2.5 flex-none z-10">
            <div class="h-8 w-8 grid place-items-center rounded-lg border border-white/12 bg-white/8 text-[11px] font-bold text-white brand-font">
                {{ $brandMark }}
            </div>
            <span class="brand-font text-sm font-semibold text-white/80">{{ $brandName }}</span>
        </a>

        {{-- Main copy (pushed to lower half) --}}
        <div class="relative mt-auto pb-10 z-10">
            <h1 class="brand-font text-[30px] xl:text-[36px] font-bold text-white leading-tight tracking-[-0.02em]">
                Every insight,<br>automated.
            </h1>
            <p class="mt-3 text-[14px] leading-[24px] text-white/45">
                Capture queue activity, get AI insights, and keep service operations moving.
            </p>
            {{-- Animated queue pipeline (vertical) --}}
            <div class="mt-10">
                @foreach ([
                    ['📱', 'Join Queue',  'Customers check in from any device',               'from-blue-500/20 to-blue-600/10',     'border-blue-400/25'],
                    ['🧭', 'Route',      'Send requests to the right branch or provider',     'from-violet-500/20 to-violet-600/10', 'border-violet-400/25'],
                    ['📣', 'Notify',     'Automatic turn alerts and live status updates',      'from-emerald-500/20 to-teal-600/10',  'border-emerald-400/25'],
                    ['✅', 'Complete',   'Keep walk-ins and follow-ups moving smoothly',       'from-amber-500/20 to-orange-600/10',  'border-amber-400/25'],
                ] as [$icon, $title, $desc, $gradient, $border])
                    <div class="flex gap-3.5">
                        <div class="flex flex-col items-center">
                            <div class="h-10 w-10 flex-none rounded-xl bg-gradient-to-br {{ $gradient }} border {{ $border }} flex items-center justify-center text-[18px]">
                                {{ $icon }}
                            </div>
                            @if (! $loop->last)
                                <div class="my-1 flex-1">
                                    <svg width="14" height="22" viewBox="0 0 14 22" fill="none">
                                        <path d="M7 0 L7 16" stroke="rgba(148,163,184,0.3)"
                                              stroke-width="1.5" stroke-dasharray="4 3"
                                              stroke-linecap="round" class="flow-dash"/>
                                        <path d="M3 14 L7 20 L11 14" stroke="rgba(148,163,184,0.25)"
                                              stroke-width="1.5" stroke-linecap="round"
                                              stroke-linejoin="round"/>
                                    </svg>
                                </div>
                            @endif
                        </div>
                        <div class="pt-2 pb-4">
                            <div class="text-[13px] font-semibold text-white/75">{{ $title }}</div>
                            <div class="text-[11px] text-white/30 mt-0.5">{{ $desc }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ── Right form panel ──────────────────────────────────────────── --}}
    <div class="flex flex-1 flex-col min-h-screen">

        {{-- Mobile header --}}
        <div class="flex items-center gap-2.5 px-6 py-5 border-b border-slate-100 lg:hidden">
            <div class="h-8 w-8 grid place-items-center rounded-lg border border-slate-200 bg-slate-100 text-[11px] font-bold text-slate-700 brand-font">
                {{ $brandMark }}
            </div>
            <span class="brand-font text-sm font-semibold text-slate-800">{{ $brandName }}</span>
        </div>

        {{-- Form area --}}
        <div class="flex flex-1 @yield('panel-align', 'items-center') justify-center px-6 py-10 sm:px-10">
            <div class="w-full @yield('form-width', 'max-w-sm') float-in">
                @yield('content')
            </div>
        </div>

        {{-- Footer --}}
        <div class="px-6 py-5 text-center border-t border-slate-100">
            <p class="text-[11px] text-slate-400">
                © {{ date('Y') }} {{ $brandName }} ·
                <a href="/terms" class="hover:text-slate-600 underline underline-offset-2">Terms</a> ·
                <a href="/privacy" class="hover:text-slate-600 underline underline-offset-2">Privacy</a>
            </p>
        </div>
    </div>

</div>
</body>
</html>
