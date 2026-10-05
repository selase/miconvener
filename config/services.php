<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    /*
     * SES API access, used to register organiser sending domains. Its own keys,
     * because on Laravel Cloud the AWS_* variables belong to the object storage
     * bucket (region "auto"), not to AWS. Mail itself goes out over SES SMTP.
     */
    'ses' => [
        'key' => env('SES_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
        'secret' => env('SES_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
        'region' => env('SES_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'paystack' => [
        /*
         * Stamped into the metadata of every transaction this application starts,
         * and required before the webhook will act on one. The platform's Paystack
         * account is shared with other applications, so a single webhook URL
         * receives their events too: without a marker of our own, a charge
         * belonging to another application whose customer email happens to match a
         * user here would be read as one of ours.
         */
        'metadata_source' => env('PAYSTACK_METADATA_SOURCE', 'miconvener'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'merchant_email' => env('PAYSTACK_MERCHANT_EMAIL'),
        'currency' => env('PAYSTACK_CURRENCY', 'GHS'),
    ],

    /*
     * Outgoing SMS. The platform sends through one provider today; the driver
     * is a setting so a different provider -- or, later, a tenant's own
     * Twilio or mNotify account -- can be added without touching callers.
     * With no token configured, SMS is switched off rather than broken.
     */
    /*
     * Marketplace money (decided 2026-10-05). Businesses pay a commission on
     * every venue booking and vendor quote they are paid for; buyers pay a
     * service fee on top of what they pay, by their plan (none on Growth and
     * Enterprise; a buyer with no MiConvener plan pays the Free rate).
     */
    'marketplace' => [
        'commission_percent' => (float) env('MARKETPLACE_COMMISSION_PERCENT', 10),
        'buyer_fee_percent' => [
            'free' => 3.0,
            'starter' => 2.0,
            'growth' => 0.0,
            'enterprise' => 0.0,
        ],
    ],

    /*
     * What MiConvener keeps from a voluntary contribution (donation, offering,
     * tribute): 1%, uncapped (decided 2026-10-05). An empty value charges the
     * event's ticket commission instead, capped the same way.
     */
    'contributions' => [
        'platform_fee_percentage' => env('CONTRIBUTION_PLATFORM_FEE_PERCENTAGE', 1),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'omnichannel'),
    ],

    'omnichannel' => [
        'url' => env('OMNICHANNEL_URL', 'https://messaging.klevelly.com'),
        'token' => env('OMNICHANNEL_TOKEN'),
        // A sender name registered and approved on the Omnichannel platform.
        'sender_id' => env('OMNICHANNEL_SENDER_ID', 'MiConvener'),
    ],

    'payment' => [
        'default' => env('PAYMENT_DRIVER', 'paystack'),
        'dev_bypass' => env('PAYMENT_DEV_BYPASS', false),
    ],

    'platform' => [
        'default_fee_percentage' => (float) env('PLATFORM_DEFAULT_FEE_PERCENTAGE', 5.0),

        /*
         * Commission ceiling per ticket, in minor units, as a last resort.
         *
         * Null — uncapped — is deliberate. The paid tiers carry their own
         * GHS 20 ceiling as a package default, so the only packages reaching
         * this line are Enterprise, whose terms are negotiated on the tenant
         * row. A number here would silently cap those negotiated deals.
         */
        'default_fee_cap_amount' => env('PLATFORM_DEFAULT_FEE_CAP_AMOUNT') !== null
            ? (int) env('PLATFORM_DEFAULT_FEE_CAP_AMOUNT')
            : null,

        /*
         * Who pays the platform commission: 'organizer' absorbs it out of the
         * ticket price, 'attendee' pays it on top of what the ticket costs.
         */
        'default_fee_bearer' => env('PLATFORM_DEFAULT_FEE_BEARER', 'organizer'),

        /*
         * Paystack Ghana's published collection rate. Used only for the
         * estimate shown before a sale — the ledger books the actual fee the
         * webhook reports.
         */
        'gateway_fee_percentage' => (float) env('PLATFORM_GATEWAY_FEE_PERCENTAGE', 1.95),

        /*
         * Paystack Ghana transfer fees in minor units, keyed by the values of
         * TenantPayoutAccount::TYPE_MOBILE_MONEY and TYPE_BANK so no mapping
         * layer is needed between a payout account and its fee.
         */
        'transfer_fees' => [
            'mobile_money' => (int) env('PLATFORM_TRANSFER_FEE_MOMO', 100),
            'bank' => (int) env('PLATFORM_TRANSFER_FEE_BANK', 800),
        ],
    ],

    'settlement' => [
        'default' => env('SETTLEMENT_DRIVER', 'paystack'),
        'paystack' => [
            // Paystack has no separate webhook-signing secret (unlike
            // Stripe) — it signs webhooks with this same secret key, so
            // the settlement webhook verifies against secret_key too.
            //
            // One Paystack account takes both the subscription revenue and the
            // ticket money collected on a tenant's behalf, so settlement shares
            // the platform's credentials rather than duplicating them. The
            // override below exists only for the day settlement moves to its own
            // account or provider. Leave it unset: two keys that must always be
            // equal are a trap, because a mismatch is rejected only after the
            // customer has already paid.
            'secret_key' => env('SETTLEMENT_PAYSTACK_SECRET_KEY') ?: env('PAYSTACK_SECRET_KEY'),
            'public_key' => env('SETTLEMENT_PAYSTACK_PUBLIC_KEY') ?: env('PAYSTACK_PUBLIC_KEY'),
        ],
    ],

];
