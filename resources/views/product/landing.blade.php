@extends('layouts.product')

@section('title', config('product-page.brand.name').' — run your whole event from one place')

@push('styles')
<style>
    /* ── Field ─────────────────────────────────────────────────────────
       The hero sits on a saturated indigo field. Everything below it is
       paper, so the colour reads as the stage and the rest as the programme. */
    .field {
        background: var(--indigo);
        background-image:
            radial-gradient(120% 90% at 50% -10%, #5a45e6 0%, rgba(90,69,230,0) 60%),
            radial-gradient(80% 60% at 90% 10%, rgba(185,174,255,.22) 0%, rgba(185,174,255,0) 70%);
        position: relative;
        overflow: hidden;
    }
    /* Faint seating-plan grid: the room, drawn once, never repeated elsewhere. */
    .field::before {
        content: "";
        position: absolute; inset: 0;
        background-image:
            linear-gradient(rgba(255,255,255,.055) 1px, transparent 1px),
            linear-gradient(90deg, rgba(255,255,255,.055) 1px, transparent 1px);
        background-size: 56px 56px;
        mask-image: radial-gradient(110% 80% at 50% 30%, #000 30%, transparent 78%);
        pointer-events: none;
    }
    .field > * { position: relative; }

    .pill {
        display: inline-flex; align-items: center; gap: .55rem;
        padding: .4rem .9rem .4rem .55rem;
        border-radius: 999px;
        background: rgba(255,255,255,.10);
        border: 1px solid rgba(255,255,255,.18);
        color: #efecff; font-size: .82rem;
    }
    .pill span.dot {
        width: 1.35rem; height: 1.35rem; border-radius: 999px;
        background: var(--marigold); color: #2a1c94;
        display: grid; place-items: center; font-size: .72rem; font-weight: 800;
    }

    .hero-title {
        font-size: clamp(2.6rem, 7vw, 4.6rem);
        line-height: 1.02;
        font-weight: 800;
        color: #fff;
        text-wrap: balance;
        margin: 1.4rem 0 0;
    }
    .hero-title em { font-style: normal; color: var(--peri); }
    .hero-sub {
        margin: 1.3rem auto 0; max-width: 44rem;
        color: #d9d4f7; font-size: 1.08rem; line-height: 1.6;
    }

    .btn-solid {
        display: inline-flex; align-items: center; gap: .5rem;
        background: #fff; color: var(--indigo-deep);
        font-weight: 700; padding: .85rem 1.6rem; border-radius: .8rem;
        transition: transform .15s ease, box-shadow .15s ease;
        box-shadow: 0 10px 30px -12px rgba(0,0,0,.5);
    }
    .btn-solid:hover { transform: translateY(-1px); }
    .btn-ghost {
        display: inline-flex; align-items: center;
        color: #cfc8f5; font-weight: 600; padding: .85rem 1.1rem;
        border-radius: .8rem; border: 1px solid rgba(255,255,255,.2);
    }
    .btn-ghost:hover { color: #fff; border-color: rgba(255,255,255,.4); }

    /* ── Product still-life ────────────────────────────────────────────
       The page an organizer shares, and the ticket their guest receives.
       Two real surfaces, composed — this is the one bold moment on the page. */
    .stage { margin-top: 4rem; padding-bottom: 0; }
    .surface {
        background: var(--surface);
        border-radius: 14px;
        box-shadow: 0 40px 80px -30px rgba(20,10,70,.55), 0 0 0 1px rgba(255,255,255,.10);
        overflow: hidden;
        text-align: left;
    }
    .chrome {
        display: flex; align-items: center; gap: .45rem;
        padding: .7rem .9rem; border-bottom: 1px solid var(--rule);
        background: #fbfaff;
    }
    .chrome i { width: .6rem; height: .6rem; border-radius: 999px; background: #ded9ec; display: block; }
    .chrome .addr {
        margin-left: .6rem; font-size: .72rem; color: var(--muted);
        background: #f2f0f9; border-radius: 6px; padding: .2rem .6rem;
    }

    .ticket {
        background: var(--surface);
        border-radius: 12px;
        box-shadow: 0 30px 60px -22px rgba(20,10,70,.6), 0 0 0 1px rgba(255,255,255,.12);
        overflow: hidden;
    }
    /* The notch is what makes it read as a ticket rather than a card. */
    .ticket .perf {
        position: relative; height: 0; border-top: 2px dashed var(--rule); margin: 0 .9rem;
    }
    .ticket .perf::before, .ticket .perf::after {
        content: ""; position: absolute; top: -11px; width: 20px; height: 20px;
        border-radius: 999px; background: var(--indigo);
    }
    .ticket .perf::before { left: -19px; }
    .ticket .perf::after { right: -19px; }

    .qr {
        width: 92px; height: 92px; border-radius: 8px;
        background-color: #fff;
        background-image:
            linear-gradient(90deg, var(--ink) 1px, transparent 1px),
            linear-gradient(var(--ink) 1px, transparent 1px);
        background-size: 8px 8px;
        box-shadow: inset 0 0 0 1px var(--rule);
        position: relative;
    }
    .qr::before, .qr::after {
        content: ""; position: absolute; width: 26px; height: 26px;
        border: 5px solid var(--ink); border-radius: 4px; background: #fff;
    }
    .qr::before { top: 7px; left: 7px; }
    .qr::after { top: 7px; right: 7px; }

    /* ── Paper sections ───────────────────────────────────────────────── */
    .paper { background: var(--paper); }
    .h2 { font-size: clamp(1.8rem, 3.4vw, 2.6rem); font-weight: 700; line-height: 1.12; text-wrap: balance; }
    .lede { color: var(--ink-2); font-size: 1.05rem; max-width: 42rem; }

    /* The arc is a real sequence, so it is numbered; nothing else on the
       page is. */
    .arc { display: grid; gap: 0; grid-template-columns: repeat(4, 1fr); }
    @media (max-width: 860px) { .arc { grid-template-columns: 1fr; } }
    .arc-step { padding: 1.6rem 1.4rem; border-left: 2px solid var(--rule); }
    .arc-step:first-child { border-left-color: var(--indigo); }
    .arc-n { font-size: .78rem; color: var(--indigo); font-weight: 700; }
    .arc-t { font-family: 'Gabarito', sans-serif; font-weight: 700; font-size: 1.12rem; margin-top: .35rem; }
    .arc-b { color: var(--ink-2); font-size: .95rem; margin-top: .35rem; }

    .panel {
        background: var(--surface); border: 1px solid var(--rule);
        border-radius: 16px; overflow: hidden;
    }
    .stat-row { display: flex; justify-content: space-between; padding: .6rem 0; border-bottom: 1px solid var(--rule); font-size: .92rem; }
    .stat-row:last-child { border-bottom: none; }
    .stat-row b { font-weight: 600; }
    .net { color: var(--go); font-weight: 700; }

    .cap { display: grid; gap: 1px; background: var(--rule); border: 1px solid var(--rule); border-radius: 16px; overflow: hidden; grid-template-columns: repeat(3, 1fr); }
    @media (max-width: 880px) { .cap { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 560px) { .cap { grid-template-columns: 1fr; } }
    .cap > div { background: var(--surface); padding: 1.5rem 1.4rem; }
    .cap h3 { font-family: 'Gabarito', sans-serif; font-weight: 700; font-size: 1.02rem; }
    .cap p { color: var(--ink-2); font-size: .93rem; margin-top: .3rem; }

    a:focus-visible, button:focus-visible { outline: 3px solid var(--peri); outline-offset: 2px; }
</style>
@endpush

@section('content')

{{-- ══ HERO ══════════════════════════════════════════════════════════ --}}
<section class="field">
    <div class="mx-auto max-w-7xl px-6 pt-16 pb-0 text-center md:pt-24">

        <span class="pill">
            <span class="dot">✦</span>
            Free for your first event
        </span>

        <h1 class="hero-title display">
            Run the whole event<br><em>from one place</em>
        </h1>

        <p class="hero-sub">
            Build the page, sell the tickets, scan guests in at the door, and see exactly
            what you earned — without stitching five tools together.
        </p>

        <div class="mt-9 flex flex-wrap items-center justify-center gap-3">
            <a href="{{ route('register') }}" class="btn-solid">Start free</a>
            <a href="#pricing" class="btn-ghost">See pricing</a>
        </div>

        <p class="mt-5 text-sm" style="color:#b3aae8">
            One live event and 100 registrations, no card required.
        </p>

        {{-- The page you share, and the ticket your guest receives. --}}
        <div class="stage grid gap-6 md:grid-cols-[1.55fr_1fr] md:items-end text-left">

            <div class="surface">
                <div class="chrome">
                    <i></i><i></i><i></i>
                    <span class="addr mono">accra-tech-week.miconvener.com/e/summit</span>
                </div>
                <div class="p-6">
                    <div class="text-[11px] font-semibold" style="color:var(--indigo)">Open to anyone</div>
                    <div class="display mt-1 text-[26px] font-extrabold leading-tight">Accra Tech Week 2026</div>
                    <div class="mt-1 text-sm" style="color:var(--muted)">Hosted by Accra Tech Collective</div>

                    <div class="mt-5 grid grid-cols-3 gap-4 border-y py-4" style="border-color:var(--rule)">
                        <div>
                            <div class="text-[11px]" style="color:var(--muted)">When</div>
                            <div class="mt-0.5 text-sm font-semibold">14 Oct 2026</div>
                        </div>
                        <div>
                            <div class="text-[11px]" style="color:var(--muted)">Where</div>
                            <div class="mt-0.5 text-sm font-semibold">Accra Int’l Conference Centre</div>
                        </div>
                        <div>
                            <div class="text-[11px]" style="color:var(--muted)">From</div>
                            <div class="mt-0.5 text-sm font-semibold mono">GHS 20.00</div>
                        </div>
                    </div>

                    <div class="mt-4 space-y-2">
                        <div class="flex items-center justify-between rounded-xl border px-4 py-3"
                             style="border-color:var(--indigo); background:#f4f2ff">
                            <div>
                                <div class="text-sm font-semibold">General Admission</div>
                                <div class="text-[12px]" style="color:var(--muted)">312 of 400 remaining</div>
                            </div>
                            <div class="mono text-sm font-semibold">GHS 20.00</div>
                        </div>
                        <div class="flex items-center justify-between rounded-xl border px-4 py-3" style="border-color:var(--rule)">
                            <div>
                                <div class="text-sm font-semibold">Workshop pass</div>
                                <div class="text-[12px]" style="color:var(--muted)">Includes both afternoon tracks</div>
                            </div>
                            <div class="mono text-sm font-semibold">GHS 75.00</div>
                        </div>
                    </div>

                    <div class="mt-4 rounded-xl px-4 py-3 text-center text-sm font-bold text-white" style="background:var(--indigo)">
                        Continue to payment
                    </div>
                </div>
            </div>

            {{-- What the guest gets back --}}
            <div class="ticket mb-2">
                <div class="px-5 pt-5 pb-4">
                    <div class="text-[11px] font-semibold" style="color:var(--go)">Confirmed</div>
                    <div class="display mt-1 text-[19px] font-extrabold leading-tight">Ama Mensah</div>
                    <div class="text-[13px]" style="color:var(--muted)">Accra Tech Week 2026</div>
                </div>
                <div class="perf"></div>
                <div class="flex items-center gap-4 px-5 pb-5 pt-5">
                    <div class="qr" role="img" aria-label="Entry QR code"></div>
                    <div class="min-w-0">
                        <div class="text-[11px]" style="color:var(--muted)">Entry code</div>
                        <div class="mono text-[15px] font-semibold">EVT-WMQQ-251</div>
                        <div class="mt-2 text-[11px]" style="color:var(--muted)">Ticket</div>
                        <div class="text-[13px] font-semibold">General Admission</div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

{{-- ══ THE ARC ═══════════════════════════════════════════════════════ --}}
<section class="paper border-b" style="border-color:var(--rule)">
    <div class="mx-auto max-w-7xl px-6 py-16 md:py-20">
        <h2 class="h2 display">{{ config('product-page.intro.title') }}</h2>
        <p class="lede mt-3">{{ config('product-page.intro.body') }}</p>

        <div class="arc mt-10">
            @foreach ([
                ['Create', 'Build the page', 'Schedule, speakers and ticket types on one link you can share anywhere.'],
                ['Sell', 'Take payments', 'Cards and mobile money. The ticket arrives the moment payment clears.'],
                ['Check in', 'Run the door', 'Scan a code or search by name, and print badges as guests arrive.'],
                ['Settle', 'Get paid', 'One statement per event, then a payout to your account.'],
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
        <div class="grid items-center gap-12 md:grid-cols-2">
            <div>
                <h2 class="h2 display">The door is the hardest part.<br>It takes one scan.</h2>
                <p class="lede mt-4">
                    Point a phone at the guest’s code, or search by name if they left it at home.
                    Capacity, ticket type and duplicate entries are all checked before you wave them through.
                </p>
                <ul class="mt-6 space-y-3">
                    @foreach ([
                        'Works on any phone — no scanner hardware to hire',
                        'Prints a badge for the guest as they arrive',
                        'Flags a code that has already been used',
                    ] as $point)
                        <li class="flex items-start gap-3 text-[15px]" style="color:var(--ink-2)">
                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full" style="background:var(--indigo)"></span>
                            {{ $point }}
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Check-in surface --}}
            <div class="panel">
                <div class="flex items-center justify-between border-b px-5 py-4" style="border-color:var(--rule); background:#fbfaff">
                    <div class="text-sm font-semibold">Check-in · Accra Tech Week</div>
                    <div class="mono text-[12px]" style="color:var(--muted)">218 / 400 in</div>
                </div>
                <div class="p-5">
                    <div class="rounded-xl border p-4" style="border-color:rgba(15,123,90,.35); background:rgba(15,123,90,.06)">
                        <div class="flex items-center gap-3">
                            <div class="grid h-9 w-9 place-items-center rounded-full text-white" style="background:var(--go)">✓</div>
                            <div>
                                <div class="font-semibold">Ama Mensah</div>
                                <div class="text-[12px]" style="color:var(--muted)">General Admission · checked in just now</div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 space-y-2">
                        @foreach ([
                            ['Kwabena Owusu', 'Workshop pass', 'in', '09:12'],
                            ['Efua Sarpong', 'General Admission', 'in', '09:10'],
                            ['Yaw Boateng', 'General Admission', 'expected', '—'],
                        ] as [$name, $ticket, $state, $time])
                            <div class="flex items-center justify-between rounded-lg border px-4 py-2.5" style="border-color:var(--rule)">
                                <div>
                                    <div class="text-[14px] font-medium">{{ $name }}</div>
                                    <div class="text-[12px]" style="color:var(--muted)">{{ $ticket }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-[12px] font-semibold" style="color:{{ $state === 'in' ? 'var(--go)' : 'var(--muted)' }}">
                                        {{ $state === 'in' ? 'Checked in' : 'Expected' }}
                                    </div>
                                    <div class="mono text-[11px]" style="color:var(--muted)">{{ $time }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ══ SETTLEMENT ════════════════════════════════════════════════════ --}}
<section class="paper border-y" style="border-color:var(--rule)">
    <div class="mx-auto max-w-7xl px-6 py-16 md:py-24">
        <div class="grid items-center gap-12 md:grid-cols-2">

            {{-- Statement surface, using the real ledger shape --}}
            <div class="panel order-2 md:order-1">
                <div class="flex items-center justify-between border-b px-5 py-4" style="border-color:var(--rule); background:#fbfaff">
                    <div class="text-sm font-semibold">Settlement statement</div>
                    <div class="mono text-[12px]" style="color:var(--muted)">Accra Tech Week</div>
                </div>
                <div class="px-5 py-4">
                    <div class="stat-row"><span style="color:var(--ink-2)">Collected</span><b class="mono">GHS 20.00</b></div>
                    <div class="stat-row"><span style="color:var(--ink-2)">Payment provider fee</span><b class="mono">− GHS 0.39</b></div>
                    <div class="stat-row"><span style="color:var(--ink-2)">Commission</span><b class="mono">− GHS 1.00</b></div>
                    <div class="stat-row"><span style="color:var(--ink-2)">Settles to you</span><b class="mono net">GHS 18.61</b></div>
                </div>
                <div class="border-t px-5 py-3.5 text-[12px]" style="border-color:var(--rule); color:var(--muted)">
                    Every charge, refund and payout, per event — exportable whenever you need it.
                </div>
            </div>

            <div class="order-1 md:order-2">
                <h2 class="h2 display">{{ config('product-page.deep_sections.0.title') }}</h2>
                <p class="lede mt-4">{{ config('product-page.deep_sections.0.body') }}</p>
                <div class="mt-7 grid gap-5 sm:grid-cols-3">
                    @foreach (config('product-page.deep_sections.0.features') as $f)
                        <div>
                            <div class="font-semibold" style="font-family:'Gabarito',sans-serif">{{ $f['title'] }}</div>
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

{{-- ══ PRICING ═══════════════════════════════════════════════════════ --}}
<livewire:pricing-table />

{{-- ══ FAQ ═══════════════════════════════════════════════════════════ --}}
<section class="paper border-t" style="border-color:var(--rule)">
    <div class="mx-auto max-w-3xl px-6 py-16 md:py-24">
        <h2 class="h2 display">Questions organizers ask</h2>
        <div class="mt-8 divide-y" style="border-color:var(--rule)">
            @foreach (config('product-page.faqs') as $faq)
                <details class="group py-4">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold">
                        {{ $faq['q'] }}
                        <span class="shrink-0 text-xl leading-none transition group-open:rotate-45" style="color:var(--indigo)">+</span>
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
        <h2 class="display text-white" style="font-size:clamp(2rem,4.6vw,3rem); font-weight:800; line-height:1.08">
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
