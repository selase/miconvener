<!doctype html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>@yield('title', config('product-page.brand.name'))</title>

    <link rel="icon" type="image/png" sizes="32x32" href="/assets/img/brand/mark-32.png" />
    <link rel="icon" type="image/png" sizes="512x512" href="/assets/img/brand/mark-512.png" />
    <link rel="apple-touch-icon" href="/assets/img/brand/mark-180.png" />

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500&family=Public+Sans:wght@300;400;500;600&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --ink: #12161c;
            --ink-2: #45505e;
            --muted: #78838f;
            --paper: #f6f8fa;
            --surface: #ffffff;
            --rule: #e3e8ee;
            --field: #155dfc;
            --field-deep: #0b3ea8;
            --ice: oklch(0.95 0.055 207.08);
            --accent: #155dfc;
            --accent-soft: #eef3ff;
            --go: #12795c;
            --amber: #b8791f;
            --danger: #b3261e;
        }
        body {
            font-family: 'Public Sans', ui-sans-serif, system-ui, -apple-system, sans-serif;
            color: var(--ink);
            font-weight: 400;
            -webkit-font-smoothing: antialiased;
        }
        /* One family throughout. Weight and size carry the hierarchy rather than
           a second, heavier display face. */
        .section-heading, .pricing-heading, .display {
            font-family: 'Public Sans', ui-sans-serif, system-ui, sans-serif;
            font-weight: 500;
            letter-spacing: -0.018em;
        }
        .mono { font-family: 'JetBrains Mono', ui-monospace, Menlo, monospace; }
        ::selection { background: var(--mint); color: var(--ink); }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
    </style>

    @livewireStyles
    @stack('styles')
</head>

<body class="bg-white text-slate-900">
    {{-- NAV --}}
    <header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/90 backdrop-blur">
        <div class="mx-auto max-w-7xl px-6">
            <div class="flex h-16 items-center justify-between">
                <div class="flex items-center gap-3">
                    <a href="{{ route('product.template') }}" class="flex items-center">
                        <img src="{{ config('product-page.brand.wordmark') }}"
                             srcset="{{ config('product-page.brand.wordmark') }} 1x, {{ config('product-page.brand.wordmark_2x') }} 2x"
                             alt="{{ config('product-page.brand.name') }}"
                             class="h-7 w-auto" width="1228" height="229" />
                    </a>
                </div>

                <nav class="hidden items-center gap-8 md:flex">
                    @foreach (config('product-page.nav.links') as $l)
                        <a href="{{ $l['href'] }}"
                            class="text-sm {{ request()->url() == $l['href'] ? 'text-slate-900 font-semibold' : 'text-slate-500 hover:text-slate-900' }} transition">
                            {{ $l['label'] }}
                        </a>
                    @endforeach
                </nav>

                <div class="flex items-center gap-3">
                    <a href="{{ config('product-page.nav.cta_secondary.href') }}"
                        class="hidden rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-50 hover:border-slate-300 md:inline-flex transition">
                        {{ config('product-page.nav.cta_secondary.label') }}
                    </a>
                    <a href="{{ config('product-page.nav.cta_primary.href') }}"
                        class="inline-flex rounded-lg px-3.5 py-2 text-sm font-semibold text-white transition" style="background: var(--field)">
                        {{ config('product-page.nav.cta_primary.label') }}
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main>
        @yield('content')
    </main>

    @section('footer')
    <footer class="border-t border-slate-200 bg-slate-50 pt-12 pb-20 mt-20">
        <div class="mx-auto max-w-7xl px-6">
            <div class="grid gap-10 md:grid-cols-5">
                <div class="md:col-span-3">
                    <div>
                        <img src="{{ config('product-page.brand.wordmark') }}"
                             srcset="{{ config('product-page.brand.wordmark') }} 1x, {{ config('product-page.brand.wordmark_2x') }} 2x"
                             alt="{{ config('product-page.brand.name') }}"
                             class="h-8 w-auto" width="1228" height="229" />
                        <div class="mt-2 text-xs text-slate-400">{{ config('product-page.footer.tagline') }}</div>
                    </div>
                    <div class="mt-6 text-xs text-slate-400">
                        © {{ date('Y') }} {{ config('product-page.brand.name') }} · All rights reserved.
                    </div>
                </div>

                @foreach (config('product-page.footer.columns') as $colTitle => $links)
                    <div>
                        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide">{{ $colTitle }}</div>
                        <div class="mt-4 space-y-2">
                            @foreach ($links as $l)
                                <a href="{{ $l['href'] }}" class="block text-sm text-slate-500 hover:text-slate-900 transition">
                                    {{ $l['label'] }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </footer>
    @show

    @livewireScripts
    @stack('scripts')
</body>

</html>
