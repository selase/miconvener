<!doctype html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>@yield('title', config('product-page.brand.name'))</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Gabarito:wght@600;700;800&family=JetBrains+Mono:wght@400;500&family=Public+Sans:wght@400;500;600&display=swap"
        rel="stylesheet">
    <style>
        :root {
            /* An auditorium before doors open: deep indigo field, with the
               warm marigold of a torn ticket stub kept for money moments. */
            --ink: #191527;
            --ink-2: #4a4459;
            --muted: #7c7589;
            --paper: #f7f6fb;
            --surface: #ffffff;
            --rule: #e4e1ec;
            --indigo: #3d2bc4;
            --indigo-deep: #2a1c94;
            --peri: #b9aeff;
            --marigold: #f2a33c;
            --go: #0f7b5a;
        }
        body { font-family: 'Public Sans', ui-sans-serif, system-ui, sans-serif; color: var(--ink); }
        .section-heading, .pricing-heading, .display {
            font-family: 'Gabarito', 'Public Sans', sans-serif;
            letter-spacing: -0.02em;
        }
        .mono { font-family: 'JetBrains Mono', ui-monospace, Menlo, monospace; }
        ::selection { background: var(--peri); color: var(--ink); }
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
                    <a href="{{ route('product.template') }}" class="flex items-center gap-3">
                        <div class="grid h-8 w-8 place-items-center rounded-lg text-xs font-bold text-white" style="background: var(--indigo)">
                            {{ config('product-page.brand.logo_text') }}
                        </div>
                        <span class="text-sm font-semibold text-slate-800">{{ config('product-page.brand.name') }}</span>
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
                        class="inline-flex rounded-lg px-3.5 py-2 text-sm font-semibold text-white transition" style="background: var(--indigo)">
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
                    <div class="flex items-center gap-3">
                        <div class="grid h-10 w-10 place-items-center rounded-xl border border-slate-200 bg-white text-xs font-semibold text-blue-600 shadow-sm">
                            {{ config('product-page.brand.logo_text') }}
                        </div>
                        <div>
                            <div class="text-sm font-semibold text-slate-800">{{ config('product-page.brand.name') }}</div>
                            <div class="text-xs text-slate-400">{{ config('product-page.footer.tagline') }}</div>
                        </div>
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
