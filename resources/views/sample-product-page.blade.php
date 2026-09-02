@php
    /**
     * EDIT ONLY THIS ARRAY PER PRODUCT
     */
    $product = [
        'brand' => ['name' => 'YourProduct', 'logo_text' => 'YP'],

        'nav' => [
            'links' => [
                ['label' => 'Pricing', 'href' => '#pricing'],
                ['label' => 'Documentation', 'href' => '#docs'],
                ['label' => 'Enterprise', 'href' => '#enterprise'],
            ],
            'cta_secondary' => ['label' => 'Sign in', 'href' => '/login'],
            'cta_primary' => ['label' => 'Start for free', 'href' => '/register'],
        ],

        'hero' => [
            'title' => "Don’t be afraid\nof the dark",
            'subtitle' => 'A crisp product description that explains the value in one or two lines.',
            'cta_primary' => ['label' => 'Start monitoring', 'href' => '/register'],
            'cta_secondary' => ['label' => 'Contact sales', 'href' => '/contact'],
            'media' => [
                'type' => 'image', // image|video
                'src' => 'https://images.unsplash.com/photo-1555066931-4365d14bab8c?auto=format&fit=crop&w=2400&q=60',
                'alt' => 'Product screenshot',
            ],
        ],

        'intro' => [
            'eyebrow' => 'Events',
            'title' => 'Start monitoring in under a minute',
            'body' => 'A short paragraph explaining quick setup and instant visibility—no matter where you deploy.',
            'cards' => [
                ['kicker' => 'Routes', 'title' => '9 routes exceeded thresholds', 'body' => 'Turn raw signals into clean, actionable insights.'],
                ['kicker' => 'Exceptions', 'title' => '145 exceptions in 24 hours', 'body' => 'Group, dedupe, and prioritize issues automatically.'],
                ['kicker' => 'Alerts', 'title' => 'Smart alerts made simple', 'body' => 'Only the notifications you need, when you need them.'],
            ],
        ],

        'capabilities' => [
            'title' => 'Every event, connected together',
            'subtitle' => 'Connect your app events from requests and queries to jobs and background tasks.',
            'items' => [
                ['icon' => '↗', 'title' => 'Requests', 'body' => 'Trace request lifecycles with timings and context.'],
                ['icon' => '⇢', 'title' => 'Outgoing Requests', 'body' => 'Monitor third-party calls, APIs, and integrations.'],
                ['icon' => '✉', 'title' => 'Notifications', 'body' => 'Ensure notification delivery with full visibility.'],
                ['icon' => '⚙', 'title' => 'Jobs', 'body' => 'Track queues, retries, and job performance.'],
                ['icon' => '⌁', 'title' => 'Queries', 'body' => 'Identify slow queries and optimize performance.'],
                ['icon' => '✈', 'title' => 'Mail', 'body' => 'Track sending, recipients, and throughput.'],
                ['icon' => '⌘', 'title' => 'Commands', 'body' => 'Record command runs and their resource impact.'],
                ['icon' => '⟲', 'title' => 'Cache', 'body' => 'Monitor hit rates and invalidation patterns.'],
                ['icon' => '⏱', 'title' => 'Scheduled Tasks', 'body' => 'Ensure scheduled tasks run on time and complete.'],
            ],
        ],

        'testimonials' => [
            [
                'quote' => 'We already caught a couple of things within an hour of the first deployment.',
                'name' => 'Matthias Hansen',
                'role' => 'CTO & Co-Founder',
                'company' => 'Geocodio',
            ],
            [
                'quote' => 'We found issues in production immediately and shipped fixes with confidence.',
                'name' => 'Ravi Peiris',
                'role' => 'Principal Software Engineer',
                'company' => 'BiteScheduling',
            ],
        ],

        'deep_sections' => [
            [
                'eyebrow' => 'Issue tracking',
                'title' => 'Track exceptions and performance issues',
                'body' => 'Explain how your product detects, groups, and helps resolve problems quickly with rich context.',
                'features' => [
                    ['title' => 'Collaborate with your team', 'body' => 'Assign, comment, and align ownership without friction.'],
                    ['title' => 'Configurable thresholds', 'body' => 'Tune noise down with rules that match your app.'],
                    ['title' => 'Instant alerts', 'body' => 'Receive alerts early and respond before users notice.'],
                ],
                'media' => [
                    'type' => 'image',
                    'src' => 'https://images.unsplash.com/photo-1553877522-43269d4ea984?auto=format&fit=crop&w=2400&q=60',
                    'alt' => 'Issues view',
                ],
            ],
            [
                'eyebrow' => 'Infrastructure',
                'title' => 'Built to scale for trillions of events',
                'body' => 'Explain your architecture: efficient ingestion, fast analytics, and safe retention—without app slowdowns.',
                'features' => [
                    ['title' => 'Lightweight agent', 'body' => 'Buffers and batches data invisibly inside your app.'],
                    ['title' => 'Hosted pipelines', 'body' => 'Processes, validates, and stores events in near real-time.'],
                    ['title' => 'Light-speed queries', 'body' => 'Column-oriented performance for huge volumes.'],
                ],
                'media' => [
                    'type' => 'image',
                    'src' => 'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?auto=format&fit=crop&w=2400&q=60',
                    'alt' => 'Scaling diagram',
                ],
            ],
        ],

        'faqs' => [
            ['q' => 'Can it support any PHP application?', 'a' => 'Yes. Explain supported versions and any limitations.'],
            ['q' => 'Does it work with any hosting provider and setup?', 'a' => 'Yes. Describe minimal requirements and recommended configs.'],
            ['q' => 'Does it support multi-tenancy?', 'a' => 'Yes. Explain tenant isolation and per-tenant dashboards.'],
            ['q' => 'How is data retained and secured?', 'a' => 'Explain retention defaults, encryption, and access controls.'],
            ['q' => 'Can I use it alongside another tool?', 'a' => 'Yes. Explain how they complement each other.'],
            ['q' => 'Where are data centers located?', 'a' => 'Explain regions and how customers choose them.'],
        ],

        'final_cta' => [
            'title' => "Don’t be afraid of the dark",
            'subtitle' => 'Get started for free. Set up in minutes, see value immediately.',
            'cta_primary' => ['label' => 'Get started', 'href' => '/register'],
            'cta_secondary' => ['label' => 'Contact sales', 'href' => '/contact'],
        ],

        'footer' => [
            'tagline' => 'Monitoring made simple. Full observability with zero hassle.',
            'columns' => [
                'Product' => [
                    ['label' => 'Pricing', 'href' => '#pricing'],
                    ['label' => 'Documentation', 'href' => '#docs'],
                    ['label' => 'Contact', 'href' => '/contact'],
                ],
                'Explore' => [
                    ['label' => 'Changelog', 'href' => '/changelog'],
                    ['label' => 'Blog', 'href' => '/blog'],
                    ['label' => 'Community', 'href' => '/community'],
                ],
                'Legal' => [
                    ['label' => 'Privacy', 'href' => '/privacy'],
                    ['label' => 'Terms', 'href' => '/terms'],
                    ['label' => 'Security', 'href' => '/security'],
                ],
            ],
        ],
    ];
