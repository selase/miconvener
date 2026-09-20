<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/img/brand/mark-32.png') }}" />
    {{--
        Deliberately self-contained: no Vite, no CDN, no database. A 500 or 503
        page must render even when the build manifest, the network or the app
        itself is what failed. The colours mirror the console tokens in
        resources/css/app.css.
    --}}
    <style>
        :root {
            --surface: #ffffff;
            --text: #111618;
            --text-secondary: #5a656a;
            --border: #dfe3e5;
            --accent: #00897c;
            --accent-ink: #ffffff;
            --hover: #f1f3f4;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --surface: #0c0e10;
                --text: #e8ecee;
                --text-secondary: #8a959b;
                --border: #1c2226;
                --accent: #00d8c4;
                --accent-ink: #05201d;
                --hover: #161b1e;
            }
            .logo-light { display: none; }
            .logo-dark { display: block !important; }
        }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: var(--surface);
            color: var(--text);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        header { padding: 24px; }
        header img { height: 24px; width: auto; }
        main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px 64px;
        }
        .panel { width: 100%; max-width: 440px; }
        .code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 13px;
            font-weight: 500;
            color: var(--accent);
            letter-spacing: 0.04em;
        }
        h1 { margin: 8px 0 0; font-size: 28px; font-weight: 600; letter-spacing: -0.02em; line-height: 1.2; }
        p { margin: 12px 0 0; font-size: 15px; line-height: 1.6; color: var(--text-secondary); }
        .actions { margin-top: 32px; display: flex; flex-wrap: wrap; gap: 8px; }
        .button {
            display: inline-flex;
            align-items: center;
            height: 40px;
            padding: 0 16px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--surface);
            color: var(--text);
            font: inherit;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: background-color 120ms ease-out;
        }
        .button:hover { background: var(--hover); }
        .button-primary { border-color: var(--accent); background: var(--accent); color: var(--accent-ink); }
        .button-primary:hover { background: var(--accent); opacity: 0.9; }
        footer { padding: 24px; text-align: center; font-size: 12px; color: var(--text-secondary); }
    </style>
</head>
<body>
    <header>
        <a href="/" aria-label="{{ config('app.name') }} home">
            <img class="logo-light" src="{{ asset('assets/img/brand/miconvener.png') }}" alt="{{ config('app.name') }}" />
            <img class="logo-dark" style="display: none" src="{{ asset('assets/img/brand/miconvener-light.png') }}" alt="" />
        </a>
    </header>

    <main>
        <div class="panel">
            <div class="code">Error @yield('code')</div>
            <h1>@yield('title')</h1>
            <p>@yield('message')</p>
            <div class="actions">
                <a href="/" class="button button-primary">Go to the home page</a>
                <button type="button" class="button" onclick="history.back()">Go back</button>
            </div>
        </div>
    </main>

    <footer>&copy; {{ date('Y') }} {{ config('app.name') }}</footer>
</body>
</html>
