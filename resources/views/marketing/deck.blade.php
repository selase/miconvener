<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>MiConvener — Executive Pitch Deck | The Unified Event Operating System</title>
    <meta name="description" content="Why modern conferences and high-stakes events fail with 6 disconnected tools — and how MiConvener unifies event production, ticketing, on-site operations, and settlement." />

    <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/brand/mark-32.png" />
    <link rel="icon" type="image/png" sizes="512x512" href="/assets/img/brand/mark-512.png" />

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Public+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Tailwind Play CDN for immediate, zero-build rendering -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Public Sans"', 'system-ui', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace'],
                    },
                    colors: {
                        brand: {
                            50: '#eef5ff',
                            100: '#d9e8ff',
                            200: '#bcd7ff',
                            500: '#155dfc',
                            600: '#0b4ed6',
                            700: '#073ab0',
                            900: '#08236d',
                        },
                        obsidian: {
                            950: '#06090e',
                            900: '#0b1017',
                            850: '#111722',
                            800: '#17202f',
                            700: '#222f44',
                        }
                    }
                }
            }
        }
    </script>

    <style>
        :root {
            --accent: #155dfc;
            --accent-glow: rgba(21, 93, 252, 0.25);
        }

        body {
            font-family: 'Public Sans', sans-serif;
            text-rendering: optimizeLegibility;
            -webkit-font-smoothing: antialiased;
        }

        /* Slide Transition System */
        .slide {
            display: none;
            opacity: 0;
            transform: scale(0.985);
            transition: opacity 0.28s cubic-bezier(0.16, 1, 0.3, 1), transform 0.28s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .slide.active {
            display: flex;
            opacity: 1;
            transform: scale(1);
        }

        /* Glassmorphic Cards */
        .glass-panel {
            background: rgba(17, 23, 34, 0.75);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .light .glass-panel {
            background: rgba(255, 255, 255, 0.88);
            border: 1px solid rgba(0, 0, 0, 0.08);
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.05);
        }

        .code-pill {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.75rem;
            letter-spacing: -0.01em;
        }

        /* Custom Scrollbar for Notes Drawer */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 999px; }

        /* Print / PDF Export Stylesheet */
        @media print {
            body { background: #ffffff !important; color: #000000 !important; }
            .no-print { display: none !important; }
            .slide {
                display: flex !important;
                opacity: 1 !important;
                transform: none !important;
                page-break-after: always;
                height: 100vh;
                max-height: 100vh;
                padding: 2.5rem !important;
                background: #ffffff !important;
                color: #111827 !important;
                border-bottom: 2px solid #e5e7eb;
            }
            .glass-panel {
                background: #f9fafb !important;
                border: 1px solid #e5e7eb !important;
                box-shadow: none !important;
                color: #111827 !important;
            }
            .text-white { color: #111827 !important; }
            .text-slate-300, .text-slate-400 { color: #4b5563 !important; }
            .text-slate-500 { color: #6b7280 !important; }
            .border-slate-800, .border-slate-700 { border-color: #e5e7eb !important; }
        }
    </style>
</head>

<body class="h-full bg-obsidian-950 text-slate-100 selection:bg-brand-500 selection:text-white flex flex-col justify-between overflow-hidden relative">

    <!-- Ambient Subtle Glow Background -->
    <div class="pointer-events-none absolute inset-0 overflow-hidden no-print z-0 opacity-40">
        <div class="absolute -top-[25%] left-1/2 -translate-x-1/2 w-[900px] h-[500px] bg-brand-600/20 blur-[130px] rounded-full"></div>
        <div class="absolute -bottom-[20%] right-[10%] w-[600px] h-[400px] bg-indigo-600/15 blur-[120px] rounded-full"></div>
    </div>

    <!-- TOP HEADER / BRAND BAR -->
    <header class="no-print relative z-20 px-6 py-4 flex items-center justify-between border-b border-white/5 backdrop-blur-md">
        <div class="flex items-center gap-3">
            <a href="/" class="flex items-center gap-2.5 group">
                <span class="w-8 h-8 rounded-lg bg-brand-500 flex items-center justify-center font-extrabold text-white text-base shadow-lg shadow-brand-500/30 group-hover:scale-105 transition-transform">M</span>
                <span class="font-bold text-lg tracking-tight text-white group-hover:text-brand-400 transition-colors">{{ $brandName }}</span>
            </a>
            <span class="text-xs text-slate-500 hidden sm:inline-block">/</span>
            <span class="text-xs font-medium text-slate-400 uppercase tracking-widest hidden sm:inline-block">Executive Pitch & Platform Architecture</span>
        </div>

        <div class="flex items-center gap-3">
            <!-- Share Slide Button -->
            <button id="btn-share" onclick="copyCurrentSlideUrl()" title="Copy Link to Current Slide (C)" class="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 transition-colors flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" /></svg>
                <span class="hidden md:inline">Share Slide</span>
            </button>

            <!-- Presenter Notes Toggle Button -->
            <button id="btn-notes" onclick="toggleNotesDrawer()" title="Toggle Presenter Talking Points (P)" class="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 transition-colors flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" /></svg>
                <span class="hidden md:inline">Presenter Notes</span>
                <span class="code-pill text-[10px] text-slate-500 bg-white/5 px-1 py-0.5 rounded">P</span>
            </button>

            <!-- PDF Print Button -->
            <button onclick="window.print()" title="Export / Print Deck (Cmd+P)" class="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-300 bg-white/5 hover:bg-white/10 border border-white/10 transition-colors flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" /></svg>
                <span class="hidden md:inline">Print / PDF</span>
            </button>

            <!-- Theme Toggle -->
            <button onclick="toggleTheme()" title="Toggle Light/Dark Theme (T)" class="p-2 rounded-lg text-slate-400 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 transition-colors">
                <svg id="theme-icon-sun" class="w-4 h-4 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 9h-1m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                <svg id="theme-icon-moon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" /></svg>
            </button>
        </div>
    </header>

    <!-- MAIN SLIDE STAGE -->
    <main class="flex-1 w-full max-w-7xl mx-auto px-4 sm:px-8 py-6 relative z-10 flex items-center justify-center overflow-y-auto">

        <!-- SLIDE 1: MANIFESTO & TITLE -->
        <article class="slide active w-full flex-col justify-center min-h-[580px]" data-slide="1" data-title="The Manifesto">
            <div class="space-y-6 max-w-4xl">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-500/10 border border-brand-500/30 text-brand-400 text-xs font-semibold tracking-wide uppercase">
                    <span class="w-2 h-2 rounded-full bg-brand-400 animate-pulse"></span>
                    Unified Event Operating System
                </div>
                <h1 class="text-4xl sm:text-6xl font-extrabold tracking-tight text-white leading-[1.08]">
                    Why Modern Conferences Fail With 6 Disconnected Tools — <br class="hidden sm:inline">
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-brand-400 via-sky-300 to-indigo-300">And The Single Platform Built For Flawless Execution.</span>
                </h1>
                <p class="text-lg sm:text-xl text-slate-300 font-light leading-relaxed max-w-3xl">
                    Stitching together separate ticketing, Canva badges, Google Form meal surveys, chaotic usher group chats, and third-party polling invites catastrophic event-day breakdown. MiConvener unifies your entire production into one sovereign command center.
                </p>

                <!-- Dual Perspective Badges -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4">
                    <div class="glass-panel p-5 rounded-xl border-l-4 border-l-brand-500">
                        <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-brand-400 mb-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>
                            Production Manager Viewpoint
                        </div>
                        <p class="text-sm text-slate-300 font-normal">
                            Zero gate failure. Offline-first PWA check-in, clash-free agendas, live in-seat water/tech requests, and automated badge printing without 2 AM spreadsheet panics.
                        </p>
                    </div>

                    <div class="glass-panel p-5 rounded-xl border-l-4 border-l-emerald-500">
                        <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-emerald-400 mb-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            Sales Tech & Commercial Viewpoint
                        </div>
                        <p class="text-sm text-slate-300 font-normal">
                            Higher ticket conversion with instant Mobile Money + Card checkout, automated settlements on double-entry ledgers, and 65–80% reduction in fragmented SaaS fees.
                        </p>
                    </div>
                </div>
            </div>
        </article>

        <!-- SLIDE 2: THE INDUSTRY CRISIS (FRANKENSTEIN TECH STACK) -->
        <article class="slide w-full flex-col justify-center min-h-[580px]" data-slide="2" data-title="The Industry Crisis">
            <div class="space-y-5 max-w-5xl">
                <span class="text-rose-400 text-xs font-semibold tracking-wider uppercase">The Pain Point</span>
                <h2 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight">
                    The Fragile "Frankenstein" Event Stack
                </h2>
                <p class="text-base sm:text-lg text-slate-300 font-light max-w-3xl">
                    Every event producer knows this pain: buying 6 different SaaS products, exporting and re-importing CSVs, and crossing their fingers that nothing breaks at registration.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pt-2">
                    <div class="glass-panel p-5 rounded-xl border-t-2 border-t-rose-500/60">
                        <div class="text-rose-400 text-2xl font-bold mb-2">6 Disconnected Apps</div>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Ticketing on Eventbrite, badges designed in Canva, lunch choices on Google Forms, polling on Slido, speaker slides chased via email, and usher requests on scattered chat threads.
                        </p>
                        <div class="mt-3 text-[11px] font-mono text-rose-300/80 bg-rose-950/30 p-2 rounded">
                            Result: 120+ hours wasted on manual copy-pasting and de-duplication.
                        </div>
                    </div>

                    <div class="glass-panel p-5 rounded-xl border-t-2 border-t-amber-500/60">
                        <div class="text-amber-400 text-2xl font-bold mb-2">Gate Chaos & Queues</div>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Venue Wi-Fi slows down, check-in apps freeze, paper lists are misprinted, badges have spelling errors, and VIP delegates wait 45 minutes in line.
                        </p>
                        <div class="mt-3 text-[11px] font-mono text-amber-300/80 bg-amber-950/30 p-2 rounded">
                            Result: Permanent damage to your organization's executive reputation.
                        </div>
                    </div>

                    <div class="glass-panel p-5 rounded-xl border-t-2 border-t-purple-500/60">
                        <div class="text-purple-400 text-2xl font-bold mb-2">Financial Blind Spots</div>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Delayed payouts, manual spreadsheets to calculate who paid by MoMo vs Card, zero double-entry audit trail, and weeks of post-event accounting headaches.
                        </p>
                        <div class="mt-3 text-[11px] font-mono text-purple-300/80 bg-purple-950/30 p-2 rounded">
                            Result: Unreconciled fees, cash leakage, and compliance vulnerabilities.
                        </div>
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-slate-900/80 border border-slate-800 flex items-center justify-between">
                    <span class="text-xs sm:text-sm text-slate-300">
                        <strong class="text-white">Bottom Line:</strong> Fragmentation doesn't just cost thousands in redundant software fees—it introduces critical single points of failure right when stakes are highest.
                    </span>
                    <span class="code-pill text-xs px-2.5 py-1 rounded bg-brand-500/20 text-brand-300 border border-brand-500/30 hidden md:inline-block">The Solution: MiConvener</span>
                </div>
            </div>
        </article>

        <!-- SLIDE 3: THE MICONVENER PROPOSITION -->
        <article class="slide w-full flex-col justify-center min-h-[580px]" data-slide="3" data-title="The Value Proposition">
            <div class="space-y-6 max-w-5xl">
                <span class="text-brand-400 text-xs font-semibold tracking-wider uppercase">The Core Proposition</span>
                <h2 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight">
                    One Sovereign Platform. Zero Operational Chaos.
                </h2>
                <p class="text-base sm:text-lg text-slate-300 font-light max-w-3xl">
                    MiConvener is an end-to-end, multi-tenant Event Operating System. Your event runs on your own branded subdomain, powered by a single unified database.
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 pt-2">
                    <div class="glass-panel p-5 rounded-xl hover:border-brand-500/50 transition-colors">
                        <div class="w-9 h-9 rounded-lg bg-brand-500/15 text-brand-400 flex items-center justify-center font-bold text-sm mb-3">01</div>
                        <h3 class="text-base font-bold text-white mb-1.5">Operational Unity</h3>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Registration, programme, faculty, materials, on-site check-in, and finance live in one relational system. No CSV exports required.
                        </p>
                    </div>

                    <div class="glass-panel p-5 rounded-xl hover:border-brand-500/50 transition-colors">
                        <div class="w-9 h-9 rounded-lg bg-emerald-500/15 text-emerald-400 flex items-center justify-center font-bold text-sm mb-3">02</div>
                        <h3 class="text-base font-bold text-white mb-1.5">Production-Grade Guardrails</h3>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Offline-first PWA check-in, sub-second scanning, schedule clash detection, and anti-passback controls keep gates moving smoothly.
                        </p>
                    </div>

                    <div class="glass-panel p-5 rounded-xl hover:border-brand-500/50 transition-colors">
                        <div class="w-9 h-9 rounded-lg bg-sky-500/15 text-sky-400 flex items-center justify-center font-bold text-sm mb-3">03</div>
                        <h3 class="text-base font-bold text-white mb-1.5">Financial Sovereignty</h3>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Dual settlement modes (Platform Default or BYO Paystack/Stripe), mobile money & cards, verified name enquiry, and append-only ledgers.
                        </p>
                    </div>

                    <div class="glass-panel p-5 rounded-xl hover:border-brand-500/50 transition-colors">
                        <div class="w-9 h-9 rounded-lg bg-indigo-500/15 text-indigo-400 flex items-center justify-center font-bold text-sm mb-3">04</div>
                        <h3 class="text-base font-bold text-white mb-1.5">White-Label Prestige</h3>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Your brand takes center stage. Custom subdomain, dedicated attendee and speaker portals, and no third-party branding hijacking.
                        </p>
                    </div>
                </div>
            </div>
        </article>

        <!-- SLIDE 4: PRE-EVENT & SPEAKER ENGAGEMENT -->
        <article class="slide w-full flex-col justify-center min-h-[580px]" data-slide="4" data-title="Pre-Event & Speaker Engagement">
            <div class="space-y-5 max-w-5xl">
                <span class="text-brand-400 text-xs font-semibold tracking-wider uppercase">Flawless Preparation</span>
                <h2 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight">
                    Automated Notifications, Speaker Portal & Meal Forms
                </h2>
                <p class="text-base sm:text-lg text-slate-300 font-light max-w-3xl">
                    Stop chasing speakers over email and collecting food allergies across messy spreadsheets. Streamline the entire pre-event pipeline.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pt-2">
                    <div class="glass-panel p-5 rounded-xl">
                        <div class="flex items-center gap-2 text-brand-400 font-semibold text-sm mb-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                            Notifications & Reminders
                        </div>
                        <ul class="text-xs text-slate-400 space-y-2 leading-relaxed">
                            <li class="flex items-start gap-1.5">
                                <span class="text-brand-400 font-bold">•</span>
                                <span><strong>Pre-Event Communications:</strong> Segmented email broadcasts with open-rate telemetry.</span>
                            </li>
                            <li class="flex items-start gap-1.5">
                                <span class="text-brand-400 font-bold">•</span>
                                <span><strong>Countdown Alerts:</strong> Automated reminders sent N days/hours prior to kickoff.</span>
                            </li>
                            <li class="flex items-start gap-1.5">
                                <span class="text-brand-400 font-bold">•</span>
                                <span><strong>Calendar Integration:</strong> Instant 1-click .ICS calendar sync for attendees' personal agendas.</span>
                            </li>
                        </ul>
                    </div>

                    <div class="glass-panel p-5 rounded-xl">
                        <div class="flex items-center gap-2 text-emerald-400 font-semibold text-sm mb-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                            Dedicated Speaker Portal
                        </div>
                        <ul class="text-xs text-slate-400 space-y-2 leading-relaxed">
                            <li class="flex items-start gap-1.5">
                                <span class="text-emerald-400 font-bold">•</span>
                                <span><strong>Token-Scoped Access:</strong> Secure private link sent to faculty without login friction.</span>
                            </li>
                            <li class="flex items-start gap-1.5">
                                <span class="text-emerald-400 font-bold">•</span>
                                <span><strong>PowerPoint Submissions:</strong> Speakers upload slides & handouts directly against strict deadlines.</span>
                            </li>
                            <li class="flex items-start gap-1.5">
                                <span class="text-emerald-400 font-bold">•</span>
                                <span><strong>Accreditation Disclosures:</strong> Conflict-of-interest forms submitted for CME/CPD compliance.</span>
                            </li>
                        </ul>
                    </div>

                    <div class="glass-panel p-5 rounded-xl">
                        <div class="flex items-center gap-2 text-sky-400 font-semibold text-sm mb-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
                            Custom Forms & Food Menus
                        </div>
                        <ul class="text-xs text-slate-400 space-y-2 leading-relaxed">
                            <li class="flex items-start gap-1.5">
                                <span class="text-sky-400 font-bold">•</span>
                                <span><strong>Menu Choice Capture:</strong> Custom questions to select specific lunch menu items (e.g., Jollof, Fried Rice, Vegan).</span>
                            </li>
                            <li class="flex items-start gap-1.5">
                                <span class="text-sky-400 font-bold">•</span>
                                <span><strong>Dietary & Allergies:</strong> Dedicated fields for Halal, Kosher, Nut allergies, and accessibility needs.</span>
                            </li>
                            <li class="flex items-start gap-1.5">
                                <span class="text-sky-400 font-bold">•</span>
                                <span><strong>Catering Manifest:</strong> 1-click aggregated export for venue catering and kitchen staff.</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </article>

        <!-- SLIDE 5: 5 REGISTRATION MODES & TICKETING -->
        <article class="slide w-full flex-col justify-center min-h-[580px]" data-slide="5" data-title="Registration Modes & Ticketing">
            <div class="space-y-5 max-w-5xl">
                <span class="text-brand-400 text-xs font-semibold tracking-wider uppercase">Sales & Ticketing Flexibility</span>
                <h2 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight">
                    5 Native Registration Modes & Fraud-Proof Tickets
                </h2>
                <p class="text-base sm:text-lg text-slate-300 font-light max-w-3xl">
                    Conferences aren't standard e-commerce stores. MiConvener provides 5 distinct registration modes tailored to high-profile corporate, academic, and medical summits.
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-5 gap-3 pt-2">
                    <div class="glass-panel p-4 rounded-xl text-center">
                        <div class="text-brand-400 font-bold text-xs uppercase mb-1">Mode 1</div>
                        <div class="font-bold text-white text-sm mb-1.5">Auto-Confirm</div>
                        <p class="text-[11px] text-slate-400">Instant registration, tag ID issuance, and ticket confirmation.</p>
                    </div>

                    <div class="glass-panel p-4 rounded-xl text-center">
                        <div class="text-brand-400 font-bold text-xs uppercase mb-1">Mode 2</div>
                        <div class="font-bold text-white text-sm mb-1.5">Manual Approval</div>
                        <p class="text-[11px] text-slate-400">Organizers vet applicants; bulk approve or reject with reasons.</p>
                    </div>

                    <div class="glass-panel p-4 rounded-xl text-center">
                        <div class="text-brand-400 font-bold text-xs uppercase mb-1">Mode 3</div>
                        <div class="font-bold text-white text-sm mb-1.5">Payment-Gated</div>
                        <p class="text-[11px] text-slate-400">Hold ticket inventory with TTL release; confirms on payment success.</p>
                    </div>

                    <div class="glass-panel p-4 rounded-xl text-center">
                        <div class="text-brand-400 font-bold text-xs uppercase mb-1">Mode 4</div>
                        <div class="font-bold text-white text-sm mb-1.5">Approve + Pay</div>
                        <p class="text-[11px] text-slate-400">Vetted delegates receive an expiring payment link (fellowships/abstracts).</p>
                    </div>

                    <div class="glass-panel p-4 rounded-xl text-center">
                        <div class="text-brand-400 font-bold text-xs uppercase mb-1">Mode 5</div>
                        <div class="font-bold text-white text-sm mb-1.5">Invite-Only</div>
                        <p class="text-[11px] text-slate-400">Access codes or personalized VIP tokens unlock private ticket tiers.</p>
                    </div>
                </div>

                <div class="glass-panel p-5 rounded-xl flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div class="space-y-1">
                        <div class="text-sm font-bold text-white flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
                            Cryptographic QR Tokens + Non-Guessable Human Tag IDs
                        </div>
                        <p class="text-xs text-slate-400">
                            Every confirmed attendee receives a collision-free Tag ID (<code class="text-brand-300 font-mono">EVT24-8F3K-221</code>) safe to read aloud at registration desks, paired with an HMAC-signed QR token that defeats screenshot sharing.
                        </p>
                    </div>
                    <div class="shrink-0 text-xs font-mono px-3 py-1.5 rounded bg-white/5 border border-white/10 text-slate-300">
                        Zero Ticket Scalping / Zero Duplicate Scans
                    </div>
                </div>
            </div>
        </article>

        <!-- SLIDE 6: FINANCIAL INTEGRITY & SETTLEMENT -->
        <article class="slide w-full flex-col justify-center min-h-[580px]" data-slide="6" data-title="Payments & Settlement">
            <div class="space-y-5 max-w-5xl">
                <span class="text-brand-400 text-xs font-semibold tracking-wider uppercase">Revenue & Commercial Control</span>
                <h2 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight">
                    Mobile Money, Global Cards & Double-Entry Settlement
                </h2>
                <p class="text-base sm:text-lg text-slate-300 font-light max-w-3xl">
                    Eliminate payment reconciliation headaches. Collect payments across Africa and the world, backed by an immutable ledger and instant automated settlement.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pt-2">
                    <div class="glass-panel p-5 rounded-xl">
                        <div class="text-sm font-bold text-white mb-2 flex items-center gap-2">
                            <span class="text-emerald-400">●</span> Native Payment Rails
                        </div>
                        <p class="text-xs text-slate-400 leading-relaxed mb-3">
                            Direct mobile money collection (MTN MoMo, Telecel Cash, AirtelTigo) alongside Visa, Mastercard, and Apple Pay through Paystack, Flutterwave, and Stripe.
                        </p>
                        <div class="text-[11px] font-mono text-slate-300 bg-white/5 p-2 rounded">
                            Currencies: GHS, NGN, USD, GBP, EUR with automated FX tracking.
                        </div>
                    </div>

                    <div class="glass-panel p-5 rounded-xl">
                        <div class="text-sm font-bold text-white mb-2 flex items-center gap-2">
                            <span class="text-brand-400">●</span> Dual Settlement Freedom
                        </div>
                        <p class="text-xs text-slate-400 leading-relaxed mb-3">
                            <strong>Platform Default:</strong> Zero gateway setup required; start selling in 60 seconds.<br>
                            <strong>Own Gateway (BYO):</strong> Direct your funds straight into your own merchant credentials.
                        </p>
                        <div class="text-[11px] font-mono text-slate-300 bg-white/5 p-2 rounded">
                            Zero vendor lock-in. Complete cash sovereignty.
                        </div>
                    </div>

                    <div class="glass-panel p-5 rounded-xl">
                        <div class="text-sm font-bold text-white mb-2 flex items-center gap-2">
                            <span class="text-indigo-400">●</span> Double-Entry Ledger
                        </div>
                        <p class="text-xs text-slate-400 leading-relaxed mb-3">
                            Immutable, append-only ledger entries for every charge, fee, refund, and payout. Bank and mobile money name-enquiry verifies recipient accounts prior to send.
                        </p>
                        <div class="text-[11px] font-mono text-slate-300 bg-white/5 p-2 rounded">
                            Automated reconciliation cron catches stranded transfers.
                        </div>
                    </div>
                </div>
            </div>
        </article>

        <!-- SLIDE 7: ON-SITE OPERATIONS: CHECK-IN & NAME TAGS -->
        <article class="slide w-full flex-col justify-center min-h-[580px]" data-slide="7" data-title="Gate Operations & Name Tags">
            <div class="space-y-5 max-w-5xl">
                <span class="text-brand-400 text-xs font-semibold tracking-wider uppercase">Production-Grade On-Site Operations</span>
                <h2 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight">
                    Sub-Second Check-In & Live Name Tag Printing
                </h2>
                <p class="text-base sm:text-lg text-slate-300 font-light max-w-3xl">
                    The registration desk is your event's first impression. Deliver a VIP gate experience with zero queues, zero Wi-Fi dependencies, and instant badge printing.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pt-2">
                    <div class="glass-panel p-5 rounded-xl">
                        <div class="text-sm font-bold text-white mb-2 flex items-center gap-2">
                            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                            Offline-First PWA Scanner
                        </div>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Underground hotel ballrooms and concrete convention centers lose Wi-Fi. Our offline PWA scanner caches the entire guest list, validates scans in <500ms, and auto-syncs when reconnected.
                        </p>
                        <div class="mt-3 text-[11px] font-mono text-emerald-300 bg-emerald-950/40 p-2 rounded">
                            Anti-passback: Blocks duplicate entries & screenshot re-use immediately.
                        </div>
                    </div>

                    <div class="glass-panel p-5 rounded-xl">
                        <div class="text-sm font-bold text-white mb-2 flex items-center gap-2">
                            <svg class="w-4 h-4 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" /></svg>
                            Print Tags with Names & Roles
                        </div>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            In-app canvas badge designer: automatically binds attendee full name, company/institution, category color bands (Speaker, VIP, Delegate), seat/table assignments, and QR code.
                        </p>
                        <div class="mt-3 text-[11px] font-mono text-brand-300 bg-brand-950/40 p-2 rounded">
                            1-Click Batch PDF sheets or live on-demand thermal printing (Zebra/Evolis).
                        </div>
                    </div>

                    <div class="glass-panel p-5 rounded-xl">
                        <div class="text-sm font-bold text-white mb-2 flex items-center gap-2">
                            <svg class="w-4 h-4 text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                            Live Gate & Room Throughput
                        </div>
                        <p class="text-xs text-slate-400 leading-relaxed">
                            Real-time control room tracking gate scans per minute, total checked-in count, and room occupancy vs fire safety limits across all breakout halls.
                        </p>
                        <div class="mt-3 text-[11px] font-mono text-sky-300 bg-sky-950/40 p-2 rounded">
                            Fuzzy search by name, email, or phone for walk-ins & lost tickets.
                        </div>
                    </div>
                </div>
            </div>
        </article>

        <!-- SLIDE 8: IN-SESSION ROOM MANAGEMENT & LUNCH LOGISTICS -->
        <article class="slide w-full flex-col justify-center min-h-[580px]" data-slide="8" data-title="Room Management & Lunch Logistics">
            <div class="space-y-5 max-w-5xl">
                <span class="text-brand-400 text-xs font-semibold tracking-wider uppercase">During-Event Operations</span>
                <h2 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight">
                    In-Seat Water & Tech Requests + Live Lunch Tracking
                </h2>
                <p class="text-base sm:text-lg text-slate-300 font-light max-w-3xl">
                    Deliver five-star hospitality inside the conference hall and maintain 100% order during the high-stress lunch rush.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 pt-2">
                    <!-- In-Seat Service Requests -->
                    <div class="glass-panel p-6 rounded-xl border-t-2 border-t-brand-500">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-base font-bold text-white flex items-center gap-2">
                                <svg class="w-5 h-5 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
                                In-Seat Service Requests (Room Management)
                            </h3>
                            <span class="code-pill text-[10px] text-brand-300 bg-brand-950/60 px-2 py-0.5 rounded border border-brand-500/30">SLA Triage</span>
                        </div>
                        <p class="text-xs text-slate-300 leading-relaxed mb-4">
                            Attendees discreetly raise requests from their phone directly from their seat without interrupting the speaker or waving at ushers.
                        </p>
                        <div class="grid grid-cols-2 gap-2 text-xs text-slate-400 mb-4">
                            <div class="bg-white/5 p-2 rounded flex items-center gap-2">
                                <span class="text-brand-400 font-bold">💧</span> Water & Refreshments
                            </div>
                            <div class="bg-white/5 p-2 rounded flex items-center gap-2">
                                <span class="text-brand-400 font-bold">🎤</span> Mic & Audio Assistance
                            </div>
                            <div class="bg-white/5 p-2 rounded flex items-center gap-2">
                                <span class="text-brand-400 font-bold">❄️</span> Room Temperature / AC
                            </div>
                            <div class="bg-white/5 p-2 rounded flex items-center gap-2">
                                <span class="text-rose-400 font-bold">🚨</span> Medical / Emergency Help
                            </div>
                        </div>
                        <p class="text-[11px] text-slate-400">
                            Staff operations console routes requests with SLA countdown timers, staff assignments, and priority escalations.
                        </p>
                    </div>

                    <!-- During-Event Lunch Management -->
                    <div class="glass-panel p-6 rounded-xl border-t-2 border-t-emerald-500">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-base font-bold text-white flex items-center gap-2">
                                <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>
                                During-Event Lunch & Menu Tracking
                            </h3>
                            <span class="code-pill text-[10px] text-emerald-300 bg-emerald-950/60 px-2 py-0.5 rounded border border-emerald-500/30">No Double Dipping</span>
                        </div>
                        <p class="text-xs text-slate-300 leading-relaxed mb-4">
                            Prevent catering shortages and confusion. Staff scan attendee badges at the food station to instantly verify meal selection and entitlement.
                        </p>
                        <div class="space-y-2 text-xs text-slate-400 mb-4">
                            <div class="bg-white/5 p-2 rounded flex items-center justify-between">
                                <span>Instant Menu Display:</span>
                                <span class="text-emerald-400 font-bold">"Vegetarian Curry" or "Grilled Chicken"</span>
                            </div>
                            <div class="bg-white/5 p-2 rounded flex items-center justify-between">
                                <span>Fraud / Re-use Block:</span>
                                <span class="text-rose-400 font-bold">Flags badge if lunch already claimed</span>
                            </div>
                            <div class="bg-white/5 p-2 rounded flex items-center justify-between">
                                <span>Live Kitchen Telemetry:</span>
                                <span class="text-sky-400 font-bold">Meals served vs. meals remaining</span>
                            </div>
                        </div>
                        <p class="text-[11px] text-slate-400">
                            Catering teams avoid costly food over-ordering while ensuring special dietary delegates get their exact meals.
                        </p>
                    </div>
                </div>
            </div>
        </article>

        <!-- SLIDE 9: ENGAGEMENT: QUIZZES, POLLS & PPT SHARING -->
        <article class="slide w-full flex-col justify-center min-h-[580px]" data-slide="9" data-title="Engagement & PowerPoint Sharing">
            <div class="space-y-5 max-w-5xl">
                <span class="text-brand-400 text-xs font-semibold tracking-wider uppercase">Audience Interaction & Knowledge Sharing</span>
                <h2 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight">
                    Live Quizzes, Moderated Polls & PowerPoint Sharing
                </h2>
                <p class="text-base sm:text-lg text-slate-300 font-light max-w-3xl">
                    Replace expensive $1,500/year third-party polling software. Built-in interactive tools keep audiences engaged and slide distribution controlled.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pt-2">
                    <div class="glass-panel p-5 rounded-xl">
                        <div class="text-sm font-bold text-white mb-2 flex items-center gap-2">
                            <span class="text-purple-400">⚡</span> Live Quizzes & Leaderboards
                        </div>
                        <p class="text-xs text-slate-400 leading-relaxed mb-3">
                            Run high-energy gamified quizzes with timers, point multipliers, and negative marking. Delegates join instantly via QR.
                        </p>
                        <div class="text-[11px] font-mono text-purple-300 bg-purple-950/40 p-2 rounded">
                            Presentation mode displays live animated leaderboards on projection screens.
                        </div>
                    </div>

                    <div class="glass-panel p-5 rounded-xl">
                        <div class="text-sm font-bold text-white mb-2 flex items-center gap-2">
                            <span class="text-sky-400">📊</span> Moderated Live Polls & Q&A
                        </div>
                        <p class="text-xs text-slate-400 leading-relaxed mb-3">
                            Real-time polls, word clouds, and threaded Q&A with upvoting. Organizer moderation queue filters inappropriate questions before they hit the big screen.
                        </p>
                        <div class="text-[11px] font-mono text-sky-300 bg-sky-950/40 p-2 rounded">
                            Tag official answers, pin key takeaways, and summarize with AI.
                        </div>
                    </div>

                    <div class="glass-panel p-5 rounded-xl">
                        <div class="text-sm font-bold text-white mb-2 flex items-center gap-2">
                            <span class="text-emerald-400">📁</span> PowerPoint & Slide Sharing
                        </div>
                        <p class="text-xs text-slate-400 leading-relaxed mb-3">
                            Share presentations, lecture slides, and handouts securely. Embargo materials until the session starts to keep attendees focused on the speaker.
                        </p>
                        <div class="text-[11px] font-mono text-emerald-300 bg-emerald-950/40 p-2 rounded">
                            Watermarking + download attempt quotas protect intellectual property.
                        </div>
                    </div>
                </div>
            </div>
        </article>

        <!-- SLIDE 10: POST-EVENT: CERTIFICATES & 10 EXPORTS -->
        <article class="slide w-full flex-col justify-center min-h-[580px]" data-slide="10" data-title="Certificates & Enterprise Reports">
            <div class="space-y-5 max-w-5xl">
                <span class="text-brand-400 text-xs font-semibold tracking-wider uppercase">Post-Event Closeout</span>
                <h2 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight">
                    Automated Certificates & 10 Enterprise Exports
                </h2>
                <p class="text-base sm:text-lg text-slate-300 font-light max-w-3xl">
                    Closing out an event usually takes weeks of manual work. MiConvener automates attendance certification and exports complete audit data in seconds.
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 pt-2">
                    <!-- Certificates of Participation -->
                    <div class="glass-panel p-6 rounded-xl">
                        <div class="text-base font-bold text-white mb-2 flex items-center gap-2">
                            <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" /></svg>
                            Certificates of Participation & CME/CPD Credits
                        </div>
                        <p class="text-xs text-slate-300 leading-relaxed mb-4">
                            Eliminate manual certificate mail merges. MiConvener automatically issues verifiable, personalized attendance certificates based on verified check-ins and session attendance scans.
                        </p>
                        <div class="space-y-1.5 text-xs text-slate-400">
                            <div class="flex items-center gap-2">
                                <span class="text-emerald-400">✓</span> Verified check-in threshold requirements
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-emerald-400">✓</span> CPD / CME accredited credit hours calculated automatically
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-emerald-400">✓</span> Attendees download directly from their portal or bulk ZIP export
                            </div>
                        </div>
                    </div>

                    <!-- 10 Enterprise Exports -->
                    <div class="glass-panel p-6 rounded-xl">
                        <div class="text-base font-bold text-white mb-2 flex items-center gap-2">
                            <svg class="w-5 h-5 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                            10 Asynchronous Formatted Excel & PDF Exports
                        </div>
                        <p class="text-xs text-slate-300 leading-relaxed mb-3">
                            Instant streaming multi-sheet exports designed for enterprise reporting, sponsors, and auditing bodies:
                        </p>
                        <div class="grid grid-cols-2 gap-1.5 text-[11px] text-slate-400 font-mono">
                            <div class="bg-white/5 px-2 py-1 rounded">1. Registrations & Forms</div>
                            <div class="bg-white/5 px-2 py-1 rounded">2. Check-In Audit Logs</div>
                            <div class="bg-white/5 px-2 py-1 rounded">3. Session Attendance (CPD)</div>
                            <div class="bg-white/5 px-2 py-1 rounded">4. Dietary & Accessibility</div>
                            <div class="bg-white/5 px-2 py-1 rounded">5. Financial Settlement</div>
                            <div class="bg-white/5 px-2 py-1 rounded">6. Sponsor Deliverables</div>
                            <div class="bg-white/5 px-2 py-1 rounded">7. Poll & Quiz Analytics</div>
                            <div class="bg-white/5 px-2 py-1 rounded">8. Forum & AI Summaries</div>
                        </div>
                    </div>
                </div>
            </div>
        </article>

        <!-- SLIDE 11: THE ECONOMICS: ROI & COST CALCULATOR -->
        <article class="slide w-full flex-col justify-center min-h-[580px]" data-slide="11" data-title="ROI & Cost Savings">
            <div class="space-y-4 max-w-5xl">
                <span class="text-brand-400 text-xs font-semibold tracking-wider uppercase">Hard Economics</span>
                <h2 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight">
                    Save 65–80% in Tool Subscriptions & 120+ Staff Hours
                </h2>
                <p class="text-sm sm:text-base text-slate-300 font-light max-w-3xl">
                    Replace 5 bloated SaaS invoices with one consolidated platform. See the actual financial return on your next event:
                </p>

                <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 pt-1">
                    <!-- Comparison Table (7 cols) -->
                    <div class="lg:col-span-7 glass-panel p-5 rounded-xl space-y-3">
                        <div class="text-xs font-bold uppercase tracking-wider text-slate-400 border-b border-white/10 pb-2 flex justify-between">
                            <span>Traditional Multi-Vendor Stack</span>
                            <span>Est. Annual / Per-Event Cost</span>
                        </div>
                        <div class="space-y-2 text-xs text-slate-300">
                            <div class="flex justify-between items-center py-1 border-b border-white/5">
                                <span>Ticketing Fees (3.5% + $1.50 per ticket on $100k sales)</span>
                                <span class="font-mono text-rose-400 font-semibold">$5,000</span>
                            </div>
                            <div class="flex justify-between items-center py-1 border-b border-white/5">
                                <span>Live Audience Q&A / Polling (Slido / Mentimeter)</span>
                                <span class="font-mono text-rose-400 font-semibold">$1,200</span>
                            </div>
                            <div class="flex justify-between items-center py-1 border-b border-white/5">
                                <span>Event App & Community (Whova / Hopin)</span>
                                <span class="font-mono text-rose-400 font-semibold">$3,500</span>
                            </div>
                            <div class="flex justify-between items-center py-1 border-b border-white/5">
                                <span>Badge & Tag Printing Software License</span>
                                <span class="font-mono text-rose-400 font-semibold">$800</span>
                            </div>
                            <div class="flex justify-between items-center py-1 border-b border-white/5">
                                <span>Certificate of Attendance Generator Tool</span>
                                <span class="font-mono text-rose-400 font-semibold">$600</span>
                            </div>
                            <div class="flex justify-between items-center pt-2 font-bold text-sm text-white">
                                <span>Total Disconnected Stack Cost</span>
                                <span class="font-mono text-rose-400 text-base">$11,100 + 120 hrs</span>
                            </div>
                        </div>
                        <div class="mt-2 p-2.5 rounded bg-brand-950/40 border border-brand-500/30 text-xs text-brand-300">
                            <strong>With MiConvener:</strong> All included in one unified subscription + low transparent transaction fees. 
                        </div>
                    </div>

                    <!-- Interactive ROI Calculator Widget (5 cols) -->
                    <div class="lg:col-span-5 glass-panel p-5 rounded-xl space-y-4 bg-gradient-to-b from-brand-950/40 to-obsidian-900 border-brand-500/40">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-white uppercase tracking-wider">Interactive ROI Calculator</span>
                            <span class="text-[10px] text-brand-400 font-mono">Live Simulation</span>
                        </div>

                        <!-- Attendees Slider -->
                        <div class="space-y-1.5">
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-400">Expected Delegates:</span>
                                <span id="calc-attendees-val" class="font-bold text-white font-mono">500</span>
                            </div>
                            <input id="calc-attendees" type="range" min="100" max="3000" step="50" value="500" oninput="updateRoiCalc()" class="w-full h-1.5 bg-slate-700 rounded-lg appearance-none cursor-pointer accent-brand-500">
                        </div>

                        <!-- Ticket Price Slider -->
                        <div class="space-y-1.5">
                            <div class="flex justify-between text-xs">
                                <span class="text-slate-400">Avg. Ticket Price ({{ $currency }}):</span>
                                <span id="calc-price-val" class="font-bold text-white font-mono">{{ $currency }} 250</span>
                            </div>
                            <input id="calc-price" type="range" min="0" max="1500" step="25" value="250" oninput="updateRoiCalc()" class="w-full h-1.5 bg-slate-700 rounded-lg appearance-none cursor-pointer accent-brand-500">
                        </div>

                        <!-- Dynamic Output Cards -->
                        <div class="grid grid-cols-2 gap-2 pt-2 border-t border-white/10">
                            <div class="bg-white/5 p-2.5 rounded text-center">
                                <div class="text-[10px] uppercase text-slate-400">Estimated Savings</div>
                                <div id="calc-savings" class="text-base font-extrabold text-emerald-400 font-mono mt-0.5">{{ $currency }} 18,500</div>
                            </div>
                            <div class="bg-white/5 p-2.5 rounded text-center">
                                <div class="text-[10px] uppercase text-slate-400">Staff Hours Saved</div>
                                <div id="calc-hours" class="text-base font-extrabold text-brand-400 font-mono mt-0.5">85 hrs</div>
                            </div>
                        </div>

                        <p class="text-[11px] text-slate-400 text-center italic">
                            Based on tool consolidation, elimination of manual data reconciliations, and reduced ticketing fees.
                        </p>
                    </div>
                </div>
            </div>
        </article>

        <!-- SLIDE 12: THE PROFESSIONALISM DIVIDEND & CTA -->
        <article class="slide w-full flex-col justify-center min-h-[580px]" data-slide="12" data-title="Take Command & Next Steps">
            <div class="space-y-6 max-w-4xl text-center mx-auto">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs font-semibold tracking-wide uppercase">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    Elevate Your Organization's Reputation
                </div>

                <h2 class="text-3xl sm:text-5xl font-extrabold text-white tracking-tight leading-tight">
                    Deliver An Uncompromising Standard of Event Professionalism
                </h2>

                <p class="text-base sm:text-lg text-slate-300 font-light max-w-2xl mx-auto">
                    Your attendees, distinguished speakers, and corporate sponsors notice every detail. Give them a seamless, modern experience from their first ticket invite to post-event certification.
                </p>

                <!-- Value Pillars Summary -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-left max-w-3xl mx-auto pt-2">
                    <div class="glass-panel p-4 rounded-xl">
                        <div class="text-emerald-400 font-bold text-xs uppercase mb-1">For Attendees</div>
                        <p class="text-xs text-slate-300">1-second gate check-in, personalized name badges, in-seat water requests, and instant attendance certificates.</p>
                    </div>

                    <div class="glass-panel p-4 rounded-xl">
                        <div class="text-brand-400 font-bold text-xs uppercase mb-1">For Speakers</div>
                        <p class="text-xs text-slate-300">A friction-free private portal to submit bios, conflict disclosures, and PowerPoint decks without email chains.</p>
                    </div>

                    <div class="glass-panel p-4 rounded-xl">
                        <div class="text-sky-400 font-bold text-xs uppercase mb-1">For Executives</div>
                        <p class="text-xs text-slate-300">Immediate double-entry settlement, mobile money revenues in bank, and comprehensive sponsor ROI reports.</p>
                    </div>
                </div>

                <!-- Call to Action Buttons -->
                <div class="pt-6 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="/product-enterprise" class="w-full sm:w-auto px-7 py-3.5 rounded-xl bg-brand-500 hover:bg-brand-600 font-bold text-white shadow-lg shadow-brand-500/30 transition-all transform hover:-translate-y-0.5 text-sm flex items-center justify-center gap-2">
                        <span>Schedule a 20-Minute Production Walkthrough</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" /></svg>
                    </a>

                    <a href="mailto:{{ $enterpriseEmail }}?subject=MiConvener%20Event%20Inquiry" class="w-full sm:w-auto px-6 py-3.5 rounded-xl bg-white/10 hover:bg-white/15 border border-white/15 font-semibold text-white transition-all text-sm flex items-center justify-center gap-2">
                        <svg class="w-4 h-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                        <span>Contact Enterprise Sales</span>
                    </a>
                </div>
            </div>
        </article>

    </main>

    <!-- FLOATING BOTTOM CONTROL DOCK -->
    <footer class="no-print relative z-20 px-6 py-4 flex items-center justify-between border-t border-white/5 backdrop-blur-md">
        <!-- Slide Progress & Counter -->
        <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
                <span id="slide-num" class="font-mono text-base font-bold text-white">01</span>
                <span class="text-xs text-slate-500">/</span>
                <span id="slide-total" class="font-mono text-xs text-slate-500">12</span>
            </div>

            <!-- Visual Progress Bar -->
            <div class="w-32 sm:w-48 h-1.5 bg-white/10 rounded-full overflow-hidden hidden sm:block">
                <div id="slide-progress" class="h-full bg-brand-500 transition-all duration-300 w-[8.33%]"></div>
            </div>

            <span id="slide-title" class="text-xs font-medium text-slate-400 truncate max-w-[140px] sm:max-w-[240px] hidden md:inline-block">
                The Manifesto
            </span>
        </div>

        <!-- Central Navigation Buttons -->
        <div class="flex items-center gap-2">
            <button onclick="prevSlide()" title="Previous Slide (← / J)" class="p-2.5 rounded-xl bg-white/5 hover:bg-white/10 border border-white/10 text-white transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" /></svg>
            </button>

            <!-- Grid Sorter Toggle Button -->
            <button onclick="toggleGridModal()" title="View All Slides Grid (O)" class="px-3 py-2 rounded-xl bg-white/5 hover:bg-white/10 border border-white/10 text-slate-300 hover:text-white transition-colors flex items-center gap-1.5 text-xs font-medium">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" /></svg>
                <span class="hidden sm:inline">Overview</span>
            </button>

            <button onclick="nextSlide()" title="Next Slide (→ / Space / K)" class="p-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white transition-colors shadow-md shadow-brand-500/20">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" /></svg>
            </button>
        </div>

        <!-- Shortcuts Help Trigger -->
        <div class="flex items-center gap-2">
            <button onclick="toggleShortcutsModal()" title="Keyboard Shortcuts (?)" class="text-xs text-slate-400 hover:text-white flex items-center gap-1.5 bg-white/5 px-2.5 py-1.5 rounded-lg border border-white/10 transition-colors">
                <span class="hidden lg:inline">Shortcuts</span>
                <span class="code-pill text-[10px] text-slate-400 bg-white/10 px-1 py-0.5 rounded">?</span>
            </button>

            <button onclick="toggleFullscreen()" title="Toggle Fullscreen (F)" class="p-2 rounded-lg text-slate-400 hover:text-white bg-white/5 hover:bg-white/10 border border-white/10 transition-colors">
                <svg id="fs-icon" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" /></svg>
            </button>
        </div>
    </footer>

    <!-- PRESENTER TALKING POINTS DRAWER (Press 'P') -->
    <aside id="notes-drawer" class="no-print fixed inset-y-0 right-0 w-full sm:w-[460px] bg-obsidian-900 border-l border-white/10 z-50 p-6 shadow-2xl transform translate-x-full transition-transform duration-300 ease-out flex flex-col justify-between">
        <div class="flex items-center justify-between pb-4 border-b border-white/10">
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-brand-400"></span>
                <h3 class="font-bold text-white text-sm uppercase tracking-wider">Presenter Talking Points</h3>
            </div>
            <button onclick="toggleNotesDrawer()" class="text-slate-400 hover:text-white p-1">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto py-5 space-y-6 text-sm">
            <div class="space-y-1">
                <span class="code-pill text-[11px] text-slate-500 uppercase">Current Slide</span>
                <h4 id="notes-slide-title" class="font-bold text-white text-base">The Manifesto</h4>
            </div>

            <!-- Production Manager Talking Point -->
            <div class="p-4 rounded-xl bg-brand-950/40 border border-brand-500/30 space-y-2">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-brand-400">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    Production Manager Script
                </div>
                <p id="notes-pm" class="text-xs text-slate-300 leading-relaxed">
                    Lead with operational peace of mind. Explain that event producers usually suffer sleepless nights worrying whether Canva badge merges match attendee registration lists, or whether venue Wi-Fi dropping will paralyze gate entry. MiConvener eliminates these catastrophic risks entirely.
                </p>
            </div>

            <!-- Sales Tech & Commercial Talking Point -->
            <div class="p-4 rounded-xl bg-emerald-950/40 border border-emerald-500/30 space-y-2">
                <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-emerald-400">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    Sales Tech & Commercial Script
                </div>
                <p id="notes-sales" class="text-xs text-slate-300 leading-relaxed">
                    Quantify the cost of chaos. Explain that paying $1,200 for Slido, $3,500 for Whova, 3.5% ticketing cuts, and separate badge printing fees burns over $10,000 per conference. MiConvener cuts software overhead by 70% while improving attendee checkout conversion with native Mobile Money.
                </p>
            </div>
        </div>

        <div class="pt-4 border-t border-white/10 flex items-center justify-between text-xs text-slate-500">
            <span>Press <kbd class="px-1.5 py-0.5 rounded bg-white/10 text-white font-mono text-[10px]">P</kbd> anytime to close</span>
            <span>MiConvener Pitch Guide</span>
        </div>
    </aside>

    <!-- SLIDE GRID OVERVIEW MODAL (Press 'O') -->
    <div id="grid-modal" class="no-print fixed inset-0 z-50 bg-obsidian-950/90 backdrop-blur-xl hidden flex-col p-6 sm:p-10 overflow-y-auto">
        <div class="max-w-6xl w-full mx-auto flex items-center justify-between pb-6 border-b border-white/10">
            <div>
                <h3 class="text-xl font-extrabold text-white">Slide Sorter & Overview</h3>
                <p class="text-xs text-slate-400">Click any slide to jump directly to it. Press Esc or O to close.</p>
            </div>
            <button onclick="toggleGridModal()" class="p-2 rounded-xl bg-white/5 hover:bg-white/10 text-slate-300">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
        </div>

        <div class="max-w-6xl w-full mx-auto grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4 py-8" id="grid-cards-container">
            <!-- Populated dynamically via JS -->
        </div>
    </div>

    <!-- KEYBOARD SHORTCUTS MODAL (Press '?') -->
    <div id="shortcuts-modal" class="no-print fixed inset-0 z-50 bg-obsidian-950/80 backdrop-blur-md hidden items-center justify-center p-4">
        <div class="bg-obsidian-900 border border-white/15 rounded-2xl p-6 max-w-md w-full shadow-2xl space-y-4">
            <div class="flex items-center justify-between border-b border-white/10 pb-3">
                <h4 class="font-bold text-white text-base">Keyboard Navigation</h4>
                <button onclick="toggleShortcutsModal()" class="text-slate-400 hover:text-white">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>

            <div class="space-y-2 text-xs text-slate-300">
                <div class="flex justify-between py-1.5 border-b border-white/5">
                    <span>Next Slide</span>
                    <span class="font-mono text-white bg-white/10 px-2 py-0.5 rounded">→ / Space / K</span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-white/5">
                    <span>Previous Slide</span>
                    <span class="font-mono text-white bg-white/10 px-2 py-0.5 rounded">← / J</span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-white/5">
                    <span>First / Last Slide</span>
                    <span class="font-mono text-white bg-white/10 px-2 py-0.5 rounded">Home / End</span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-white/5">
                    <span>Toggle Presenter Notes</span>
                    <span class="font-mono text-white bg-white/10 px-2 py-0.5 rounded">P</span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-white/5">
                    <span>Slide Sorter Overview</span>
                    <span class="font-mono text-white bg-white/10 px-2 py-0.5 rounded">O</span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-white/5">
                    <span>Toggle Fullscreen</span>
                    <span class="font-mono text-white bg-white/10 px-2 py-0.5 rounded">F</span>
                </div>
                <div class="flex justify-between py-1.5 border-b border-white/5">
                    <span>Copy Slide Link</span>
                    <span class="font-mono text-white bg-white/10 px-2 py-0.5 rounded">C</span>
                </div>
                <div class="flex justify-between py-1.5">
                    <span>Toggle Theme</span>
                    <span class="font-mono text-white bg-white/10 px-2 py-0.5 rounded">T</span>
                </div>
            </div>
        </div>
    </div>

    <!-- TOAST NOTIFICATION -->
    <div id="toast" class="no-print fixed bottom-20 left-1/2 -translate-x-1/2 px-4 py-2 rounded-xl bg-brand-600 text-white font-medium text-xs shadow-xl shadow-brand-500/20 z-50 opacity-0 pointer-events-none transition-opacity duration-200 flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
        <span id="toast-msg">Link copied to clipboard!</span>
    </div>

    <!-- INTERACTIVE SLIDE LOGIC & SCRIPTS -->
    <script>
        const slides = document.querySelectorAll('.slide');
        const totalSlides = slides.length;
        let currentSlide = 1;

        // Presenter Talking Points Scripts Database
        const presenterScripts = {
            1: {
                title: "The Manifesto",
                pm: "Emphasize event-day predictability. Event producers stay awake at night dreading registration gate bottlenecks, badge spelling errors, and speaker presentation mishaps. MiConvener eliminates all of these risks with an integrated architecture.",
                sales: "Lead with software sprawl and margin erosion. Enterprise organizations spend $10k+ across 5 subscriptions and lose days reconciling payments. Pitch the single sovereign subscription with native mobile money checkout."
            },
            2: {
                title: "The Industry Crisis",
                pm: "Walk through the nightmare scenario: When venue Wi-Fi fails in a basement ballroom, cloud-only ticketing apps crash, forming 45-minute queues. Attendees get irritated before the keynote even starts.",
                sales: "Ask the client: 'How many spreadsheets did your team manage for your last summit?' Point out that every CSV export/import is an invitation to lost registrations and compliance violations."
            },
            3: {
                title: "The Value Proposition",
                pm: "Highlight single-source-of-truth. When an attendee updates their dietary preference or session choice, it updates instantly across the check-in terminal, the badge printer, and the catering report.",
                sales: "Stress White-Label Sovereignty. On MiConvener, your summit lives on summit.yourdomain.com with your colors. We don't promote other conferences on your ticket confirmation pages like Eventbrite does."
            },
            4: {
                title: "Pre-Event & Speaker Engagement",
                pm: "Focus on the Speaker Portal: Faculty receive private links, upload PowerPoint files against hard deadlines, and submit COI disclosures. No more frantically chasing slides 5 minutes before stage time.",
                sales: "Emphasize automated attendee reminders and lunch forms. Capturing menu preferences (e.g. Jollof vs Vegan) during registration prevents caterer over-ordering and saves thousands on food waste."
            },
            5: {
                title: "Registration Modes & Ticketing",
                pm: "Explain that academic, medical, and corporate events cannot use simple e-commerce checkouts. Highlight Mode 2 (Manual vetting) and Mode 4 (Approve first, pay later) for scholarship and abstract presenters.",
                sales: "Demonstrate anti-scalping: Cryptographic signed QR codes + readable Tag IDs (EVT24-8F3K-221) prevent ticket forgery and duplicate screenshot transfers."
            },
            6: {
                title: "Payments & Settlement",
                pm: "Explain that gate staff never have to handle cash or guess if a mobile money transfer arrived. Payment confirmation is cryptographically locked to registration state.",
                sales: "Huge selling point for African and global events: Dual settlement allows organizers to bring their own Paystack/Stripe accounts or use the platform default. Native MTN MoMo, Telecel, and cards."
            },
            7: {
                title: "Gate Operations & Name Tags",
                pm: "The crown jewel: The offline-first PWA check-in scanner operates in underground venues with ZERO internet. Scans validate in <500ms and anti-passback blocks duplicate entries instantly.",
                sales: "Highlight the in-app canvas badge designer: Print personalized name tags with company, category color bands (VIP/Speaker), and QR codes directly at the desk or batch print sheets."
            },
            8: {
                title: "Room Management & Lunch Logistics",
                pm: "Two critical production tools: 1) In-seat service requests let VIPs and attendees discreetly ping staff for water, microphone help, or AC adjustments. 2) Lunch station QR scanning prevents attendees from double-dipping or taking the wrong meal.",
                sales: "This elevates the perceived prestige of the summit. When attendees can get water delivered to their seat and lunch lines move seamlessly, satisfaction scores (CSAT/NPS) jump dramatically."
            },
            9: {
                title: "Engagement & PowerPoint Sharing",
                pm: "Session materials repository: Slide decks and handouts can be scheduled for embargo release (only unlock when session starts) with watermarking and download attempt caps.",
                sales: "Eliminate $1,200 to $2,000 annual fees for Slido or Mentimeter. Live quizzes with timers and animated leaderboards run right on the projector."
            },
            10: {
                title: "Certificates & Enterprise Reports",
                pm: "Closing an event usually takes two weeks. With MiConvener, attendance and CME/CPD credit certificates are automatically generated from verified check-in data and delivered to attendee portals.",
                sales: "Showcase the 10 asynchronous formatted Excel & PDF exports: Dietary lists for caterers, session attendance logs for accrediting boards, and deliverable fulfillment sheets for sponsors."
            },
            11: {
                title: "ROI & Cost Savings",
                pm: "Highlight team burnout prevention: Over 120 staff hours saved from eliminating manual badge merges, payment cross-referencing, and usher walkie-talkie chaos.",
                sales: "Have the prospect interact with the live ROI calculator! Slide the attendees and ticket price to show them their exact net dollar or cedi savings."
            },
            12: {
                title: "Take Command & Next Steps",
                pm: "Offer a customized technical production dry-run for their specific upcoming conference floor plan.",
                sales: "Direct close: Propose setting up a live sandbox tenant in 60 seconds so their organizing committee can test the scanner and badge builder directly."
            }
        };

        // Initialize Slide Navigation from URL Hash
        function initSlideFromHash() {
            const hash = window.location.hash;
            if (hash && hash.startsWith('#slide-')) {
                const num = parseInt(hash.replace('#slide-', ''), 10);
                if (!isNaN(num) && num >= 1 && num <= totalSlides) {
                    currentSlide = num;
                }
            }
            showSlide(currentSlide);
            buildGridCards();
        }

        // Show Specific Slide
        function showSlide(index) {
            if (index < 1) index = 1;
            if (index > totalSlides) index = totalSlides;
            currentSlide = index;

            slides.forEach(slide => {
                const sIndex = parseInt(slide.dataset.slide, 10);
                if (sIndex === currentSlide) {
                    slide.classList.add('active');
                } else {
                    slide.classList.remove('active');
                }
            });

            // Update Counters & Progress Bar
            document.getElementById('slide-num').innerText = currentSlide.toString().padStart(2, '0');
            const pct = (currentSlide / totalSlides) * 100;
            document.getElementById('slide-progress').style.width = pct + '%';

            const activeSlideElem = document.querySelector(`.slide[data-slide="${currentSlide}"]`);
            const slideTitle = activeSlideElem?.dataset.title || 'Slide ' + currentSlide;
            document.getElementById('slide-title').innerText = slideTitle;

            // Update Presenter Notes
            const script = presenterScripts[currentSlide] || { title: slideTitle, pm: 'General talking point.', sales: 'General commercial point.' };
            document.getElementById('notes-slide-title').innerText = script.title;
            document.getElementById('notes-pm').innerText = script.pm;
            document.getElementById('notes-sales').innerText = script.sales;

            // Sync URL hash without triggering full reload
            history.replaceState(null, null, '#slide-' + currentSlide);
        }

        function nextSlide() {
            if (currentSlide < totalSlides) {
                showSlide(currentSlide + 1);
            }
        }

        function prevSlide() {
            if (currentSlide > 1) {
                showSlide(currentSlide - 1);
            }
        }

        // Keyboard Shortcut Listeners
        window.addEventListener('keydown', (e) => {
            // Ignore when typing inside an input or textarea
            if (['input', 'textarea'].includes(e.target.tagName.toLowerCase())) return;

            switch (e.key) {
                case 'ArrowRight':
                case ' ':
                case 'k':
                case 'K':
                    e.preventDefault();
                    nextSlide();
                    break;
                case 'ArrowLeft':
                case 'j':
                case 'J':
                    e.preventDefault();
                    prevSlide();
                    break;
                case 'Home':
                    e.preventDefault();
                    showSlide(1);
                    break;
                case 'End':
                    e.preventDefault();
                    showSlide(totalSlides);
                    break;
                case 'p':
                case 'P':
                    e.preventDefault();
                    toggleNotesDrawer();
                    break;
                case 'o':
                case 'O':
                    e.preventDefault();
                    toggleGridModal();
                    break;
                case 'f':
                case 'F':
                    e.preventDefault();
                    toggleFullscreen();
                    break;
                case 't':
                case 'T':
                    e.preventDefault();
                    toggleTheme();
                    break;
                case 'c':
                case 'C':
                    e.preventDefault();
                    copyCurrentSlideUrl();
                    break;
                case '?':
                    e.preventDefault();
                    toggleShortcutsModal();
                    break;
                case 'Escape':
                    closeAllModals();
                    break;
            }
        });

        // Touch Swipe Navigation for Mobile / Tablets
        let touchStartX = 0;
        let touchEndX = 0;
        document.addEventListener('touchstart', e => {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });
        document.addEventListener('touchend', e => {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        }, { passive: true });

        function handleSwipe() {
            const threshold = 50;
            if (touchEndX < touchStartX - threshold) {
                nextSlide();
            } else if (touchEndX > touchStartX + threshold) {
                prevSlide();
            }
        }

        // Presenter Notes Drawer Toggle
        function toggleNotesDrawer() {
            const drawer = document.getElementById('notes-drawer');
            drawer.classList.toggle('translate-x-full');
        }

        // Grid Overview Modal Toggle
        function toggleGridModal() {
            const modal = document.getElementById('grid-modal');
            modal.classList.toggle('hidden');
            modal.classList.toggle('flex');
        }

        // Shortcuts Modal Toggle
        function toggleShortcutsModal() {
            const modal = document.getElementById('shortcuts-modal');
            modal.classList.toggle('hidden');
            modal.classList.toggle('flex');
        }

        function closeAllModals() {
            document.getElementById('grid-modal').classList.add('hidden');
            document.getElementById('grid-modal').classList.remove('flex');
            document.getElementById('shortcuts-modal').classList.add('hidden');
            document.getElementById('shortcuts-modal').classList.remove('flex');
            document.getElementById('notes-drawer').classList.add('translate-x-full');
        }

        // Build Grid Cards
        function buildGridCards() {
            const container = document.getElementById('grid-cards-container');
            container.innerHTML = '';

            slides.forEach((slide, idx) => {
                const sNum = idx + 1;
                const title = slide.dataset.title || 'Slide ' + sNum;
                const card = document.createElement('div');
                card.className = `p-4 rounded-xl cursor-pointer border transition-all hover:scale-102 ${sNum === currentSlide ? 'bg-brand-900/40 border-brand-500' : 'bg-obsidian-850 border-white/10 hover:border-white/20'}`;
                card.innerHTML = `
                    <div class="flex items-center justify-between text-xs mb-2">
                        <span class="font-mono text-brand-400 font-bold">${sNum.toString().padStart(2, '0')}</span>
                        <span class="text-[10px] text-slate-500">Jump ↗</span>
                    </div>
                    <div class="text-sm font-bold text-white line-clamp-2">${title}</div>
                `;
                card.onclick = () => {
                    showSlide(sNum);
                    toggleGridModal();
                };
                container.appendChild(card);
            });
        }

        // Fullscreen Toggle
        function toggleFullscreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(() => {});
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                }
            }
        }

        // Light/Dark Theme Toggle
        function toggleTheme() {
            document.documentElement.classList.toggle('light');
            const isLight = document.documentElement.classList.contains('light');
            document.getElementById('theme-icon-sun').classList.toggle('hidden', !isLight);
            document.getElementById('theme-icon-moon').classList.toggle('hidden', isLight);
        }

        // Copy Shareable URL
        function copyCurrentSlideUrl() {
            const url = window.location.origin + window.location.pathname + '#slide-' + currentSlide;
            navigator.clipboard.writeText(url).then(() => {
                showToast('Slide link copied: #slide-' + currentSlide);
            }).catch(() => {
                showToast('Link ready: ' + url);
            });
        }

        function showToast(msg) {
            const toast = document.getElementById('toast');
            document.getElementById('toast-msg').innerText = msg;
            toast.classList.remove('opacity-0', 'pointer-events-none');
            setTimeout(() => {
                toast.classList.add('opacity-0', 'pointer-events-none');
            }, 2500);
        }

        // Interactive ROI Calculator Logic
        function updateRoiCalc() {
            const attendees = parseInt(document.getElementById('calc-attendees').value, 10);
            const price = parseInt(document.getElementById('calc-price').value, 10);
            const currency = "{{ $currency }}";

            document.getElementById('calc-attendees-val').innerText = attendees.toLocaleString();
            document.getElementById('calc-price-val').innerText = currency + ' ' + price.toLocaleString();

            const grossRevenue = attendees * price;

            // Estimated costs of legacy disconnected tools:
            // Ticketing fees: 3.5% + $1.50 per ticket
            const ticketingFee = (grossRevenue * 0.035) + (attendees * 1.5);
            // Third-party add-ons: Slido ($1,200) + App ($3,500) + Badge ($800) + Certs ($600)
            const addOnsCost = 6100;
            const totalLegacy = ticketingFee + addOnsCost;

            // MiConvener estimate: unified platform fee + minimal 1.5% processing
            const miconvenerCost = Math.min(2500, grossRevenue * 0.02 + 800);
            const netSavings = Math.max(1500, Math.round(totalLegacy - miconvenerCost));

            // Staff hours saved: 0.15 hours per attendee + 30 baseline hours
            const hoursSaved = Math.round(30 + (attendees * 0.12));

            document.getElementById('calc-savings').innerText = currency + ' ' + netSavings.toLocaleString();
            document.getElementById('calc-hours').innerText = hoursSaved + ' hrs';
        }

        // Initialize on Load
        document.addEventListener('DOMContentLoaded', () => {
            initSlideFromHash();
            updateRoiCalc();
        });
    </script>
</body>
</html>
