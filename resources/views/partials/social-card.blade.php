{{-- Link preview tags. Chat apps and social sites read these from the raw HTML and do not run JavaScript, so they are rendered on the server. A page sets $seo to override any of the defaults. --}}
@php
    $card = array_merge([
        'title' => config('app.name'),
        'description' => config('product-page.hero.subtitle'),
        'image' => url('/assets/img/brand/og-default.png'),
        'image_alt' => config('app.name'),
        'url' => url()->current(),
        'site_name' => config('app.name'),
        'type' => 'website',
    ], $seo ?? []);
@endphp
<meta name="description" content="{{ $card['description'] }}">
<meta property="og:type" content="{{ $card['type'] }}">
<meta property="og:site_name" content="{{ $card['site_name'] }}">
<meta property="og:title" content="{{ $card['title'] }}">
<meta property="og:description" content="{{ $card['description'] }}">
<meta property="og:url" content="{{ $card['url'] }}">
<meta property="og:image" content="{{ $card['image'] }}">
<meta property="og:image:alt" content="{{ $card['image_alt'] }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $card['title'] }}">
<meta name="twitter:description" content="{{ $card['description'] }}">
<meta name="twitter:image" content="{{ $card['image'] }}">
