<?php

declare(strict_types=1);

return [
    'brand' => [
        'name' => 'MiConvener',
        'logo_text' => 'MC',
    ],

    'nav' => [
        'links' => [
            ['label' => 'Features', 'href' => '/#features'],
            ['label' => 'Pricing', 'href' => '/#pricing'],
            ['label' => 'Enterprise', 'href' => '/product-enterprise'],
        ],
        'cta_secondary' => ['label' => 'Sign in', 'href' => '/login'],
        'cta_primary' => ['label' => 'Start for free', 'href' => '/register'],
    ],

    'hero' => [
        'title' => "Run the whole event\nfrom one place",
        'subtitle' => 'Build your event page, sell tickets, check guests in at the door, and see exactly what you earned — without stitching five tools together.',
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
                '1 live event at a time',
                '100 registrations per month',
                '1 team seat',
                '200 email credits per month',
                '5% commission on ticket sales',
            ],
            'cta' => [
                'label' => 'Get Started Free',
                'href' => '/register?plan=free',
            ],
        ],
        [
            'name' => 'Starter',
            'slug' => 'starter',
            'description' => 'For teams running a few events a month.',
            'monthly_price' => 29,
            'yearly_price' => 264,
            'most_popular' => true,
            'features' => [
                '3 live events at a time',
                '500 registrations per month',
                '3 team seats',
                '1,000 email credits per month',
                '100 SMS credits per month',
                'CSV settlement export',
                '3.5% commission on ticket sales',
            ],
            'cta' => [
                'label' => 'Choose Starter',
                'href' => '/register?plan=starter',
            ],
        ],
        [
            'name' => 'Growth',
            'slug' => 'growth',
            'description' => 'For organizations running events at scale.',
            'monthly_price' => 99,
            'yearly_price' => 900,
            'most_popular' => false,
            'features' => [
                'Everything in Starter',
                '15 live events at a time',
                '3,000 registrations per month',
                '10 team seats',
                '10,000 email credits per month',
                'Your own domain',
                'No platform branding',
                '2% commission on ticket sales',
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
                'Unlimited events, registrations and seats',
                'Unlimited email and SMS credits',
                'White label portals',
                'Single sign-on',
                'API access',
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
        'subtitle' => 'One place for the whole operation, from the first invite to the money landing in your account.',
        'items' => [
            ['icon' => '🎫', 'title' => 'Event pages', 'body' => 'Description, schedule, speakers and tickets on one shareable link.'],
            ['icon' => '💳', 'title' => 'Ticketing', 'body' => 'Free, paid or tiered tickets with capacity limits, approvals and waitlists.'],
            ['icon' => '📲', 'title' => 'Check-in', 'body' => 'Scan a QR code or search by name at the door, and print badges as guests arrive.'],
            ['icon' => '🗓', 'title' => 'Programme', 'body' => 'Multi-session agendas, speaker portals, venue rooms and seat assignments.'],
            ['icon' => '💬', 'title' => 'Engagement', 'body' => 'Live polls, quizzes with a leaderboard, a moderated forum and shared materials.'],
            ['icon' => '📊', 'title' => 'Settlement', 'body' => 'Every payment, fee and refund on one statement, with payouts to your account.'],
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
        ['q' => 'How does the free plan work?', 'a' => 'The free plan covers one live event at a time, up to 100 registrations a month, and a single team seat. No card required. Upgrade whenever you need more.'],
        ['q' => 'Can I switch plans at any time?', 'a' => 'Yes. Upgrades take effect immediately with prorated billing. Downgrades take effect at the end of your current billing period.'],
        ['q' => 'How can my guests pay?', 'a' => 'Cards and mobile money, processed through Paystack.'],
        ['q' => 'How and when do I get paid?', 'a' => 'Ticket revenue is tracked on a settlement statement for each event, showing what was collected, what the payment provider took, and what settles to you. Request a payout and track it through to completion.'],
        ['q' => 'Is there a fee on ticket sales?', 'a' => 'Yes. Paid tickets carry a commission, and the rate falls as you move up plans. Each plan shows its rate in the pricing table above.'],
        ['q' => 'Do you offer annual billing?', 'a' => 'Yes. Annual billing saves 17% on every paid plan.'],
    ],

    'final_cta' => [
        'title' => 'Ready to run your next event?',
        'subtitle' => 'Start free with one event and up to 100 registrations. No card required.',
        'cta_primary' => ['label' => 'Start Free', 'href' => '/register'],
        'cta_secondary' => ['label' => 'Contact Sales', 'href' => '/contact'],
    ],

    'footer' => [
        'tagline' => 'Create, run and settle events — all in one place.',
        'columns' => [
            'Product' => [
                ['label' => 'Features', 'href' => '/product-template#features'],
                ['label' => 'Pricing', 'href' => '/product-template#pricing'],
                ['label' => 'Enterprise', 'href' => '/product-enterprise'],
            ],
            'Company' => [
                ['label' => 'About Us', 'href' => '#'],
                ['label' => 'Terms', 'href' => '/terms'],
                ['label' => 'Privacy', 'href' => '/privacy'],
            ],
        ],
    ],
];
