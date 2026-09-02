<?php

declare(strict_types=1);

return [
    'brand' => [
        'name' => 'xData Audition',
        'logo_text' => 'xA',
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

    'plans' => [
        [
            'name' => 'Free',
            'slug' => 'free',
            'description' => 'For individuals and small teams getting started.',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'most_popular' => false,
            'features' => [
                'Up to 5 workflows per month',
                'Up to 3 team members',
                'Basic scheduling',
                'Basic analytics',
                '7-day file retention',
            ],
            'cta' => [
                'label' => 'Get Started Free',
                'href' => '/register?plan=free',
            ],
        ],
        [
            'name' => 'Pro',
            'slug' => 'pro',
            'description' => 'For growing teams that need recording, transcription & AI.',
            'monthly_price' => 19,
            'yearly_price' => 190,
            'most_popular' => true,
            'features' => [
                'Up to 50 workflows per month',
                'Up to 15 team members',
                'Audio analysis & transcription',
                'AI summaries & insights',
                'Task item tracking',
                'Custom markers',
                'Calendar integrations',
                'Custom alerts',
                '30-day file retention',
            ],
            'cta' => [
                'label' => 'Start 14-day trial',
                'href' => '/register?plan=pro',
            ],
        ],
        [
            'name' => 'Business',
            'slug' => 'business',
            'description' => 'For teams that need integrations, collaboration & higher limits.',
            'monthly_price' => 49,
            'yearly_price' => 490,
            'most_popular' => false,
            'features' => [
                'Everything in Pro',
                'Up to 200 workflows per month',
                'Up to 50 team members',
                'Custom vocabulary for analysis',
                'Advanced insight editor',
                'Role-based access control',
                'Jira, Asana, Linear integrations',
                'Slack & Teams notifications',
                'Priority support',
                '90-day file retention',
            ],
            'cta' => [
                'label' => 'Start 14-day trial',
                'href' => '/register?plan=business',
            ],
        ],
        [
            'name' => 'Enterprise',
            'slug' => 'enterprise',
            'description' => 'For large organizations with compliance & governance needs.',
            'monthly_price' => 99,
            'yearly_price' => 990,
            'most_popular' => false,
            'features' => [
                'Everything in Business',
                'Unlimited workflows & team members',
                'Enhanced security & diarization',
                'Custom webhooks',
                'SSO & SAML integration',
                'Custom domains & white labeling',
                'Full compliance suite (audit, legal hold, e-discovery)',
                'Bring your own LLM key',
                'Unlimited file retention',
                'Dedicated support',
            ],
            'cta' => [
                'label' => 'Start 14-day trial',
                'href' => '/register?plan=enterprise',
            ],
        ],
    ],

    'intro' => [
        'eyebrow' => 'How it works',
        'title' => 'From workflow to insights in minutes',
        'body' => 'Stop wasting hours on manual documentation. xData Audition captures, analyzes, and generates actionable insights automatically.',
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

    'testimonials' => [
        [
            'quote' => 'xData Audition has completely transformed how we document our internal processes. We went from days to minutes.',
            'name' => 'Kwame Asante',
            'role' => 'Operations Head',
            'company' => 'GoldStar Holdings',
        ],
        [
            'quote' => 'The task tracking alone has improved our follow-through rate by 60%. The AI insights are a game changer.',
            'name' => 'Sarah Chen',
            'role' => 'Project Director',
            'company' => 'TechBridge',
        ],
    ],

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
