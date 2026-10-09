@extends('layouts.product')

@section('title', config('product-page.brand.name').' — run your whole event from one place')

@push('styles')
<style>
    /* ── Field ───────────────────────────────────────────────────────── */
    .field {
        background: var(--field);
        background-image:
            radial-gradient(120% 90% at 50% -20%, #3b7bff 0%, rgba(59,123,255,0) 62%),
            radial-gradient(70% 60% at 88% 4%, rgba(203,242,251,.18) 0%, rgba(203,242,251,0) 70%);
        position: relative; overflow: hidden;
    }
    .field::before {
        content: ""; position: absolute; inset: 0;
        background-image:
            linear-gradient(rgba(255,255,255,.06) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,.06) 1px, transparent 1px);
        background-size: 60px 60px;
        mask-image: radial-gradient(115% 85% at 50% 25%, #000 32%, transparent 80%);
        pointer-events: none;
    }
    .field > * { position: relative; }

    .hero-title {
        font-size: clamp(2.5rem, 6.2vw, 72px);
        line-height: 1.056;
        font-weight: 500;
        letter-spacing: -0.02em;
        color: #fff; text-wrap: balance; margin: 0;
    }
    .hero-title em { font-style: normal; color: var(--ice); }
    .hero-sub {
        margin: 1.6rem auto 0; max-width: 38rem;
        color: #fff; font-size: 16px; line-height: 24px; font-weight: 300;
    }

    .btn-solid {
        display: inline-flex; align-items: center; background: #fff; color: var(--field-deep);
        font-weight: 500; padding: .8rem 1.7rem; border-radius: .6rem;
        box-shadow: 0 12px 34px -14px rgba(0,0,0,.55); transition: transform .15s ease;
    }
    .btn-solid:hover { transform: translateY(-1px); }
    .btn-ghost {
        display: inline-flex; align-items: center; color: #c4d8ff; font-weight: 400;
        padding: .85rem 1.1rem; border-radius: .75rem; border: 1px solid rgba(255,255,255,.24);
    }
    .btn-ghost:hover { color: #fff; border-color: rgba(255,255,255,.45); }

    /* ── Product surface ──────────────────────────────────────────────
       One window, centred, cut by the fold — the reader sees the product
       before they finish reading the page. */
    .shot {
        background: var(--surface); border-radius: 12px; text-align: left;
        box-shadow: 0 50px 90px -32px rgba(0,40,28,.6), 0 0 0 1px rgba(255,255,255,.14);
        overflow: hidden;
    }
    .shot-head {
        display: flex; align-items: center; gap: .45rem;
        padding: .65rem .85rem; border-bottom: 1px solid var(--rule); background: #fbfcfc;
    }
    .shot-head i { width: .58rem; height: .58rem; border-radius: 999px; background: #dfe6e4; display: block; }
    .shot-head .addr {
        margin-left: .55rem; font-size: .71rem; color: var(--muted);
        background: #f1f4f3; border-radius: 5px; padding: .18rem .55rem;
    }
    .shot-body { padding: 1.4rem 1.5rem 1.6rem; }

    /* Stat tiles, ruled rather than carded — as the product draws them. */
    .tiles { display: grid; grid-template-columns: repeat(4, 1fr); border: 1px solid var(--rule); }
    .tiles > div { padding: .95rem 1.1rem; border-left: 1px solid var(--rule); }
    .tiles > div:first-child { border-left: none; }
    .tile-k { font-size: .78rem; color: var(--muted); }
    .tile-v { font-size: 1.5rem; font-weight: 500; margin-top: .2rem; letter-spacing: -.015em; }
    .tile-s { font-size: .72rem; color: var(--muted); margin-top: .15rem; }
    @media (max-width: 760px) {
        .tiles { grid-template-columns: repeat(2, 1fr); }
        .tiles > div:nth-child(3) { border-left: none; }
        .tiles > div:nth-child(n+3) { border-top: 1px solid var(--rule); }
    }

    .bars { display: flex; align-items: flex-end; gap: 4px; height: 118px; }
    .bars span { flex: 1; background: #93b4f0; border-radius: 2px 2px 0 0; display: block; }

    .row { display: flex; align-items: center; justify-content: space-between; padding: .6rem 0; border-bottom: 1px solid var(--rule); }
    .row:last-child { border-bottom: none; }
    .tick { width: 1.15rem; height: 1.15rem; border-radius: 3px; background: #e7f4ef; color: var(--go); display: grid; place-items: center; font-size: .68rem; font-weight: 700; }
    .tick.no { background: #fdeceb; color: var(--danger); }
    .chip { font-size: .68rem; padding: .1rem .45rem; border: 1px solid currentColor; border-radius: 4px; }

    /* ── Paper ────────────────────────────────────────────────────────── */
    .paper { background: var(--paper); }
    .h2 { font-size: clamp(1.7rem, 3vw, 2.35rem); font-weight: 500; line-height: 1.18; letter-spacing: -.018em; text-wrap: balance; }
    .lede { color: var(--ink-2); font-size: 1.02rem; line-height: 1.6; font-weight: 300; max-width: 40rem; }

    .arc { display: grid; grid-template-columns: repeat(4, 1fr); }
    @media (max-width: 880px) { .arc { grid-template-columns: 1fr; } }
    .arc-step { padding: 1.5rem 1.35rem; border-left: 2px solid var(--rule); }
    .arc-step:first-child { border-left-color: var(--field); }
    .arc-n { font-size: .74rem; color: var(--field); font-weight: 500; }
    .arc-t { font-weight: 500; font-size: 1.06rem; margin-top: .3rem; }
    .arc-b { color: var(--ink-2); font-size: .94rem; margin-top: .3rem; }

    .panel { background: var(--surface); border: 1px solid var(--rule); border-radius: 12px; overflow: hidden; }
    .panel-head { display: flex; align-items: center; justify-content: space-between; padding: .85rem 1.1rem; border-bottom: 1px solid var(--rule); background: #fbfcfc; }

    .cap { display: grid; gap: 1px; background: var(--rule); border: 1px solid var(--rule); border-radius: 12px; overflow: hidden; grid-template-columns: repeat(3, 1fr); }
    @media (max-width: 900px) { .cap { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 580px) { .cap { grid-template-columns: 1fr; } }
    .cap > div { background: var(--surface); padding: 1.45rem 1.35rem; }
    .cap h3 { font-weight: 500; font-size: 1rem; }
    .cap p { color: var(--ink-2); font-size: .92rem; margin-top: .3rem; }

    a:focus-visible, button:focus-visible, summary:focus-visible { outline: 3px solid var(--ice); outline-offset: 2px; }
    [data-hero-showcase] [hidden] { display:none!important; }
    [data-hero-showcase] .mc-reference-tabs { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); margin-bottom:22px; border-top:1px solid #ffffff35; border-bottom:1px solid #ffffff35; text-align:left; }
    [data-hero-showcase] .mc-reference-tab { position:relative; padding:20px 24px; font:500 15px 'Public Sans',sans-serif; color:#d7e5ff; background:transparent; border:0; border-left:1px solid #ffffff35; min-height:64px; text-align:left; }
    [data-hero-showcase] .mc-reference-tab:first-child { border-left:0; }
    [data-hero-showcase] .mc-reference-tab::after { content:''; position:absolute; left:0; right:0; bottom:-1px; height:2px; background:#ffffff55; }
    [data-hero-showcase] .mc-reference-tab[aria-selected="true"] { color:#fff; background:#ffffff0a; }
    [data-hero-showcase] .mc-reference-tab[aria-selected="true"]::after { background:#fff; }
    [data-hero-showcase] .mc-reference-tab:focus-visible { outline:3px solid #cbf2fb; outline-offset:-3px; }
    [data-hero-showcase] .shot-body { display:grid; }
    [data-hero-showcase] .mc-product-slide { grid-area:1 / 1; min-width:0; min-height:430px; }
    [data-hero-showcase] .mc-product-slide[hidden] { display:block!important; visibility:hidden; pointer-events:none; }
    [data-hero-showcase] .mc-slide-title { font-size:19px; font-weight:500; margin:0; }
    [data-hero-showcase] .mc-slide-sub { font-size:13px; line-height:1.6; color:#45505e; margin:4px 0 20px; }
    [data-hero-showcase] .mc-showcase-columns { display:grid; grid-template-columns:1.05fr 1fr; gap:22px; }
    [data-hero-showcase] .mc-demo-pane { border:1px solid #e3e8ee; border-radius:8px; padding:18px; background:#fff; }
    [data-hero-showcase] .mc-demo-pane h3 { font-size:14px; font-weight:500; margin:0 0 14px; }
    [data-hero-showcase] .mc-demo-row { padding:12px 0; border-top:1px solid #e3e8ee; display:flex; justify-content:space-between; align-items:center; gap:12px; font-size:13px; }
    [data-hero-showcase] .mc-demo-row small { display:block; font-size:11px; color:#78838f; margin-top:4px; }
    [data-hero-showcase] .mc-demo-state { font-size:11px; color:#155dfc; background:#eef3ff; padding:4px 6px; border-radius:4px; white-space:nowrap; }
    [data-hero-showcase] .mc-artifact-pair { display:grid; grid-template-columns:.8fr 1.2fr; gap:14px; align-items:center; }
    [data-hero-showcase] .mc-demo-badge { border:1px solid #e3e8ee; border-radius:7px; overflow:hidden; text-align:center; background:#f6f8fa; }
    [data-hero-showcase] .mc-demo-badge header { padding:16px 10px; color:#fff; background:#155dfc; font-size:11px; }
    [data-hero-showcase] .mc-demo-badge strong { display:block; margin:18px 8px 4px; font-size:16px; font-weight:500; }
    [data-hero-showcase] .mc-demo-badge p { color:#78838f; font-size:11px; margin-bottom:14px; }
    [data-hero-showcase] .mc-demo-badge svg { width:48px!important; height:48px!important; margin:0 auto 18px; color:#12161c; }
    [data-hero-showcase] .mc-demo-certificate { border:1px solid #e3e8ee; padding:28px 12px; text-align:center; background:#fff; }
    [data-hero-showcase] .mc-demo-certificate small { font-size:10px; color:#78838f; }
    [data-hero-showcase] .mc-demo-certificate h3 { font-size:15px; font-weight:500; margin:20px 0 14px; }
    [data-hero-showcase] .mc-demo-certificate strong { font-size:15px; font-weight:500; }
    [data-hero-showcase] .mc-demo-certificate p { font-size:11px; color:#78838f; margin:10px 0; }
    @media(max-width:640px) { [data-hero-showcase] .mc-reference-tab { padding:16px 12px; font-size:13px; min-height:58px; } [data-hero-showcase] .mc-showcase-columns { grid-template-columns:1fr; gap:16px; } [data-hero-showcase] .mc-product-slide { min-height:610px; } [data-hero-showcase] .mc-demo-pane { padding:14px; } }

</style>
@endpush

@section('content')

{{-- ══ HERO ══════════════════════════════════════════════════════════ --}}
<section class="field">
    <div class="mx-auto max-w-7xl px-6 pt-24 text-center md:pt-32">

        <h1 class="hero-title display">
            Run the whole event<br><em>from one place</em>
        </h1>

        <p class="hero-sub">
            Sell the tickets, run the door with your own crew’s phones, answer guests while
            the event is on, and see exactly what you earned. One tool instead of five.
        </p>

        <div class="mt-9 flex flex-wrap items-center justify-center gap-3">
            <a href="{{ route('register', ['plan' => 'free']) }}" class="btn-solid">Start free</a>
            <a href="#pricing" class="btn-ghost">See pricing</a>
        </div>

        <p class="mt-5 text-sm" style="color:#b7cdf7; font-weight:300">One live event at a time and 50 registrations a month. No card required.</p>

        <div class="mx-auto mt-14 max-w-5xl" data-hero-showcase>
            <div class="mc-reference-tabs" role="tablist" aria-label="Event feature showcase" hidden>
                <button type="button" class="mc-reference-tab" role="tab" id="hero-tab-plan" aria-controls="hero-panel-plan" aria-selected="false" tabindex="-1">Plan your event</button>
                <button type="button" class="mc-reference-tab" role="tab" id="hero-tab-run" aria-controls="hero-panel-run" aria-selected="true" tabindex="0">Run the day</button>
                <button type="button" class="mc-reference-tab" role="tab" id="hero-tab-finish" aria-controls="hero-panel-finish" aria-selected="false" tabindex="-1">Follow through</button>
            </div>
            <div class="shot">
                <div class="shot-head">
                    <i></i><i></i><i></i>
                    <span class="addr mono">accra-tech-week.miconvener.com/events/summit</span>
                </div>
                <div class="shot-body">
                    <div class="mc-product-slide" id="hero-panel-plan" role="tabpanel" aria-labelledby="hero-tab-plan" tabindex="0" hidden>
                        <h2 class="mc-slide-title">Prepare the whole event</h2>
                        <p class="mc-slide-sub">Event pages, registration, payments, programme, speakers and your event team.</p>
                        <div class="mc-showcase-columns">
                            <div class="mc-demo-pane">
                                <h3>Programme · Demo summit</h3>
                                <div class="mc-demo-row">
                                    <div>
                                        Opening keynote
                                        <small>09:00 · Main hall · Speaker confirmed</small>
                                    </div>
                                    <span class="mc-demo-state">Ready</span>
                                </div>
                                <div class="mc-demo-row">
                                    <div>
                                        Research presentations
                                        <small>10:30 · Breakout room · Abstracts reviewed</small>
                                    </div>
                                    <span class="mc-demo-state">Ready</span>
                                </div>
                                <div class="mc-demo-row">
                                    <div>
                                        Practical workshop
                                        <small>13:00 · Studio · Materials available</small>
                                    </div>
                                    <span class="mc-demo-state">Ready</span>
                                </div>
                            </div>
                            <div class="mc-demo-pane">
                                <h3>Everything around the programme</h3>
                                <div class="mc-demo-row">
                                    <div>
                                        Registration & tickets
                                        <small>Forms, groups, ticket tiers and promo codes</small>
                                    </div>
                                </div>
                                <div class="mc-demo-row">
                                    <div>
                                        Venue & seating
                                        <small>Rooms, facilities and seating plans</small>
                                    </div>
                                </div>
                                <div class="mc-demo-row">
                                    <div>
                                        Team & sponsors
                                        <small>Crew responsibilities and event partners</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mc-product-slide" id="hero-panel-run" role="tabpanel" aria-labelledby="hero-tab-run" tabindex="0">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="display text-[19px]">Overview</div>
                            <div class="text-[13px]" style="color:var(--muted)">Check-in, live polls, Q&amp;A, discussions and guest assistance in one workspace.</div>
                        </div>
                        <div class="hidden rounded-md border px-3 py-1.5 text-[12px] sm:block" style="border-color:var(--rule); color:var(--ink-2)">Export day report</div>
                    </div>

                    <div class="tiles mt-4">
                        @foreach ([
                            ['Registered', '587', 'of 640 capacity', false],
                            ['Confirmed', '541', '46 awaiting payment', false],
                            ['Checked in today', '388', '72% of confirmed', true],
                            ['Collected', 'GHS 412,400', 'GHS 371,160 settles to you', false],
                        ] as [$k, $v, $sub, $accent])
                            <div>
                                <div class="tile-k">{{ $k }}</div>
                                <div class="tile-v {{ $accent ? '' : '' }} {{ str_starts_with($v, 'GHS') ? 'mono' : '' }}"
                                     style="{{ $accent ? 'color:var(--go)' : '' }}">{{ $v }}</div>
                                <div class="tile-s">{{ $sub }}</div>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div class="rounded-lg border p-4" style="border-color:var(--rule)">
                            <div class="flex items-baseline justify-between">
                                <div class="text-[13px] font-medium">Arrivals through the gates</div>
                                <div class="text-[11px]" style="color:var(--muted)">08:00 to 11:00</div>
                            </div>
                            <div class="bars mt-4">
                                @foreach ([6,11,18,26,38,54,72,88,80,58,40,30,26,28,36,34,22,17,13] as $h)
                                    <span style="height: {{ $h }}%"></span>
                                @endforeach
                            </div>
                            <div class="mt-2 flex justify-between text-[11px] mono" style="color:var(--muted)">
                                <span>08:00</span><span>09:30</span><span>11:00</span>
                            </div>
                        </div>

                        <div class="rounded-lg border p-4" style="border-color:var(--rule)">
                            <div class="flex items-baseline justify-between">
                                <div class="text-[13px] font-medium">Needs a person</div>
                                <div class="text-[11px]" style="color:var(--muted)">Longest waiting first</div>
                            </div>
                            <div class="mt-2">
                                @foreach ([
                                    ['6m', 'Water at table 14', 'Grand Ballroom, raised by Comfort Adjei', 'Open', 'var(--amber)'],
                                    ['4m', 'Projector not showing laptop', 'Volta Room, raised by Kwame Asare', 'Technical', 'var(--danger)'],
                                    ['2m', 'Wheelchair access to stage', 'Grand Ballroom, raised by front desk', 'Open', 'var(--amber)'],
                                ] as [$age, $title, $where, $state, $tone])
                                    <div class="row">
                                        <div class="flex min-w-0 items-start gap-3">
                                            <span class="mono text-[11px] pt-0.5" style="color:var(--amber)">{{ $age }}</span>
                                            <span class="min-w-0">
                                                <span class="block truncate text-[13px] font-medium">{{ $title }}</span>
                                                <span class="block truncate text-[11px]" style="color:var(--muted)">{{ $where }}</span>
                                            </span>
                                        </div>
                                        <span class="chip shrink-0" style="color:{{ $tone }}">{{ $state }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    </div>
                    <div class="mc-product-slide" id="hero-panel-finish" role="tabpanel" aria-labelledby="hero-tab-finish" tabindex="0" hidden>
                        <h2 class="mc-slide-title">Give people something to take away</h2>
                        <p class="mc-slide-sub">Your badge and certificate designs, attendee records, feedback and event finances.</p>
                        <div class="mc-showcase-columns">
                            <div class="mc-artifact-pair">
                                <div class="mc-demo-badge">
                                    <header>Accra Tech Week</header>
                                    <strong>Ama Mensah</strong>
                                    <p>Speaker · Main hall</p>
                                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true">
                                        <path
                                            d="M4 4h14v14H4zM30 4h14v14H30zM4 30h14v14H4zM10 10h2v2h-2zM36 10h2v2h-2zM10 36h2v2h-2zM24 4v8M24 18v12h8v-6h12M24 36v8h8M38 32h6v12h-6M32 36v-4"
                                        />
                                    </svg>
                                </div>
                                <div class="mc-demo-certificate">
                                    <small>Accra Tech Week</small>
                                    <h3>Certificate of participation</h3>
                                    <p>Presented to</p>
                                    <strong>Ama Mensah</strong>
                                    <p>A record of your participation</p>
                                </div>
                            </div>
                            <div class="mc-demo-pane">
                                <h3>Close the loop</h3>
                                <div class="mc-demo-row">
                                    <div>
                                        Badges & certificates
                                        <small>Your fonts, artwork, logo and QR placement</small>
                                    </div>
                                </div>
                                <div class="mc-demo-row">
                                    <div>
                                        Attendee portal & feedback
                                        <small>Tickets, certificates, receipts and surveys</small>
                                    </div>
                                </div>
                                <div class="mc-demo-row">
                                    <div>
                                        Reports & settlement
                                        <small>Contributions, refunds, statements and payouts</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="h-16 md:h-24"></div>
    </div>
</section>

{{-- ══ ARC ═══════════════════════════════════════════════════════════ --}}
<section class="paper border-y" style="border-color:var(--rule)">
    <div class="mx-auto max-w-7xl px-6 py-16 md:py-20">
        <h2 class="h2 display">{{ config('product-page.intro.title') }}</h2>
        <p class="lede mt-3">{{ config('product-page.intro.body') }}</p>
        <div class="arc mt-10">
            @foreach ([
                ['Create', 'Build the page', 'Schedule, speakers and ticket types on one link you can share anywhere.'],
                ['Sell', 'Take payments', 'Card and mobile money, with a QR ticket the moment payment clears. Bank transfer and MoMo slips you approve by hand.'],
                ['Run', 'Run the day', 'Scan guests in, answer their requests, run live polls and Q&A, and keep the forum going.'],
                ['Settle', 'Get paid', 'One statement per event, then a payout to your bank or mobile money.'],
            ] as $i => [$step, $title, $body])
                <div class="arc-step">
                    <div class="arc-n mono">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }} · {{ $step }}</div>
                    <div class="arc-t">{{ $title }}</div>
                    <div class="arc-b">{{ $body }}</div>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ══ CHECK-IN ══════════════════════════════════════════════════════ --}}
<section>
    <div class="mx-auto max-w-7xl px-6 py-16 md:py-24">
        <div class="grid items-center gap-12 lg:grid-cols-[0.85fr_1.15fr]">
            <div>
                <h2 class="h2 display">Your crew scans<br>from their own phones.</h2>
                <p class="lede mt-4">
                    Send each usher a staff link on WhatsApp. It opens the scanner on their phone:
                    no app, no account, no team seat. It stops working when the event ends.
                </p>
                <ul class="mt-6 space-y-3">
                    @foreach ([
                        'Works on any phone, so there is no scanner hardware to hire',
                        'The guest list is saved on the phone, so scanning carries on without signal and syncs when it returns',
                        'Every door counts into the same live total',
                        'Turns away a code that has already been used, and says why',
                        'Every scan records who let the guest in',
                    ] as $point)
                        <li class="flex items-start gap-3 text-[15px]" style="color:var(--ink-2)">
                            <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full" style="background:var(--field)"></span>{{ $point }}
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Check-in. Replace with <img src="/assets/img/marketing/check-in.png"> when captured. --}}
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <div class="text-[13px] font-medium">Check-in</div>
                        <div class="text-[11px]" style="color:var(--muted)">Every door counts into the same live total, scan by scan.</div>
                    </div>
                    <div class="hidden rounded-md border px-2.5 py-1 text-[11px] sm:block" style="border-color:var(--rule); color:var(--ink-2)">Export log</div>
                </div>
                <div class="grid gap-0 sm:grid-cols-[0.8fr_1.2fr]">
                    <div class="border-b p-4 sm:border-b-0 sm:border-r" style="border-color:var(--rule)">
                        <div class="relative aspect-square rounded-md border" style="border-color:var(--rule)">
                            @foreach (['top-2 left-2 border-t-2 border-l-2','top-2 right-2 border-t-2 border-r-2','bottom-2 left-2 border-b-2 border-l-2','bottom-2 right-2 border-b-2 border-r-2'] as $corner)
                                <span class="absolute h-5 w-5 {{ $corner }}" style="border-color:var(--field)"></span>
                            @endforeach
                            <span class="absolute left-4 right-4 top-1/2 h-px" style="background:var(--field)"></span>
                        </div>
                        <div class="mt-3 text-[11px]" style="color:var(--muted)">Point the camera at the entry code on the phone or badge.</div>
                        <div class="mt-3 flex gap-2">
                            <div class="mono flex-1 rounded-md border px-2 py-1.5 text-[11px]" style="border-color:var(--rule); color:var(--muted)">AHIS26-XXXX</div>
                            <div class="rounded-md px-3 py-1.5 text-[11px] font-semibold text-white" style="background:var(--field)">Check in</div>
                        </div>
                    </div>

                    <div class="p-4">
                        <div class="flex items-baseline justify-between">
                            <div class="text-[13px] font-medium">Last scans</div>
                            <div class="text-[11px]" style="color:var(--muted)">Three ushers, three doors</div>
                        </div>
                        <div class="mt-1">
                            @foreach ([
                                ['Abena Owusu','AHIS26-9XR4','Gate A · Kwame','09:14:22', true],
                                ['Selorm Attoh','AHIS26-7VD3','Faculty desk · Efua','09:13:58', true],
                                ['Michael Tetteh','AHIS26-3JW2','Gate A · Kwame','09:13:41', true],
                                ['Ibrahim Sulemana','AHIS26-4TL8','Gate A · Kwame','09:13:07', false],
                                ['Kofi Danso','AHIS26-2M7Q','Gate B · Adjoa','09:12:50', true],
                                ['Yaa Serwaa Mensah','AHIS26-8F3K','Volta Room · Efua','09:12:11', true],
                            ] as [$name, $code, $gate, $time, $ok])
                                <div class="row">
                                    <div class="flex items-center gap-3">
                                        <span class="tick {{ $ok ? '' : 'no' }}">{{ $ok ? '✓' : '✕' }}</span>
                                        <span>
                                            <span class="block text-[13px] font-medium" style="{{ $ok ? '' : 'color:var(--danger)' }}">{{ $name }}</span>
                                            <span class="mono block text-[11px]" style="color:var(--muted)">{{ $code }}</span>
                                        </span>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-[11px]" style="color:var(--muted)">{{ $gate }}</div>
                                        <div class="mono text-[11px]" style="color:var(--muted)">{{ $time }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-3 rounded-md px-3 py-2 text-[11px]" style="background:#fdf6e9; color:var(--amber)">
                            One entry turned away: payment outstanding. Take payment at the desk to let them in.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ══ REQUESTS ══════════════════════════════════════════════════════ --}}
<section class="border-t" style="border-color:var(--rule)">
    <div class="mx-auto max-w-7xl px-6 py-16 md:py-24">
        <div class="grid items-center gap-12 lg:grid-cols-[0.85fr_1.15fr]">
            <div>
                <h2 class="h2 display">When a guest needs something,<br>someone’s phone buzzes.</h2>
                <p class="lede mt-4">
                    Water, a technician, a step-free route or first aid: guests ask from their ticket page.
                    Your floor crew sees the request with the seat number, taps “I’m on it” and marks it done.
                    Medical requests jump the queue, and the console shows who is handling each one.
                </p>
            </div>

            {{-- Requests, as the floor crew sees them on a staff link. --}}
            <div class="panel">
                <div class="panel-head">
                    <div>
                        <div class="text-[13px] font-medium">Requests</div>
                        <div class="text-[11px]" style="color:var(--muted)">Floor · Efua</div>
                    </div>
                    <div class="rounded-md px-2.5 py-1 text-[11px] font-semibold text-white" style="background:var(--danger)">2 open</div>
                </div>
                <div class="px-5 py-2">
                    @foreach ([
                        ['Medical / first aid', 'Seat C-14 · Main Hall', '1m', 'Open', true],
                        ['Water / refreshments', 'Seat F-02 · Volta Room', '3m', 'Kwame is on it', false],
                        ['Technical issue', 'Seat A-21 · Main Hall', '6m', 'Open', false],
                    ] as [$type, $where, $age, $state, $urgent])
                        <div class="row">
                            <span>
                                <span class="block text-[13px] font-medium" style="{{ $urgent ? 'color:var(--danger)' : '' }}">{{ $type }}</span>
                                <span class="block text-[11px]" style="color:var(--muted)">{{ $where }}</span>
                            </span>
                            <span class="text-right">
                                <span class="mono block text-[11px]" style="color:var(--muted)">{{ $age }}</span>
                                <span class="block text-[11px]" style="color:var(--ink-2)">{{ $state }}</span>
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ══ SETTLEMENT ════════════════════════════════════════════════════ --}}
<section class="paper border-y" style="border-color:var(--rule)">
    <div class="mx-auto max-w-7xl px-6 py-16 md:py-24">
        <div class="grid items-center gap-12 lg:grid-cols-2">
            <div class="panel order-2 lg:order-1">
                <div class="panel-head">
                    <div class="text-[13px] font-medium">Settlement statement</div>
                    <div class="mono text-[11px]" style="color:var(--muted)">Accra Tech Week</div>
                </div>
                <div class="px-5 py-2">
                    @foreach ([
                        ['Collected', 'GHS 412,400.00', false],
                        ['Payment provider fees', '− GHS 8,040.00', false],
                        ['Commission', '− GHS 33,200.00', false],
                        ['Settles to you', 'GHS 371,160.00', true],
                    ] as [$label, $amount, $net])
                        <div class="row">
                            <span class="text-[14px]" style="color:var(--ink-2)">{{ $label }}</span>
                            <span class="mono text-[14px] font-medium" style="{{ $net ? 'color:var(--go)' : '' }}">{{ $amount }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="border-t px-5 py-3 text-[11px]" style="border-color:var(--rule); color:var(--muted)">
                    Every charge, refund and payout, per event — exportable whenever you need it.
                </div>
            </div>

            <div class="order-1 lg:order-2">
                <h2 class="h2 display">{{ config('product-page.deep_sections.0.title') }}</h2>
                <p class="lede mt-4">{{ config('product-page.deep_sections.0.body') }}</p>
                <div class="mt-7 grid gap-5 sm:grid-cols-3">
                    @foreach (config('product-page.deep_sections.0.features') as $f)
                        <div>
                            <div style="font-weight:500">{{ $f['title'] }}</div>
                            <div class="mt-1 text-[14px]" style="color:var(--ink-2)">{{ $f['body'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ══ CAPABILITIES ══════════════════════════════════════════════════ --}}
<section id="features">
    <div class="mx-auto max-w-7xl px-6 py-16 md:py-24">
        <h2 class="h2 display">{{ config('product-page.capabilities.title') }}</h2>
        <p class="lede mt-3">{{ config('product-page.capabilities.subtitle') }}</p>
        <div class="cap mt-10">
            @foreach (config('product-page.capabilities.items') as $item)
                <div>
                    <h3>{{ $item['title'] }}</h3>
                    <p>{{ $item['body'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ══ COMMUNITIES ═══════════════════════════════════════════════════ --}}
<section class="border-t" style="border-color:var(--rule)">
    <div class="mx-auto max-w-7xl px-6 py-16 md:py-24">
        <h2 class="h2 display">{{ config('product-page.communities.title') }}</h2>
        <p class="lede mt-3">{{ config('product-page.communities.body') }}</p>
        <div class="cap mt-10">
            @foreach (config('product-page.communities.cards') as $card)
                <div>
                    <h3>{{ $card['title'] }}</h3>
                    <p>{{ $card['body'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ══ PRICING ═══════════════════════════════════════════════════════ --}}
<livewire:pricing-table />

{{-- ══ FAQ ═══════════════════════════════════════════════════════════ --}}
<section class="paper border-t" style="border-color:var(--rule)">
    <div class="mx-auto max-w-3xl px-6 py-16 md:py-24">
        <h2 class="h2 display">Questions organizers ask</h2>
        <div class="mt-8 divide-y" style="border-color:var(--rule)">
            @foreach (config('product-page.faqs') as $faq)
                <details class="group py-4">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4" style="font-weight:500">
                        {{ $faq['q'] }}
                        <span class="shrink-0 text-xl leading-none transition group-open:rotate-45" style="color:var(--field)">+</span>
                    </summary>
                    <p class="mt-3 text-[15px]" style="color:var(--ink-2)">{{ $faq['a'] }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- ══ CLOSE ═════════════════════════════════════════════════════════ --}}
<section class="field">
    <div class="mx-auto max-w-3xl px-6 py-20 text-center md:py-28">
        <h2 class="display text-white" style="font-size:clamp(1.9rem,4vw,2.7rem); font-weight:500; line-height:1.14; letter-spacing:-.018em">
            {{ config('product-page.final_cta.title') }}
        </h2>
        <p class="hero-sub">{{ config('product-page.final_cta.subtitle') }}</p>
        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
            <a href="{{ route('register') }}" class="btn-solid">{{ config('product-page.final_cta.cta_primary.label') }}</a>
            <a href="{{ route('product.enterprise') }}" class="btn-ghost">Talk to us</a>
        </div>
    </div>
</section>

@endsection

@push('scripts')
<script data-hero-showcase-script>
(() => {
    const root = document.querySelector('[data-hero-showcase]');
    if (!root) return;
    const tabs = [...root.querySelectorAll('[role="tab"]')];
    const panels = tabs.map(tab => document.getElementById(tab.getAttribute('aria-controls')));
    const select = (index, focus = false) => {
        tabs.forEach((tab, position) => {
            const selected = position === index;
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
            panels[position].hidden = !selected;
        });
        if (focus) tabs[index].focus();
    };
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => select(index));
        tab.addEventListener('keydown', event => {
            const destinations = {
                ArrowRight: (index + 1) % tabs.length,
                ArrowLeft: (index + tabs.length - 1) % tabs.length,
                Home: 0,
                End: tabs.length - 1,
            };
            if (!(event.key in destinations)) return;
            event.preventDefault();
            select(destinations[event.key], true);
        });
    });
    select(1);
    root.querySelector('[role="tablist"]').hidden = false;
})();
</script>
@endpush