@endphp

<!doctype html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $product['brand']['name'] }}</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@300;400;500&display=swap"
        rel="stylesheet">
</head>

<body class="bg-[#0b0f0e] text-white font-['Instrument_Sans'] selection:bg-white/10">
    {{-- Background glow similar to the reference screenshots --}}
    <div aria-hidden="true" class="pointer-events-none fixed inset-0 -z-10">
        <div class="absolute inset-0 bg-[#0b0f0e]"></div>
        <div
            class="absolute -top-40 left-1/2 h-[700px] w-[900px] -translate-x-1/2 rounded-full bg-emerald-500/10 blur-3xl">
        </div>
        <div class="absolute top-40 -left-40 h-[700px] w-[900px] rounded-full bg-blue-500/10 blur-3xl"></div>
        <div class="absolute top-52 -right-40 h-[700px] w-[900px] rounded-full bg-green-500/10 blur-3xl"></div>
        <div class="absolute inset-0 bg-gradient-to-b from-black/10 via-black/40 to-black"></div>
    </div>

    {{-- NAV --}}
    <header class="sticky top-0 z-30 border-b border-white/5 bg-black/20 backdrop-blur">
        <div class="mx-auto max-w-6xl px-6">
            <div class="flex h-16 items-center justify-between">
                <div class="flex items-center gap-3">
                    <div
                        class="grid h-8 w-8 place-items-center rounded-lg border border-white/10 bg-white/5 text-xs font-medium">
                        {{ $product['brand']['logo_text'] }}
                    </div>
                    <span class="text-sm font-medium text-white/90">{{ $product['brand']['name'] }}</span>
                </div>

                <nav class="hidden items-center gap-8 md:flex">
                    @foreach ($product['nav']['links'] as $l)
                        <a href="{{ $l['href'] }}" class="text-sm text-white/70 hover:text-white transition">
                            {{ $l['label'] }}
                        </a>
                    @endforeach
                </nav>

                <div class="flex items-center gap-3">
                    <a href="{{ $product['nav']['cta_secondary']['href'] }}"
                        class="hidden rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/80 hover:bg-white/10 md:inline-flex">
                        {{ $product['nav']['cta_secondary']['label'] }}
                    </a>
                    <a href="{{ $product['nav']['cta_primary']['href'] }}"
                        class="inline-flex rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500 transition">
                        {{ $product['nav']['cta_primary']['label'] }}
                    </a>
                </div>
            </div>
        </div>
    </header>

    {{-- HERO --}}
    <section class="pt-16 md:pt-24">
        <div class="mx-auto max-w-6xl px-6 text-center">
            <h1
                class="mx-auto max-w-3xl whitespace-pre-line text-[52px] leading-[52px] font-medium tracking-[-0.02em] text-white md:text-[96px] md:leading-[96px]">
                {{ $product['hero']['title'] }}
            </h1>

            <p class="mx-auto mt-6 max-w-2xl text-[20px] leading-[28px] font-normal text-white/55">
                {{ $product['hero']['subtitle'] }}
            </p>

            <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ $product['hero']['cta_primary']['href'] }}"
                    class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500 transition">
                    {{ $product['hero']['cta_primary']['label'] }}
                </a>
                <a href="{{ $product['hero']['cta_secondary']['href'] }}"
                    class="inline-flex items-center justify-center rounded-lg border border-white/10 bg-white/5 px-4 py-2.5 text-sm font-medium text-white/80 hover:bg-white/10 transition">
                    {{ $product['hero']['cta_secondary']['label'] }}
                </a>
            </div>

            {{-- HERO MEDIA --}}
            <div class="mt-12 rounded-2xl border border-white/10 bg-white/[0.03] p-2 shadow-2xl shadow-black/40">
                <div class="overflow-hidden rounded-xl border border-white/10 bg-black/30">
                    @if ($product['hero']['media']['type'] === 'video')
                        <video class="w-full" autoplay muted loop playsinline>
                            <source src="{{ $product['hero']['media']['src'] }}" type="video/mp4">
                        </video>
                    @else
                        <img src="{{ $product['hero']['media']['src'] }}" alt="{{ $product['hero']['media']['alt'] }}"
                            class="w-full object-cover" />
                    @endif
                </div>
            </div>
        </div>
    </section>

    {{-- INTRO (two-column with cards) --}}
    <section class="mt-20 md:mt-28">
        <div class="mx-auto max-w-6xl px-6">
            <div class="grid gap-10 md:grid-cols-2 md:items-start">
                <div>
                    <div
                        class="inline-flex items-center rounded-full border border-emerald-500/20 bg-emerald-500/10 px-3 py-1 text-xs font-medium text-emerald-200">
                        {{ $product['intro']['eyebrow'] }}
                    </div>

                    <h2
                        class="mt-5 max-w-xl text-[40px] leading-[40px] font-medium tracking-[-0.01em] text-white/95 md:text-[48px] md:leading-[48px]">
                        {{ $product['intro']['title'] }}
                    </h2>

                    <p class="mt-5 max-w-xl text-[18px] leading-[28px] font-light text-white/70">
                        {{ $product['intro']['body'] }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($product['intro']['cards'] as $c)
                        <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                            <div class="text-xs font-medium text-white/40">{{ $c['kicker'] }}</div>
                            <div class="mt-2 text-[18px] leading-[28px] font-medium text-white/95">{{ $c['title'] }}</div>
                            <div class="mt-2 text-[16px] leading-[24px] font-light text-white/55">{{ $c['body'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- CAPABILITIES GRID --}}
    <section class="mt-20 md:mt-28">
        <div class="mx-auto max-w-6xl px-6">
            <div class="text-center">
                <h2
                    class="text-[40px] leading-[40px] font-medium tracking-[-0.01em] text-white/95 md:text-[48px] md:leading-[48px]">
                    {{ $product['capabilities']['title'] }}
                </h2>
                <p class="mx-auto mt-4 max-w-2xl text-[18px] leading-[28px] font-light text-white/70">
                    {{ $product['capabilities']['subtitle'] }}
                </p>
            </div>

            <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($product['capabilities']['items'] as $it)
                    <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-5 hover:bg-white/[0.05] transition">
                        <div class="flex items-start gap-3">
                            <div
                                class="grid h-9 w-9 place-items-center rounded-lg border border-white/10 bg-white/5 text-sm text-emerald-200">
                                {{ $it['icon'] }}
                            </div>
                            <div>
                                <div class="text-[18px] leading-[28px] font-medium text-white/95">{{ $it['title'] }}</div>
                                <div class="mt-1 text-[16px] leading-[24px] font-light text-white/55">{{ $it['body'] }}
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Testimonial (center band) --}}
            <div class="mt-14">
                <div
                    class="mx-auto max-w-3xl rounded-2xl border border-white/10 bg-white/[0.03] px-6 py-10 text-center">
                    <div class="text-emerald-300 text-2xl leading-none">“</div>
                    <p class="mt-4 text-[18px] leading-[28px] font-light text-white/80">
                        {{ $product['testimonials'][0]['quote'] }}
                    </p>
                    <div class="mt-6 flex items-center justify-center gap-3">
                        <div class="h-9 w-9 rounded-full bg-white/10"></div>
                        <div class="text-left">
                            <div class="text-sm font-medium text-white/90">{{ $product['testimonials'][0]['name'] }}
                            </div>
                            <div class="text-xs text-white/50">{{ $product['testimonials'][0]['role'] }} ·
                                {{ $product['testimonials'][0]['company'] }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- DEEP SECTIONS --}}
    @foreach ($product['deep_sections'] as $sec)
        <section class="mt-20 md:mt-28">
            <div class="mx-auto max-w-6xl px-6">
                <div class="grid gap-10 lg:grid-cols-2 lg:items-start">
                    <div>
                        <div
                            class="inline-flex items-center rounded-full border border-emerald-500/20 bg-emerald-500/10 px-3 py-1 text-xs font-medium text-emerald-200">
                            {{ $sec['eyebrow'] }}
                        </div>

                        <h2
                            class="mt-5 max-w-xl text-[40px] leading-[40px] font-medium tracking-[-0.01em] text-white/95 md:text-[48px] md:leading-[48px]">
                            {{ $sec['title'] }}
                        </h2>

                        <p class="mt-5 max-w-xl text-[18px] leading-[28px] font-light text-white/70">
                            {{ $sec['body'] }}
                        </p>

                        <div class="mt-8 grid gap-4">
                            @foreach ($sec['features'] as $f)
                                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                                    <div class="text-[18px] leading-[28px] font-medium text-white/95">{{ $f['title'] }}</div>
                                    <div class="mt-2 text-[16px] leading-[24px] font-light text-white/55">{{ $f['body'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-2 shadow-2xl shadow-black/40">
                        <div class="overflow-hidden rounded-xl border border-white/10 bg-black/30">
                            @if ($sec['media']['type'] === 'video')
                                <video class="w-full" autoplay muted loop playsinline>
                                    <source src="{{ $sec['media']['src'] }}" type="video/mp4">
                                </video>
                            @else
                                <img src="{{ $sec['media']['src'] }}" alt="{{ $sec['media']['alt'] }}"
                                    class="w-full object-cover" />
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </section>
    @endforeach

    {{-- FAQS --}}
    <section class="mt-20 md:mt-28">
        <div class="mx-auto max-w-6xl px-6">
            <div class="text-center">
                <h2
                    class="text-[40px] leading-[40px] font-medium tracking-[-0.01em] text-white/95 md:text-[48px] md:leading-[48px]">
                    FAQs
                </h2>
                <p class="mx-auto mt-4 max-w-2xl text-[18px] leading-[28px] font-light text-white/70">
                    Can’t find your answer? <a
                        class="text-emerald-200 hover:text-emerald-100 underline underline-offset-4" href="#docs">Read
                        our docs</a>.
                </p>
            </div>

            <div class="mx-auto mt-10 max-w-3xl space-y-3">
                @foreach ($product['faqs'] as $i => $faq)
                    <details class="group rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4">
                            <span class="text-sm font-medium text-white/85">{{ $faq['q'] }}</span>
                            <span
                                class="grid h-8 w-8 place-items-center rounded-lg border border-white/10 bg-white/5 text-white/60 group-open:rotate-45 transition">
                                +
                            </span>
                        </summary>
                        <div class="mt-3 text-[16px] leading-[24px] font-light text-white/55">
                            {{ $faq['a'] }}
                        </div>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    {{-- SECOND TESTIMONIAL + FINAL CTA (centered card) --}}
    <section class="mt-20 md:mt-28 pb-20">
        <div class="mx-auto max-w-6xl px-6">
            <div class="mx-auto max-w-3xl rounded-2xl border border-white/10 bg-white/[0.03] px-6 py-10 text-center">
                <div class="text-emerald-300 text-2xl leading-none">“</div>
                <p class="mt-4 text-[18px] leading-[28px] font-light text-white/80">
                    {{ $product['testimonials'][1]['quote'] }}
                </p>
                <div class="mt-6 flex items-center justify-center gap-3">
                    <div class="h-9 w-9 rounded-full bg-white/10"></div>
                    <div class="text-left">
                        <div class="text-sm font-medium text-white/90">{{ $product['testimonials'][1]['name'] }}</div>
                        <div class="text-xs text-white/50">{{ $product['testimonials'][1]['role'] }} ·
                            {{ $product['testimonials'][1]['company'] }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-14 rounded-2xl border border-white/10 bg-white/[0.03] p-8 text-center md:p-12">
                <h2
                    class="mx-auto max-w-3xl text-[32px] leading-[36px] font-medium tracking-[-0.01em] text-white/95 md:text-[40px] md:leading-[44px]">
                    {{ $product['final_cta']['title'] }}
                </h2>
                <p class="mx-auto mt-4 max-w-2xl text-[18px] leading-[28px] font-light text-white/70">
                    {{ $product['final_cta']['subtitle'] }}
                </p>

                <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <a href="{{ $product['final_cta']['cta_primary']['href'] }}"
                        class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm shadow-blue-600/20 hover:bg-blue-500 transition">
                        {{ $product['final_cta']['cta_primary']['label'] }}
                    </a>
                    <a href="{{ $product['final_cta']['cta_secondary']['href'] }}"
                        class="inline-flex items-center justify-center rounded-lg border border-white/10 bg-white/5 px-4 py-2.5 text-sm font-medium text-white/80 hover:bg-white/10 transition">
                        {{ $product['final_cta']['cta_secondary']['label'] }}
                    </a>
                </div>
            </div>

            {{-- FOOTER --}}
            <footer class="mt-14 border-t border-white/5 pt-10">
                <div class="grid gap-10 md:grid-cols-5">
                    <div class="md:col-span-2">
                        <div class="flex items-center gap-3">
                            <div
                                class="grid h-10 w-10 place-items-center rounded-xl border border-white/10 bg-white/5 text-xs font-medium">
                                {{ $product['brand']['logo_text'] }}
                            </div>
                            <div>
                                <div class="text-sm font-medium text-white/90">{{ $product['brand']['name'] }}</div>
                                <div class="text-xs text-white/50">{{ $product['footer']['tagline'] }}</div>
                            </div>
                        </div>

                        <form class="mt-6 flex max-w-md gap-2" action="#" method="post">
                            {{-- If you wire this up, add @csrf and route --}}
                            <input type="email" placeholder="Email address"
                                class="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white/80 placeholder:text-white/35 focus:outline-none focus:ring-2 focus:ring-blue-500/40" />
                            <button type="button"
                                class="rounded-lg bg-white/10 px-3 py-2 text-sm font-medium text-white/80 hover:bg-white/15 transition">
                                Stay updated
                            </button>
                        </form>

                        <div class="mt-6 text-xs text-white/40">
                            © {{ date('Y') }} {{ $product['brand']['name'] }} · All rights reserved.
                        </div>
                    </div>

                    @foreach ($product['footer']['columns'] as $colTitle => $links)
                        <div>
                            <div class="text-xs font-medium text-white/60">{{ $colTitle }}</div>
                            <div class="mt-4 space-y-2">
                                @foreach ($links as $l)
                                    <a href="{{ $l['href'] }}"
                                        class="block text-sm text-white/50 hover:text-white/80 transition">
                                        {{ $l['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </footer>
        </div>
    </section>
</body>

</html>