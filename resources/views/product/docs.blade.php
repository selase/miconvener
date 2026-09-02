@extends('layouts.product')

@section('title', 'Documentation - ' . config('product-page.brand.name'))

@section('content')
    <div class="mx-auto max-w-7xl px-6">
        <div class="flex flex-col lg:flex-row gap-12 pt-10 pb-20">

            {{-- Left Sidebar --}}
            <aside class="w-full lg:w-64 flex-shrink-0 space-y-10">
                <div>
                    <a href="#" class="flex items-center gap-3 text-white/70 hover:text-white transition group">
                        <div
                            class="h-8 w-8 rounded-lg bg-indigo-500/10 flex items-center justify-center text-indigo-400 group-hover:scale-110 transition">
                            <i class="fab fa-discord"></i>
                        </div>
                        <span class="text-sm font-medium">Community</span>
                    </a>
                </div>

                <nav class="space-y-8">
                    <div>
                        <h4 class="text-xs font-semibold text-white/30 uppercase tracking-widest mb-4">Get Started</h4>
                        <ul class="space-y-2">
                            <li><a href="{{ route('product.docs', 'start-guide') }}"
                                    class="block px-3 py-2 rounded-lg {{ $section === 'start-guide' ? 'bg-blue-500/10 text-blue-400 border border-blue-500/20 font-medium' : 'text-white/50 hover:text-white/80 transition' }} text-sm">Start
                                    Guide</a></li>
                            <li><a href="{{ route('product.docs', 'requirements') }}"
                                    class="block px-3 py-2 rounded-lg {{ $section === 'requirements' ? 'bg-blue-500/10 text-blue-400 border border-blue-500/20 font-medium' : 'text-white/50 hover:text-white/80 transition' }} text-sm">Requirements</a>
                            </li>
                        </ul>
                    </div>

                    <div>
                        <h4 class="text-xs font-semibold text-white/30 uppercase tracking-widest mb-4">Features</h4>
                        <ul class="space-y-2">
                            <li><a href="{{ route('product.docs', 'overview') }}"
                                    class="block px-3 py-2 rounded-lg {{ $section === 'overview' ? 'bg-blue-500/10 text-blue-400 border border-blue-500/20 font-medium' : 'text-white/50 hover:text-white/80 transition' }} text-sm">Overview</a>
                            </li>
                            <li><a href="#"
                                    class="block px-3 py-2 rounded-lg text-white/50 hover:text-white/80 transition text-sm">Tenant
                                    Isolation</a></li>
                            <li><a href="#"
                                    class="block px-3 py-2 rounded-lg text-white/50 hover:text-white/80 transition text-sm">LLM
                                    Management</a></li>
                            <li><a href="#"
                                    class="block px-3 py-2 rounded-lg text-white/50 hover:text-white/80 transition text-sm">Branding
                                    Configuration</a></li>
                        </ul>
                    </div>

                    <div>
                        <h4 class="text-xs font-semibold text-white/30 uppercase tracking-widest mb-4">Settings</h4>
                        <ul class="space-y-2">
                            <li><a href="#"
                                    class="block px-3 py-2 rounded-lg text-white/50 hover:text-white/80 transition text-sm">Organization</a>
                            </li>
                            <li><a href="#"
                                    class="block px-3 py-2 rounded-lg text-white/50 hover:text-white/80 transition text-sm">Billing</a>
                            </li>
                            <li><a href="#"
                                    class="block px-3 py-2 rounded-lg text-white/50 hover:text-white/80 transition text-sm">API
                                    Tokens</a></li>
                        </ul>
                    </div>
                </nav>
            </aside>

            {{-- Main Content --}}
            <main class="flex-grow min-w-0">
                {{-- Search Bar / Top Actions --}}
                <div class="flex flex-col sm:flex-row gap-4 mb-12">
                    <div class="relative flex-grow">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-search text-white/30"></i>
                        </div>
                        <input type="text" placeholder="Search documentation..."
                            class="w-full bg-white/5 border border-white/10 rounded-xl py-3 pl-11 pr-4 text-sm text-white focus:outline-none focus:ring-2 focus:ring-blue-500/40 transition">
                        <div
                            class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none text-xs text-white/20">
                            ⌘K
                        </div>
                    </div>
                    <button
                        class="inline-flex items-center gap-2 bg-white/5 hover:bg-white/10 border border-white/10 rounded-xl px-4 py-3 text-sm text-white transition">
                        <i class="fas fa-sparkles text-blue-400"></i>
                        Ask AI
                    </button>
                    <div class="flex-grow sm:flex-grow-0 flex items-center justify-end gap-3 px-2">
                        <span class="text-white/40 text-sm">Support</span>
                        <a href="{{ route('dashboard') }}"
                            class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold px-4 py-2 rounded-lg transition">Dashboard
                            <i class="fas fa-arrow-right ml-1"></i></a>
                    </div>
                </div>

                <article class="prose prose-invert max-w-none">
                    @if($section === 'start-guide')
                        <div class="flex items-center gap-2 text-blue-400 text-sm font-medium mb-4">
                            <span class="hover:underline cursor-pointer font-medium text-white/40">Get Started</span>
                            <i class="fas fa-chevron-right text-[10px] text-white/20"></i>
                            <span class="text-blue-400">Start Guide</span>
                        </div>

                        <h1 class="text-4xl md:text-5xl font-medium text-white mb-4">Start Guide</h1>
                        <p class="text-lg text-white/50 mb-10">Get started with {{ config('product-page.brand.name') }} in just
                            a few minutes.</p>

                        <div
                            class="rounded-2xl border border-white/10 bg-black/40 p-1 mb-12 aspect-video flex items-center justify-center overflow-hidden">
                            <div class="text-center">
                                <i
                                    class="fas fa-play-circle text-6xl text-white/10 hover:text-blue-500/50 transition cursor-pointer"></i>
                                <p class="mt-4 text-xs text-white/20 uppercase tracking-widest font-medium">Walkthrough Video
                                </p>
                            </div>
                        </div>

                        <h2 class="text-2xl font-medium text-white mb-6 border-b border-white/5 pb-4"
                            id="registering-an-account">Registering an account</h2>
                        <p class="text-white/60 mb-8 leading-relaxed">
                            To begin, visit the registration page and create a new account. You'll be asked for your name,
                            email, and to choose a password. Once registered, you'll be guided through the initial organization
                            setup.
                        </p>

                        <h2 class="text-2xl font-medium text-white mb-6 border-b border-white/5 pb-4"
                            id="creating-an-organization">Creating an organization</h2>
                        <p class="text-white/60 mb-8 leading-relaxed">
                            After logging in, you can create your first organization. This will serve as your primary tenant.
                            You can customize the organization's name, slug (for your subdomain), and branding colors later in
                            the settings panel.
                        </p>

                        <h2 class="text-2xl font-medium text-white mb-6 border-b border-white/5 pb-4" id="next-steps">Next steps
                        </h2>
                        <p class="text-white/60 mb-8 leading-relaxed">
                            Now that your organization is set up, you can start inviting team members, configuring your LLM
                            providers, and integrating your existing applications. Check out our detailed guides for more
                            advanced features.
                        </p>
                    @elseif($section === 'requirements')
                        <div class="flex items-center gap-2 text-blue-400 text-sm font-medium mb-4">
                            <span class="hover:underline cursor-pointer font-medium text-white/40">Get Started</span>
                            <i class="fas fa-chevron-right text-[10px] text-white/20"></i>
                            <span class="text-blue-400">Requirements</span>
                        </div>

                        <h1 class="text-4xl md:text-5xl font-medium text-white mb-4">Requirements</h1>
                        <p class="text-lg text-white/50 mb-10">Minimal requirements to run
                            {{ config('product-page.brand.name') }}.</p>

                        <h2 class="text-2xl font-medium text-white mb-6 border-b border-white/5 pb-4" id="server-environment">
                            Server Environment</h2>
                        <p class="text-white/60 mb-4 leading-relaxed">
                            {{ config('product-page.brand.name') }} requires the following environment to run efficiently:
                        </p>
                        <ul class="text-white/60 space-y-3 mb-8 list-disc pl-5">
                            <li>PHP 8.2 or higher</li>
                            <li>PostgreSQL 14+ or MySQL 8.0+</li>
                            <li>Redis 6.0+ (highly recommended for performance)</li>
                            <li>Nginx or Apache with mod_rewrite enabled</li>
                        </ul>

                        <h2 class="text-2xl font-medium text-white mb-6 border-b border-white/5 pb-4" id="php-extensions">PHP
                            Extensions</h2>
                        <p class="text-white/60 mb-4 leading-relaxed">
                            Ensure the following PHP extensions are installed and enabled:
                        </p>
                        <ul class="text-white/60 space-y-3 mb-8 grid grid-cols-2">
                            <li><i class="fas fa-check text-blue-400 mr-2"></i> BCMath</li>
                            <li><i class="fas fa-check text-blue-400 mr-2"></i> Ctype</li>
                            <li><i class="fas fa-check text-blue-400 mr-2"></i> Fileinfo</li>
                            <li><i class="fas fa-check text-blue-400 mr-2"></i> JSON</li>
                            <li><i class="fas fa-check text-blue-400 mr-2"></i> Mbstring</li>
                            <li><i class="fas fa-check text-blue-400 mr-2"></i> OpenSSL</li>
                            <li><i class="fas fa-check text-blue-400 mr-2"></i> PDO</li>
                            <li><i class="fas fa-check text-blue-400 mr-2"></i> Tokenizer</li>
                            <li><i class="fas fa-check text-blue-400 mr-2"></i> XML</li>
                        </ul>
                    @elseif($section === 'overview')
                        <div class="flex items-center gap-2 text-blue-400 text-sm font-medium mb-4">
                            <span class="hover:underline cursor-pointer font-medium text-white/40">Features</span>
                            <i class="fas fa-chevron-right text-[10px] text-white/20"></i>
                            <span class="text-blue-400">Overview</span>
                        </div>

                        <h1 class="text-4xl md:text-5xl font-medium text-white mb-4">Overview</h1>
                        <p class="text-lg text-white/50 mb-10">A high-level view of how {{ config('product-page.brand.name') }}
                            works.</p>

                        <div class="grid gap-6 md:grid-cols-2 mb-12">
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-6 hover:bg-white/[0.08] transition">
                                <div
                                    class="h-10 w-10 rounded-lg bg-blue-500/10 flex items-center justify-center text-blue-400 mb-4">
                                    <i class="fas fa-users"></i>
                                </div>
                                <h3 class="text-lg font-medium text-white mb-2">Multi-tenancy</h3>
                                <p class="text-sm text-white/50">Built-in support for multiple organizations with complete data
                                    isolation and subdomain-based routing.</p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-6 hover:bg-white/[0.08] transition">
                                <div
                                    class="h-10 w-10 rounded-lg bg-purple-500/10 flex items-center justify-center text-purple-400 mb-4">
                                    <i class="fas fa-robot"></i>
                                </div>
                                <h3 class="text-lg font-medium text-white mb-2">LLM Management</h3>
                                <p class="text-sm text-white/50">Seamless integration with major AI providers like OpenAI,
                                    Anthropic, and Google for per-tenant credit management.</p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-6 hover:bg-white/[0.08] transition">
                                <div
                                    class="h-10 w-10 rounded-lg bg-emerald-500/10 flex items-center justify-center text-emerald-400 mb-4">
                                    <i class="fas fa-credit-card"></i>
                                </div>
                                <h3 class="text-lg font-medium text-white mb-2">Integrated Billing</h3>
                                <p class="text-sm text-white/50">Complete Stripe integration for handling subscriptions, metered
                                    usage, and automated invoicing.</p>
                            </div>
                            <div class="rounded-2xl border border-white/10 bg-white/5 p-6 hover:bg-white/[0.08] transition">
                                <div
                                    class="h-10 w-10 rounded-lg bg-amber-500/10 flex items-center justify-center text-amber-400 mb-4">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <h3 class="text-lg font-medium text-white mb-2">Enterprise Security</h3>
                                <p class="text-sm text-white/50">Advanced RBAC, audit logs, and security-first architecture
                                    designed for scaling securely.</p>
                            </div>
                        </div>

                        <h2 class="text-2xl font-medium text-white mb-6 border-b border-white/5 pb-4" id="architecture">
                            Architecture</h2>
                        <p class="text-white/60 mb-8 leading-relaxed">
                            {{ config('product-page.brand.name') }} is built on a modular Laravel architecture. It uses dynamic
                            database resolution for tenants, allowing you to choose between shared or isolated databases on the
                            fly.
                        </p>
                    @endif
                </article>
            </main>

            {{-- Right Sidebar (TOC) --}}
            <aside class="hidden xl:block w-64 flex-shrink-0">
                <div class="sticky top-24">
                    <h4 class="text-xs font-semibold text-white/30 uppercase tracking-widest mb-6">On this page</h4>
                    <ul class="space-y-4 border-l border-white/5 pl-4">
                        @if($section === 'start-guide')
                            <li><a href="#registering-an-account"
                                    class="block text-sm text-blue-400 hover:text-blue-300 transition">Registering an
                                    account</a></li>
                            <li><a href="#creating-an-organization"
                                    class="block text-sm text-white/50 hover:text-white/80 transition">Creating an
                                    organization</a></li>
                            <li><a href="#next-steps" class="block text-sm text-white/50 hover:text-white/80 transition">Next
                                    steps</a></li>
                        @elseif($section === 'requirements')
                            <li><a href="#server-environment"
                                    class="block text-sm text-blue-400 hover:text-blue-300 transition">Server Environment</a>
                            </li>
                            <li><a href="#php-extensions" class="block text-sm text-white/50 hover:text-white/80 transition">PHP
                                    Extensions</a></li>
                        @elseif($section === 'overview')
                            <li><a href="#architecture"
                                    class="block text-sm text-blue-400 hover:text-blue-300 transition">Architecture</a></li>
                        @endif
                    </ul>
                </div>
            </aside>

        </div>
    </div>
@endsection

@section('footer')
    {{-- No footer on docs or different footer --}}
    <footer class="border-t border-white/5 py-10">
        <div class="mx-auto max-w-7xl px-6 text-center text-xs text-white/20">
            © {{ date('Y') }} {{ config('product-page.brand.name') }} Documentation.
        </div>
    </footer>
@endsection