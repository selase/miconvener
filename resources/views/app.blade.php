<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600&family=Geist+Mono:wght@400;500&display=swap">

        <script>
            (function () {
                try {
                    var stored = localStorage.getItem('miconvener-theme');
                    var dark = stored ? stored === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
                    document.documentElement.classList.toggle('dark', dark);
                } catch (e) {}
            })();
        </script>

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
        @routes
        @inertiaHead
    </head>
    <body class="font-console antialiased bg-surface text-ink console-root">
        @inertia
    </body>
</html>
