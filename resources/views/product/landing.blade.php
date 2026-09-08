@extends('layouts.product')

@push('styles')
<style>
    @@keyframes fade-up {
        from { opacity: 0; transform: translateY(18px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    @@keyframes fade-in {
        from { opacity: 0; }
        to   { opacity: 1; }
    }
    @@keyframes wave-bar {
        0%, 100% { transform: scaleY(0.3); }
        50%      { transform: scaleY(1); }
    }
    @@keyframes pulse-dot {
        0%, 100% { opacity: 1; transform: scale(1); }
        50%      { opacity: 0.4; transform: scale(0.85); }
    }
    @@keyframes flow-dash {
        from { stroke-dashoffset: 20; }
        to   { stroke-dashoffset: 0; }
    }

    .fade-up   { animation: fade-up 0.65s ease-out forwards; opacity: 0; }
    .fade-in   { animation: fade-in 0.5s ease-out forwards; opacity: 0; }
    .wave-bar  { animation: wave-bar 1.3s ease-in-out infinite; }
    .pulse-dot { animation: pulse-dot 1.6s ease-in-out infinite; }
    .flow-dash { animation: flow-dash 0.6s linear infinite; }
</style>
@endpush

@section('content')

    {{-- ============================================================
         HERO
    ============================================================ --}}
    <section class="pt-10 md:pt-14 pb-10">
        <div class="mx-auto max-w-7xl px-6">

            {{-- Headline --}}
            <div class="text-center">
                <h1 class="section-heading fade-up mx-auto max-w-4xl whitespace-pre-line text-[50px] font-bold leading-[1.05] tracking-[-0.025em] text-slate-900 md:text-[80px]" style="animation-delay:0.15s">
                    {{ config('product-page.hero.title') }}
                </h1>

                <p class="fade-up mx-auto mt-6 max-w-2xl text-[19px] leading-[30px] text-slate-500" style="animation-delay:0.25s">
                    {{ config('product-page.hero.subtitle') }}
                </p>

                <div class="fade-up mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row" style="animation-delay:0.35s">
                    <a href="{{ config('product-page.hero.cta_primary.href') }}"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-blue-600/25 hover:bg-blue-500 transition-all hover:scale-[1.02] hover:shadow-blue-500/30">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        {{ config('product-page.hero.cta_primary.label') }}
                    </a>
                    <a href="{{ config('product-page.hero.cta_secondary.href') }}"
                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-6 py-3.5 text-sm font-medium text-slate-700 hover:bg-slate-50 hover:border-slate-400 hover:text-slate-900 transition-all">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        {{ config('product-page.hero.cta_secondary.label') }}
                    </a>
                </div>

                {{-- Device availability strip --}}
                <div class="fade-up mt-6 flex flex-wrap items-center justify-center gap-x-5 gap-y-2 text-[12px] text-slate-400" style="animation-delay:0.45s">
                    <span class="flex items-center gap-1.5">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                        iOS & Android
                    </span>
                    <span class="h-1 w-1 rounded-full bg-slate-300"></span>
                    <span class="flex items-center gap-1.5">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        Web browser
                    </span>
                    <span class="h-1 w-1 rounded-full bg-slate-300"></span>
                    <span>No conference room hardware needed</span>
                </div>

            </div>

            {{-- CSS-only meeting dashboard mockup (stays dark — it's showing the app UI) --}}
            <div class="fade-up mt-14 relative mx-auto max-w-5xl" style="animation-delay:0.45s">
                {{-- Ambient shadow glow --}}
                <div class="absolute -inset-4 bg-blue-400/10 blur-3xl rounded-full pointer-events-none"></div>

                <div class="relative rounded-2xl border border-slate-200 bg-white p-1.5 shadow-2xl shadow-slate-300/50">
                    <div class="rounded-xl bg-[#0d1117] overflow-hidden">

                        {{-- Browser chrome --}}
                        <div class="border-b border-white/5 px-4 py-2.5 flex items-center gap-3">
                            <div class="flex gap-1.5">
                                <div class="h-2.5 w-2.5 rounded-full bg-red-500/40"></div>
                                <div class="h-2.5 w-2.5 rounded-full bg-amber-500/40"></div>
                                <div class="h-2.5 w-2.5 rounded-full bg-emerald-500/40"></div>
                            </div>
                            <div class="flex-1 rounded-md bg-white/5 px-3 py-1 text-center">
                                <span class="text-[11px] text-white/20 font-mono">app.xdataaudition.com / board-q1-review</span>
                            </div>
                        </div>

                        {{-- Meeting UI --}}
                        <div class="grid min-h-[320px] md:min-h-[400px]" style="grid-template-columns: 220px 1fr;">

                            {{-- Left sidebar: participants --}}
                            <div class="border-r border-white/5 p-4 hidden md:block">
                                <div class="text-[10px] font-semibold text-white/30 uppercase tracking-widest mb-3">Participants · 4</div>

                                @foreach ([
                                    ['K', 'Kwame A.',  'Organizer', 'from-amber-500/25 to-orange-500/15 text-amber-300'],
                                    ['S', 'Sarah C.',  'Secretary', 'from-blue-500/25 to-cyan-500/15 text-blue-300'],
                                    ['M', 'Marcus T.', 'Member',    'from-purple-500/25 to-violet-500/15 text-purple-300'],
                                    ['A', 'Adwoa B.',  'Member',    'from-emerald-500/25 to-teal-500/15 text-emerald-300'],
                                ] as [$initial, $name, $role, $gradient])
                                    <div class="flex items-center gap-2 mb-3">
                                        <div class="h-7 w-7 rounded-full bg-gradient-to-br {{ $gradient }} border border-white/10 flex items-center justify-center text-[11px] font-bold flex-shrink-0">{{ $initial }}</div>
                                        <div>
                                            <div class="text-[12px] text-white/70 font-medium leading-none mb-0.5">{{ $name }}</div>
                                            <div class="text-[10px] text-white/30">{{ $role }}</div>
                                        </div>
                                    </div>
                                @endforeach

                                <div class="mt-5 pt-4 border-t border-white/5">
                                    <div class="text-[10px] font-semibold text-white/30 uppercase tracking-widest mb-2">Duration</div>
                                    <div class="font-mono text-[22px] font-bold text-white/80 tracking-tight">47:23</div>
                                    <div class="flex items-center gap-1.5 mt-2">
                                        <div class="h-2 w-2 rounded-full bg-red-400 pulse-dot"></div>
                                        <span class="text-[11px] text-red-300 font-semibold">Recording</span>
                                    </div>
                                </div>

                                {{-- Audio waveform --}}
                                <div class="mt-5 flex items-end gap-[2px] h-8">
                                    @for ($i = 0; $i < 24; $i++)
                                        @php $pct = [20, 40, 65, 90, 75, 50, 85, 30, 95, 60, 45, 80, 35, 70, 55, 100, 40, 75, 25, 88, 50, 65, 30, 70][$i]; @endphp
                                        <div class="wave-bar flex-1 rounded-sm bg-blue-500/40 origin-bottom"
                                             style="height:{{ $pct }}%; animation-delay:{{ $i * 0.055 }}s;"></div>
                                    @endfor
                                </div>
                            </div>

                            {{-- Right panel: transcript --}}
                            <div class="p-5 overflow-hidden">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="text-[10px] font-semibold text-white/30 uppercase tracking-widest">Live Transcript</div>
                                    <div class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 px-2.5 py-1 text-[11px] text-emerald-300 font-semibold">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707" />
                                        </svg>
                                        AI Processing
                                    </div>
                                </div>

                                {{-- Transcript lines --}}
                                <div class="space-y-4">
                                    @foreach ([
                                        ['K', 'Kwame A.',  'from-amber-500/25 to-orange-500/15 text-amber-300', "Let's review the Q1 budget allocation. We need to finalise the numbers before the board meeting next Thursday."],
                                        ['S', 'Sarah C.',  'from-blue-500/25 to-cyan-500/15 text-blue-300',     "I've prepared the breakdown. Marketing is requesting a 15% increase for the digital campaign push this quarter."],
                                        ['M', 'Marcus T.', 'from-purple-500/25 to-violet-500/15 text-purple-300', "We should review the ROI from last quarter first. Conversion rates improved by 23%, so the case is strong."],
                                    ] as [$initial, $speaker, $gradient, $text])
                                        <div class="fade-in" style="animation-delay:{{ $loop->index * 0.15 + 0.5 }}s">
                                            <div class="flex items-center gap-2 mb-1">
                                                <div class="h-5 w-5 rounded-full bg-gradient-to-br {{ $gradient }} border border-white/10 flex items-center justify-center text-[10px] font-bold flex-shrink-0">{{ $initial }}</div>
                                                <span class="text-[11px] text-white/40 font-semibold">{{ $speaker }}</span>
                                            </div>
                                            <div class="ml-7 text-[13px] leading-relaxed text-white/65">{{ $text }}</div>
                                        </div>
                                    @endforeach
                                </div>

                                {{-- AI-generated output preview --}}
                                <div class="mt-5 rounded-xl bg-blue-600/8 border border-blue-500/20 p-4 fade-in" style="animation-delay:0.9s">
                                    <div class="flex items-center gap-2 mb-3">
                                        <div class="h-5 w-5 rounded-full bg-blue-500/20 border border-blue-500/30 flex items-center justify-center flex-shrink-0">
                                            <svg class="h-3 w-3 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                                                <path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/>
                                                <path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/>
                                            </svg>
                                        </div>
                                        <span class="text-[11px] text-blue-300 font-semibold uppercase tracking-wider">AI Action Items Detected</span>
                                    </div>
                                    <div class="space-y-2">
                                        @foreach ([
                                            ['Sarah C.',  'Share Q1 budget breakdown document by Friday EOD'],
                                            ['Marcus T.', 'Prepare ROI analysis for digital campaign spend'],
                                        ] as [$assignee, $task])
                                            <div class="flex items-start gap-2.5">
                                                <svg class="mt-0.5 h-4 w-4 text-emerald-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                                <span class="text-[12px] text-white/60">{{ $task }}
                                                    <span class="text-white/30"> → {{ $assignee }}</span>
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>

    {{-- ============================================================
         SOCIAL PROOF STRIP
    ============================================================ --}}
    <section class="border-y border-slate-200 py-10 mt-8">
        <div class="mx-auto max-w-7xl px-6">
            <div class="grid grid-cols-2 gap-8 md:grid-cols-4">
                @foreach ([
                    ['10,000+',  'Meetings processed'],
                    ['500+',     'Organizations'],
                    ['< 5 min',  'Avg. minutes generation'],
                    ['99%',      'Transcript accuracy'],
                ] as [$stat, $label])
                    <div class="text-center">
                        <div class="section-heading text-2xl font-bold text-slate-900 md:text-3xl">{{ $stat }}</div>
                        <div class="mt-1 text-sm text-slate-400">{{ $label }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ============================================================
         HOW IT WORKS — animated pipeline
    ============================================================ --}}
    <section class="bg-slate-50 py-20 md:py-28">
        <div class="mx-auto max-w-7xl px-6">

            {{-- Section header --}}
            <div class="text-center mb-16">
                <div class="inline-flex items-center rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-xs font-medium text-blue-600 mb-5">
                    {{ config('product-page.intro.eyebrow') }}
                </div>
                <h2 class="section-heading text-[36px] font-bold leading-tight tracking-tight text-slate-900 md:text-[46px]">
                    {{ config('product-page.intro.title') }}
                </h2>
                <p class="mx-auto mt-5 max-w-2xl text-[17px] leading-[28px] text-slate-500">
                    {{ config('product-page.intro.body') }}
                </p>
            </div>

            {{-- Animated pipeline (desktop: horizontal) --}}
            <div class="hidden md:flex items-start justify-center gap-0 mb-16">
                @foreach ([
                    ['🎫', '01', 'Create',    'Page, schedule, tickets',    'from-blue-500/15 to-blue-600/5',   'border-blue-200'],
                    ['💳', '02', 'Sell',      'Cards and mobile money',     'from-violet-500/15 to-violet-600/5','border-violet-200'],
                    ['📲', '03', 'Check in',  'Scan guests at the door',    'from-emerald-500/15 to-emerald-600/5','border-emerald-200'],
                    ['📊', '04', 'Get paid',  'Statement &amp; payout',     'from-amber-500/15 to-amber-600/5', 'border-amber-200'],
                ] as [$icon, $num, $title, $sub, $gradient, $border])
                    {{-- Node --}}
                    <div class="flex flex-col items-center text-center w-44">
                        <div class="relative mb-4">
                            <div class="h-[72px] w-[72px] rounded-2xl bg-gradient-to-br {{ $gradient }} border {{ $border }} flex items-center justify-center text-[28px] shadow-sm">
                                {!! $icon !!}
                            </div>
                            <div class="absolute -top-2 -right-2 h-5 w-5 rounded-full bg-white border border-slate-200 flex items-center justify-center">
                                <span class="text-[9px] font-bold text-slate-500">{{ $num }}</span>
                            </div>
                        </div>
                        <div class="text-[14px] font-semibold text-slate-800">{{ $title }}</div>
                        <div class="mt-1 text-[12px] text-slate-400 leading-snug">{!! $sub !!}</div>
                    </div>

                    {{-- SVG dash connector (between nodes, not after last) --}}
                    @if (! $loop->last)
                        <div class="flex-none flex items-start pt-9">
                            <svg width="56" height="24" viewBox="0 0 56 24" fill="none">
                                <path d="M0 12 L44 12" stroke="#94a3b8" stroke-width="1.5"
                                      stroke-dasharray="5 3.5" stroke-linecap="round"
                                      class="flow-dash"/>
                                <path d="M44 7 L54 12 L44 17" stroke="#94a3b8" stroke-width="1.5"
                                      stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                    @endif
                @endforeach
            </div>

            {{-- Mobile pipeline (vertical) --}}
            <div class="flex md:hidden flex-col items-center mb-14 gap-0">
                @foreach ([
                    ['🎫', '01', 'Create',    'Page, schedule, tickets',  'from-blue-500/15 to-blue-600/5',    'border-blue-200'],
                    ['💳', '02', 'Sell',      'Cards and mobile money',   'from-violet-500/15 to-violet-600/5','border-violet-200'],
                    ['📲', '03', 'Check in',  'Scan guests at the door',  'from-emerald-500/15 to-emerald-600/5','border-emerald-200'],
                    ['📊', '04', 'Get paid',  'Statement and payout',     'from-amber-500/15 to-amber-600/5',  'border-amber-200'],
                ] as [$icon, $num, $title, $sub, $gradient, $border])
                    <div class="flex items-center gap-4">
                        <div class="relative flex-none">
                            <div class="h-14 w-14 rounded-2xl bg-gradient-to-br {{ $gradient }} border {{ $border }} flex items-center justify-center text-2xl shadow-sm">
                                {{ $icon }}
                            </div>
                            <div class="absolute -top-1.5 -right-1.5 h-4 w-4 rounded-full bg-white border border-slate-200 flex items-center justify-center">
                                <span class="text-[9px] font-bold text-slate-500">{{ $num }}</span>
                            </div>
                        </div>
                        <div>
                            <div class="text-[14px] font-semibold text-slate-800">{{ $title }}</div>
                            <div class="text-[12px] text-slate-400">{{ $sub }}</div>
                        </div>
                    </div>
                    @if (! $loop->last)
                        <svg width="24" height="28" viewBox="0 0 24 28" fill="none" class="ml-7">
                            <path d="M12 0 L12 20" stroke="#94a3b8" stroke-width="1.5"
                                  stroke-dasharray="4 3" stroke-linecap="round"
                                  class="flow-dash"/>
                            <path d="M7 18 L12 26 L17 18" stroke="#94a3b8" stroke-width="1.5"
                                  stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    @endif
                @endforeach
            </div>

            {{-- Step cards (detail) --}}
            <div class="grid gap-5 md:grid-cols-3">
                @foreach (config('product-page.intro.cards') as $c)
                    <div class="flex gap-4 rounded-2xl border border-slate-200 bg-white p-5 hover:shadow-md hover:border-slate-300 transition-all shadow-sm">
                        <div class="h-9 w-9 flex-shrink-0 rounded-xl bg-blue-50 border border-blue-100 flex items-center justify-center text-sm font-bold font-mono text-blue-600">
                            0{{ $loop->index + 1 }}
                        </div>
                        <div>
                            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-widest mb-1">{{ $c['kicker'] }}</div>
                            <div class="text-[15px] font-semibold text-slate-800">{{ $c['title'] }}</div>
                            <div class="mt-1 text-[13px] leading-[21px] text-slate-500">{{ $c['body'] }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

        </div>
    </section>

    {{-- ============================================================
         CAPABILITIES GRID
    ============================================================ --}}
    <section class="py-20 md:py-28">
        <div class="mx-auto max-w-7xl px-6">

            <div class="text-center mb-12">
                <h2 class="section-heading text-[36px] font-bold leading-tight tracking-tight text-slate-900 md:text-[46px]">
                    {{ config('product-page.capabilities.title') }}
                </h2>
                <p class="mx-auto mt-4 max-w-2xl text-[17px] leading-[27px] text-slate-500">
                    {{ config('product-page.capabilities.subtitle') }}
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (config('product-page.capabilities.items') as $it)
                    <div class="group rounded-2xl border border-slate-200 bg-white p-5 hover:bg-slate-50 hover:border-slate-300 transition-all shadow-sm">
                        <div class="mb-4 h-10 w-10 rounded-xl bg-blue-50 border border-blue-100 flex items-center justify-center text-xl">
                            {{ $it['icon'] }}
                        </div>
                        <div class="text-[15px] font-semibold text-slate-800 mb-1.5">{{ $it['title'] }}</div>
                        <div class="text-[13px] leading-[21px] text-slate-500">{{ $it['body'] }}</div>
                    </div>
                @endforeach
            </div>

        </div>
    </section>

    {{-- ============================================================
         DEEP SECTIONS
    ============================================================ --}}
    @foreach (config('product-page.deep_sections') as $sec)
        <section class="bg-slate-50 py-20 md:py-28">
            <div class="mx-auto max-w-7xl px-6">
                <div class="grid gap-10 lg:grid-cols-2 lg:items-center">

                    {{-- Text side --}}
                    <div>
                        <div class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-medium text-emerald-700">
                            {{ $sec['eyebrow'] }}
                        </div>
                        <h2 class="section-heading mt-5 max-w-xl text-[36px] font-bold leading-tight tracking-tight text-slate-900 md:text-[46px]">
                            {{ $sec['title'] }}
                        </h2>
                        <p class="mt-5 max-w-xl text-[17px] leading-[28px] text-slate-500">
                            {{ $sec['body'] }}
                        </p>
                        <div class="mt-8 space-y-3">
                            @foreach ($sec['features'] as $f)
                                <div class="flex gap-3.5 rounded-2xl border border-slate-200 bg-white p-4 hover:bg-slate-50 transition-all shadow-sm">
                                    <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <div>
                                        @if (isset($f['kicker']))
                                            <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-0.5">{{ $f['kicker'] }}</div>
                                        @endif
                                        <div class="text-[14px] font-semibold text-slate-800">{{ $f['title'] }}</div>
                                        <div class="mt-0.5 text-[13px] leading-[20px] text-slate-500">{{ $f['body'] }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- CSS governance visualization (stays dark for contrast) --}}
                    <div class="rounded-2xl border border-slate-800 bg-[#0d1117] p-6 shadow-xl">
                        <div class="text-[10px] font-semibold text-white/25 uppercase tracking-widest mb-5">Meeting Governance · Audit Trail</div>

                        <div class="space-y-0">
                            @foreach ([
                                ['emerald', 'Decision recorded',    'Budget increase approved in Q1 review',       '12:34 PM'],
                                ['blue',    'Action item created',  'Prepare Q2 forecast → Sarah C.',              '12:36 PM'],
                                ['purple',  'Minutes generated',    'AI-generated minutes ready for review',       '01:18 PM'],
                                ['amber',   'Minutes signed',       'Approved & signed by Board Secretary',        '02:05 PM'],
                            ] as [$color, $event, $detail, $time])
                                <div class="flex gap-3">
                                    <div class="flex flex-col items-center pt-1.5">
                                        <div class="h-2.5 w-2.5 rounded-full bg-{{ $color }}-400 flex-shrink-0 ring-4 ring-{{ $color }}-400/10"></div>
                                        @if (! $loop->last)
                                            <div class="w-px flex-1 bg-white/6 my-1" style="min-height:24px;"></div>
                                        @endif
                                    </div>
                                    <div class="pb-5">
                                        <div class="text-[13px] font-semibold text-white/80">{{ $event }}</div>
                                        <div class="text-[12px] text-white/40 mt-0.5">{{ $detail }}</div>
                                        <div class="text-[11px] text-white/22 mt-0.5 font-mono">{{ $time }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="flex flex-wrap gap-2 pt-4 border-t border-white/5">
                            @foreach ([
                                ['emerald', 'Per-organization data isolation'],
                                ['blue',    'Role-based access'],
                                ['purple',  'Activity logging'],
                                ['amber',   'Encrypted payment credentials'],
                            ] as [$color, $label])
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-{{ $color }}-500/10 border border-{{ $color }}-500/20 px-2.5 py-1 text-[11px] text-{{ $color }}-300 font-medium">
                                    <span class="h-1.5 w-1.5 rounded-full bg-{{ $color }}-400"></span>{{ $label }}
                                </span>
                            @endforeach
                        </div>
                    </div>

                </div>
            </div>
        </section>
    @endforeach

    {{-- ============================================================
         PRICING (Livewire component)
    ============================================================ --}}
    <livewire:pricing-table />

    {{-- ============================================================
         TESTIMONIALS (only rendered once there are real customer quotes)
    ============================================================ --}}
    @if (! empty(config('product-page.testimonials')))
        <section class="bg-slate-50 py-20 md:py-28">
            <div class="mx-auto max-w-7xl px-6">

                <div class="text-center mb-12">
                    <div class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-700 mb-4">
                        Customer Stories
                    </div>
                    <h2 class="section-heading text-[36px] font-bold leading-tight tracking-tight text-slate-900 md:text-[44px]">
                        Trusted by teams who value their time
                    </h2>
                </div>

                <div class="grid gap-6 md:grid-cols-2">
                    @foreach (config('product-page.testimonials') as $t)
                        <div class="rounded-2xl border border-slate-200 bg-white px-7 py-8 hover:bg-slate-50 hover:border-slate-300 transition-all shadow-sm">
                            {{-- Stars --}}
                            <div class="flex gap-1 mb-5">
                                @for ($i = 0; $i < 5; $i++)
                                    <svg class="h-4 w-4 text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                    </svg>
                                @endfor
                            </div>
                            <p class="text-[17px] leading-[27px] text-slate-600 italic">
                                "{{ $t['quote'] }}"
                            </p>
                            <div class="mt-6 flex items-center gap-3">
                                <div class="h-10 w-10 rounded-full bg-gradient-to-br from-blue-100 to-emerald-100 border border-slate-200 flex items-center justify-center text-sm font-bold text-slate-700">
                                    {{ strtoupper(substr($t['name'], 0, 1)) }}
                                </div>
                                <div>
                                    <div class="text-sm font-semibold text-slate-800">{{ $t['name'] }}</div>
                                    <div class="text-xs text-slate-400">{{ $t['role'] }} · {{ $t['company'] }}</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

            </div>
        </section>
    @endif

    {{-- ============================================================
         FAQS
    ============================================================ --}}
    <section class="py-20 md:py-28">
        <div class="mx-auto max-w-7xl px-6">

            <div class="text-center mb-12">
                <h2 class="section-heading text-[36px] font-bold leading-tight tracking-tight text-slate-900 md:text-[44px]">
                    Frequently asked questions
                </h2>
            </div>

            <div class="mx-auto max-w-3xl space-y-3">
                @foreach (config('product-page.faqs') as $faq)
                    <details class="group rounded-2xl border border-slate-200 bg-white p-5 open:bg-slate-50 open:border-slate-300 transition-all shadow-sm">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4">
                            <span class="text-[15px] font-medium text-slate-800">{{ $faq['q'] }}</span>
                            <span class="grid h-7 w-7 flex-shrink-0 place-items-center rounded-lg border border-slate-200 bg-slate-100 text-slate-400 group-open:rotate-45 transition-transform duration-200">+</span>
                        </summary>
                        <div class="mt-3 pr-10 text-[14px] leading-[23px] text-slate-500">
                            {{ $faq['a'] }}
                        </div>
                    </details>
                @endforeach
            </div>

        </div>
    </section>

    {{-- ============================================================
         FINAL CTA — rich blue for contrast on the light page
    ============================================================ --}}
    <section class="pb-24 pt-8">
        <div class="mx-auto max-w-7xl px-6">
            <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-blue-700 via-blue-800 to-slate-900 p-10 text-center md:p-16">

                {{-- Radial glow --}}
                <div class="pointer-events-none absolute inset-0">
                    <div class="absolute top-0 left-1/2 -translate-x-1/2 h-40 w-96 bg-blue-400/30 blur-3xl rounded-full"></div>
                </div>

                <h2 class="section-heading relative mx-auto max-w-3xl text-[34px] font-bold leading-tight tracking-tight text-white md:text-[48px]">
                    {{ config('product-page.final_cta.title') }}
                </h2>
                <p class="relative mx-auto mt-4 max-w-2xl text-[17px] leading-[28px] text-blue-100">
                    {{ config('product-page.final_cta.subtitle') }}
                </p>

                <div class="relative mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <a href="{{ config('product-page.final_cta.cta_primary.href') }}"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-white px-7 py-3.5 text-sm font-bold text-blue-900 shadow-lg hover:bg-blue-50 transition-all hover:scale-[1.02]">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        {{ config('product-page.final_cta.cta_primary.label') }}
                    </a>
                    <a href="{{ config('product-page.final_cta.cta_secondary.href') }}"
                        class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/25 bg-white/10 px-7 py-3.5 text-sm font-medium text-white/85 hover:bg-white/15 hover:text-white transition-all">
                        {{ config('product-page.final_cta.cta_secondary.label') }}
                    </a>
                </div>

                <p class="relative mt-5 text-xs text-blue-200/60">No credit card required · Free plan available · Cancel anytime</p>
            </div>
        </div>
    </section>

@endsection
