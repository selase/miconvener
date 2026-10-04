<?php

declare(strict_types=1);

return [
    'brand' => [
        'name' => 'MiConvener',
        // The real wordmark and its square "Mi" mark. logo_text remains only as
        // the alt text and the last resort if an image fails to load.
        'logo_text' => 'MiConvener',
        'wordmark' => '/assets/img/brand/miconvener.png',
        'wordmark_2x' => '/assets/img/brand/miconvener@2x.png',
        'mark' => '/assets/img/brand/mark-512.png',
    ],

    'nav' => [
        'links' => [
            ['label' => 'Features', 'href' => '/#features'],
            ['label' => 'Marketplace', 'href' => '/marketplace'],
            ['label' => 'Pricing', 'href' => '/#pricing'],
            ['label' => 'Enterprise', 'href' => '/product-enterprise'],
        ],
        'cta_secondary' => ['label' => 'Sign in', 'href' => '/login'],
        'cta_primary' => ['label' => 'Start for free', 'href' => '/register'],
    ],

    'hero' => [
        'title' => "Run the whole event\nfrom one place",
        'subtitle' => 'Sell the tickets, run the door with your own crew’s phones, answer guests while the event is on, and see exactly what you earned. One tool instead of five.',
        'cta_primary' => ['label' => 'Start Free', 'href' => '/register'],
        'cta_secondary' => ['label' => 'See pricing', 'href' => '/#pricing'],
    ],

    /*
     * These plans must stay in step with the packages seeded by
     * Database\Seeders\EventPackageSeeder. The registration form validates the
     * chosen slug with `exists:packages,slug`, so a slug listed here that is not
     * seeded rejects every signup that picks it.
     */
    'plans' => [
        [
            'name' => 'Free',
            'slug' => 'free',
            'description' => 'Get started with a single event.',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'most_popular' => false,
            'features' => [
                'Free events only — no ticket sales',
                '1 live event at a time',
                '50 registrations per month',
                '1 team seat',
                '1 staff link for door and floor crew',
                '200 email credits per month',
            ],
            'cta' => [
                'label' => 'Get Started Free',
                'href' => '/register?plan=free',
            ],
        ],
        [
            'name' => 'Starter',
            'slug' => 'starter',
            'description' => 'For teams running regular professional events and workshops.',
            'monthly_price' => 249,
            'yearly_price' => 2490,
            'most_popular' => true,
            'features' => [
                'Sell paid tickets',
                '3 live events at a time',
                '500 registrations per month',
                '3 team seats',
                '2 staff links for door and floor crew',
                '3,000 email credits per month',
                'Speaker material downloads',
                'CSV settlement export',
                '3.5% commission on ticket sales (GHS 25 cap)',
                'Modular add-ons available (Live Polling, extra seats)',
            ],
            'cta' => [
                'label' => 'Choose Starter',
                'href' => '/register?plan=starter',
            ],
        ],
        [
            'name' => 'Growth',
            'slug' => 'growth',
            'description' => 'For organizations and agencies running conferences and summits at scale.',
            'monthly_price' => 499,
            'yearly_price' => 4990,
            'most_popular' => false,
            'features' => [
                'Everything in Starter',
                '15 live events at a time',
                '3,000 registrations per month',
                '6 team seats',
                '5 staff links for door and floor crew',
                '15,000 email credits per month',
                'Live Polling & Audience Q&A included',
                'Your own domain',
                'No platform branding & white label',
                '2.0% commission on ticket sales (GHS 20 cap)',
            ],
            'cta' => [
                'label' => 'Choose Growth',
                'href' => '/register?plan=growth',
            ],
        ],
        [
            'name' => 'Enterprise',
            'slug' => 'enterprise',
            'description' => 'Custom limits and negotiated commission for large organizations.',
            'monthly_price' => null,
            'yearly_price' => null,
            'price_label' => 'Custom',
            'price_note' => 'Negotiated commission and limits',
            'most_popular' => false,
            'features' => [
                'Everything in Growth',
                'Unlimited events, registrations, seats and staff links',
                'Unlimited email credits',
                'White label portals',
                'Negotiated commission',
            ],
            'cta' => [
                'label' => 'Talk to us',
                'href' => '/product-enterprise',
            ],
        ],
    ],

    'intro' => [
        'eyebrow' => 'How it works',
        'title' => 'From first invite to final payout',
        'body' => 'Set up an event in minutes, share a single link, and run the day from the same place you built it.',
        'cards' => [
            ['kicker' => 'Create', 'title' => 'Build your event page', 'body' => 'Add the schedule, speakers and ticket types, then publish to a link you can share anywhere.'],
            ['kicker' => 'Sell', 'title' => 'Take payments', 'body' => 'Cards and mobile money. Guests get a ticket with a QR code the moment payment clears.'],
            ['kicker' => 'Run', 'title' => 'Run the day', 'body' => 'Scan guests in, print badges, run live polls, and answer questions in the event forum.'],
        ],
    ],

    'capabilities' => [
        'title' => 'Everything an event needs',
        'subtitle' => 'One place for the whole operation, from venue booking and vendor sourcing to on-site check-in and financial settlement.',
        'items' => [
            ['icon' => '🎫', 'title' => 'Event pages', 'body' => 'Description, schedule, speakers and ticket tiers on one shareable link.'],
            ['icon' => '💳', 'title' => 'Tickets & payments', 'body' => 'Free, paid or tiered tickets. Card and mobile money through Paystack, plus offline payments you approve.'],
            ['icon' => '📲', 'title' => 'Door & floor crew', 'body' => 'Staff links for ushers, an Event Staff role for permanent staff, and printed badges.'],
            ['icon' => '🙋', 'title' => 'Attendee requests', 'body' => 'Guests ask for help from their ticket, and your crew answers from their phones.'],
            ['icon' => '🗓', 'title' => 'Programme', 'body' => 'Several rooms, session capacity, live room headcounts and calendar feeds.'],
            ['icon' => '🎤', 'title' => 'Speaker portal', 'body' => 'Speakers confirm, edit their bios and upload their slides themselves.'],
            ['icon' => '💬', 'title' => 'Live polling & Q&A', 'body' => 'Presenter decks, eight question types including quizzes, word clouds and ratings, and moderated Q&A. Included on Growth, or as an add-on.'],
            ['icon' => '🏛️', 'title' => 'Venue marketplace', 'body' => 'Find venues, compare layouts, request quotes and pay a deposit to hold the date.'],
            ['icon' => '🛍️', 'title' => 'Vendor quotes', 'body' => 'Ask caterers, sound and decor suppliers for itemized quotes, and accept with a deposit.'],
            ['icon' => '📊', 'title' => 'Settlement', 'body' => 'Charges, refunds and fees on an exportable statement, with payouts to bank or mobile money.'],
            ['icon' => '⚡', 'title' => 'Webhooks', 'body' => 'Signed notifications to your own systems for registrations, check-ins, payments and contributions.'],
            ['icon' => '🛡️', 'title' => 'Safeguards', 'body' => 'A deleted event can be restored for 6 hours, and nothing is deleted while a payout is in progress.'],
        ],
    ],

    // No testimonials yet — the product has not launched and has no customers.
    // Do not add fabricated quotes here; only real, attributable customer
    // testimonials belong in this array.
    'testimonials' => [],

    'deep_sections' => [
        [
            'eyebrow' => 'Payments and settlement',
            'title' => 'See exactly what you earned',
            'body' => 'Every ticket sale is recorded with what the guest paid, what the payment provider took, and what settles to you. No guessing, and no waiting for a statement that never comes.',
            'features' => [
                ['kicker' => 'Ledger', 'title' => 'One statement per event', 'body' => 'Charges, refunds and payouts in one place, exportable whenever you need it.'],
                ['kicker' => 'Payouts', 'title' => 'Paid to your account', 'body' => 'Request a payout to your bank or mobile money account and track it through to settlement.'],
                ['kicker' => 'Payments', 'title' => 'However guests pay', 'body' => 'Cards and mobile money, so nobody is turned away at checkout.'],
            ],
        ],
    ],

    'faqs' => [
        ['q' => 'How does the free plan work?', 'a' => 'The free plan covers one live event at a time, up to 50 registrations a month, and a single team seat. No card required. Upgrade whenever you need more.'],
        ['q' => 'Can I switch plans at any time?', 'a' => 'Yes. An upgrade starts straight away at the new plan’s price. A downgrade takes effect at the end of the period you have paid for.'],
        ['q' => 'How can my guests pay?', 'a' => 'Cards and mobile money, processed through Paystack.'],
        ['q' => 'Can guests pay by bank transfer or MoMo directly?', 'a' => 'Yes. They upload the slip, you approve it, and their ticket is issued.'],
        ['q' => 'Do my ushers need accounts?', 'a' => 'No. Each usher gets a staff link that works on their own phone. Your plan includes some (1 on Free, 2 on Starter, 5 on Growth), and an usher pack adds five more.'],
        ['q' => 'How and when do I get paid?', 'a' => 'Ticket revenue is tracked on a settlement statement for each event, showing what was collected, what the payment provider took, and what settles to you. Request a payout and track it through to completion.'],
        ['q' => 'Is there a fee on ticket sales?', 'a' => 'Yes. Paid tickets carry a commission, and the rate falls as you move up plans. Each plan shows its rate in the pricing table above.'],
        ['q' => 'Do you offer annual billing?', 'a' => 'Yes. Annual billing saves 17% on every paid plan.'],
    ],

    'final_cta' => [
        'title' => 'Ready to run your next event?',
        'subtitle' => 'One live event at a time and 50 registrations a month. No card required.',
        'cta_primary' => ['label' => 'Start Free', 'href' => '/register'],
        // The contact form lives on the enterprise page; there is no /contact.
        'cta_secondary' => ['label' => 'Talk to us', 'href' => '/product-enterprise'],
    ],

    'footer' => [
        'tagline' => 'Create, run and settle events — all in one place.',
        'columns' => [
            'Product' => [
                ['label' => 'Features', 'href' => '/#features'],
                ['label' => 'Venue Marketplace', 'href' => '/marketplace'],
                ['label' => 'Pricing', 'href' => '/#pricing'],
                ['label' => 'Enterprise', 'href' => '/product-enterprise'],
            ],
            'Company' => [
                ['label' => 'Terms', 'href' => '/terms'],
                ['label' => 'Privacy', 'href' => '/privacy'],
            ],
        ],
    ],
];
