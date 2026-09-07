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
        'title' => "AI-powered insights\n& task automation",
        'subtitle' => 'Capture your workflow from any device. Get AI-generated insights, extracted tasks, and full accountability — automatically.',
        'cta_primary' => ['label' => 'Start Free', 'href' => '/register'],
        'cta_secondary' => ['label' => 'View Demo', 'href' => '#'],
        'media' => [
            'type' => 'image',
            'src' => '/assets/img/marketing/hero.png',
            'alt' => 'Workflow dashboard screenshot',
        ],
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
        'title' => 'From workflow to insights in minutes',
        'body' => 'Stop wasting hours on manual documentation. MiConvener captures, analyzes, and generates actionable insights automatically.',
        'cards' => [
            ['kicker' => 'Capture', 'title' => 'Capture every detail', 'body' => 'Record from your phone or laptop — no hardware needed. Capture audio or text input instantly.'],
            ['kicker' => 'Analyze', 'title' => 'AI-powered analysis', 'body' => 'Automatic analysis with speaker diarization, custom vocabulary, and confidence scoring.'],
            ['kicker' => 'Deliver', 'title' => 'Insights & tasks', 'body' => 'AI generates structured insights and extracts tasks with owners and deadlines.'],
        ],
    ],

    'capabilities' => [
        'title' => 'Everything your team needs',
        'subtitle' => 'Built for organizations that take data seriously.',
        'items' => [
            ['icon' => '📱', 'title' => 'Record Anywhere', 'body' => 'Use the iOS or Android app to capture any workflow. No special hardware required.'],
            ['icon' => '📝', 'title' => 'AI Insights', 'body' => 'Automatic insights with decisions, tasks, and key outcomes.'],
            ['icon' => '✅', 'title' => 'Task Tracking', 'body' => 'Track tasks with assignments, due dates, and status across all workflows.'],
            ['icon' => '🔗', 'title' => 'Integrations', 'body' => 'Connect with Jira, Asana, Slack, Teams, Google Calendar, and Outlook.'],
            ['icon' => '🔒', 'title' => 'Compliance', 'body' => 'Audit trails, legal holds, data retention policies, and e-discovery search.'],
            ['icon' => '🎙', 'title' => 'Flexible Input', 'body' => 'Push-to-talk or open mic options. Structured queues for formal interaction.'],
        ],
    ],

    // No testimonials yet — the product has not launched and has no customers.
    // Do not add fabricated quotes here; only real, attributable customer
    // testimonials belong in this array.
    'testimonials' => [],

    'deep_sections' => [
        [
            'eyebrow' => 'For Organizations',
            'title' => 'Workflow governance made simple',
            'body' => 'From boardrooms to team stand-ups, manage every process with structure, accountability, and compliance.',
            'features' => [
                ['kicker' => 'Live Tagging', 'title' => 'Real-time Markers', 'body' => 'Tag decisions, tasks, and risks in real-time.'],
                ['kicker' => 'Collaboration', 'title' => 'Permission Control', 'body' => 'Structured access and role-based permissions for all documentation.'],
                ['kicker' => 'Governance', 'title' => 'Compliance Suite', 'body' => 'Audit trails, legal holds, and data retention for regulated industries.'],
            ],
            'media' => [
                'type' => 'image',
                'src' => '/assets/img/marketing/rbac.png',
                'alt' => 'Workflow management dashboard',
            ],
        ],
    ],

    'faqs' => [
        ['q' => 'How does the free plan work?', 'a' => 'The free plan includes up to 5 workflows per month with 3 team members. No credit card required. Upgrade anytime to unlock recording, analysis, and AI insights.'],
        ['q' => 'Can I switch plans at any time?', 'a' => 'Yes! Upgrades take effect immediately with prorated billing. Downgrades take effect at the end of your current billing period.'],
        ['q' => 'What payment methods do you accept?', 'a' => 'We accept all major credit/debit cards, mobile money, and bank transfers through Paystack. We support payments in USD and GHS.'],
        ['q' => 'Is my data secure?', 'a' => 'Absolutely. Each organization gets isolated data storage. Enterprise plans include compliance features like audit trails, legal holds, and data retention policies.'],
        ['q' => 'Do you offer annual billing?', 'a' => 'Yes! Save 17% with annual billing on all paid plans.'],
    ],

    'final_cta' => [
        'title' => 'Ready to transform your workflow?',
        'subtitle' => 'Start free and upgrade when your team needs more. No credit card required.',
        'cta_primary' => ['label' => 'Start Free', 'href' => '/register'],
        'cta_secondary' => ['label' => 'Contact Sales', 'href' => '/contact'],
    ],

    'footer' => [
        'tagline' => 'AI-powered insights and task tracking for modern organizations.',
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
