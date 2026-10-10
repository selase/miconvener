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
            ['label' => 'Pricing', 'href' => '/#pricing'],
            ['label' => 'Enterprise', 'href' => '/product-enterprise'],
        ],
        'cta_secondary' => ['label' => 'Sign in', 'href' => '/login'],
        'cta_primary' => ['label' => 'Start for free', 'href' => '/register'],
    ],

    'hero' => [
        'title' => "Run the whole event\nfrom one place",
        'subtitle' => 'Bring programmes, people, payments and places together. Plan the event, equip your crew, engage your guests and keep the venue ready—from the first registration to the final settlement.',
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
                'SMS available as credit packs',
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
                '150 SMS credits per month',
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
                '500 SMS credits per month',
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
                'Unlimited email and SMS credits',
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
        'eyebrow' => 'Who it serves',
        'title' => 'Built for everyone behind the gathering',
        'body' => 'Bring the people organising, hosting, running and attending your event into connected workspaces.',
        'cards' => [
            ['kicker' => 'Organisers', 'title' => 'Event organisers', 'body' => 'Coordinate programmes, speakers, registrations, team tasks and sponsor commitments.'],
            ['kicker' => 'Crew', 'title' => 'Door & floor teams', 'body' => 'Check guests in, track session arrivals and respond to requests from their own phones.'],
            ['kicker' => 'Venues', 'title' => 'Venue operators', 'body' => 'Manage spaces, inquiries, quotations and facility preparations, with a shared view of the event.'],
            ['kicker' => 'Attendees', 'title' => 'Attendees & participants', 'body' => 'Find tickets, available materials, receipts and certificates, and take part in the event from one personal portal.'],
        ],
    ],

    'capabilities' => [
        'title' => 'Everything an event needs',
        'subtitle' => 'One place for the whole operation, from the first ticket sold to the final settlement statement.',
        'items' => [
            ['icon' => '🎫', 'title' => 'Event pages', 'body' => 'Description, schedule, speakers and ticket tiers on one shareable link.'],
            ['icon' => '💳', 'title' => 'Tickets & payments', 'body' => 'Free, paid or tiered tickets. Card and mobile money through Paystack, plus offline payments you approve.'],
            ['icon' => '📲', 'title' => 'Door scanning that works offline', 'body' => 'Ushers scan from their own phones with a staff link: no app, no account. The guest list is saved on the phone, so scanning carries on without signal and syncs when it returns. One link covers every day of a multi-day event.'],
            ['icon' => '👥', 'title' => 'Door & floor crew', 'body' => 'An Event Staff role for permanent staff, and printed badges.'],
            ['icon' => '💬', 'title' => 'SMS to your guests', 'body' => 'Ticket confirmations, reminders and announcements by text. Plans include SMS credits, and packs top them up.'],
            ['icon' => '🪪', 'title' => 'Attendee portal', 'body' => 'Guests sign in at miconvener.com/my to find their tickets, certificates and receipts across every organizer, with tickets saved for offline use.'],
            ['icon' => '🙋', 'title' => 'Attendee requests', 'body' => 'Guests ask for help from their ticket, and your crew answers from their phones.'],
            ['icon' => '🗓', 'title' => 'Programme', 'body' => 'Several rooms, session capacity, live room headcounts and calendar feeds.'],
            ['icon' => '🎤', 'title' => 'Speaker portal', 'body' => 'Speakers confirm, edit their bios and upload their slides themselves.'],
            ['icon' => '💬', 'title' => 'Live polling & Q&A', 'body' => 'A live presenter screen, ten question types including quizzes, word clouds, rankings and ratings, and moderated Q&A. Included on Growth, or as an add-on.'],
            ['icon' => '🕊️', 'title' => 'Giving, tributes and recurring gatherings', 'body' => 'Collect donations, offerings and tributes, and run weekly services or lecture series. Wording adapts to churches, memorials, academic events and fundraisers. Contributions carry a platform fee, disclosed in the Terms.'],
            ['icon' => '📊', 'title' => 'Settlement', 'body' => 'Charges, refunds and fees on an exportable statement, with payouts to bank or mobile money.'],
            ['icon' => '⚡', 'title' => 'Webhooks', 'body' => 'Signed notifications to your own systems for registrations, check-ins, payments and contributions.'],
            ['icon' => '🛡️', 'title' => 'Safeguards', 'body' => 'A deleted event can be restored for 6 hours, and nothing is deleted while a payout is in progress.'],
        ],
    ],

    /*
     * Each card quotes the EventLexicon wording for its event category, so a
     * change there is a change to be made here too (MarketingClaimsTest pins it).
     */
    'communities' => [
        'eyebrow' => 'Beyond conferences',
        'title' => 'The same tools, in the words your community uses',
        'body' => 'Choose the type of event and the page speaks accordingly. Giving, a message wall and recurring gatherings are built in.',
        'cards' => [
            ['title' => 'Churches', 'body' => 'Tithes & Offerings, a Blessings & Prayer Wall, and services that repeat every week.'],
            ['title' => 'Funerals and memorials', 'body' => 'Funeral Donations & Support, and a Tribute & Condolence Wall for those who cannot attend.'],
            ['title' => 'Academic events', 'body' => 'Lectures and seminars on a schedule, a Department & Class Fund, and a Student & Alumni Message Board.'],
            ['title' => 'Fundraisers', 'body' => 'Donations & Pledges, and a Donor Wall & Solidarity Messages.'],
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
        ['q' => 'Does door scanning work without signal?', 'a' => 'Yes. When an usher opens their staff link, the guest list is saved on their phone. Scans carry on offline, refuse a ticket already used on that phone, and sync when the connection returns. Two phones offline at once can both admit the same ticket; the second is flagged as a duplicate when they sync.'],
        ['q' => 'Can I text my guests?', 'a' => 'Yes. Ticket confirmations, reminders and announcements can go out by SMS as well as email. Starter includes 150 SMS credits a month and Growth 500; Free has none, and prepaid packs top up any plan. Each text uses one credit, and a message is sent only to guests who gave a phone number.'],
        ['q' => 'Can I use it for a church service, funeral or fundraiser?', 'a' => 'Yes. Choose the type when you create the event and the wording follows: tithes and offerings, a tribute wall, or donations. Weekly services and lecture series repeat on a schedule. Contributions carry a platform fee, shown in the contribution settings and in the Terms.'],
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
