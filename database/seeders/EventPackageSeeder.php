<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Package;
use Illuminate\Database\Seeder;

final class EventPackageSeeder extends Seeder
{
    /**
     * @var array<string, array{name: string, type: string, description: string}>
     */
    private array $features = [
        'events_in_flight' => ['name' => 'Concurrent Live Events', 'type' => 'limit', 'description' => 'Maximum non-archived events at a time.'],
        'event_registrations' => ['name' => 'Registrations Per Month', 'type' => 'limit', 'description' => 'Maximum confirmed registrations across all events per month.'],
        'team_seats' => ['name' => 'Team Seats', 'type' => 'limit', 'description' => 'Maximum team members for this organization.'],
        'email_credits' => ['name' => 'Email Credits Per Month', 'type' => 'limit', 'description' => 'Maximum event-related emails sent per month.'],
        'sms_credits' => ['name' => 'SMS Credits Per Month', 'type' => 'limit', 'description' => 'Maximum SMS messages sent per month. Reserved: SMS sending does not exist yet.'],
        'paid_tickets' => ['name' => 'Paid Ticket Sales', 'type' => 'boolean', 'description' => 'Sell tickets and collect payments. Free events remain available on every plan.'],
        'event_materials' => ['name' => 'Speaker Materials', 'type' => 'boolean', 'description' => 'Upload files attendees can download from the event page.'],
        'remove_platform_branding' => ['name' => 'Remove Platform Branding', 'type' => 'boolean', 'description' => 'Hide platform branding on public event pages and emails.'],
        'custom-domains' => ['name' => 'Custom Domains', 'type' => 'boolean', 'description' => 'Use your own domain for your workspace.'],
        'csv_settlement_export' => ['name' => 'CSV Settlement Export', 'type' => 'boolean', 'description' => 'Export the settlement statement as CSV.'],
        'white_label' => ['name' => 'White Label', 'type' => 'boolean', 'description' => 'Replace MiConvener branding with your own logo across the console, emails and tickets.'],
        'sso' => ['name' => 'SSO Integration', 'type' => 'boolean', 'description' => 'Reserved: Single Sign-On. Not enforced yet — no SAML/OIDC integration exists.'],
        'api_access' => ['name' => 'API Access', 'type' => 'boolean', 'description' => 'Reserved: self-service webhook/API management. Not enforced yet — no tenant-facing management UI exists.'],
    ];

    /**
     * @var array<string, array{name: string, description: string, monthly: float, yearly: float, sort: int, is_free: bool, default_fee: float|null, features: array<string, int|bool>}>
     */
    private array $packages = [
        'free' => [
            'name' => 'Free',
            'description' => 'Get started with a single event.',
            'monthly' => 0,
            'yearly' => 0,
            'sort' => 0,
            'is_free' => true,
            'default_fee' => 5.0,
            'features' => [
                'events_in_flight' => 1,
                'event_registrations' => 50,
                'team_seats' => 1,
                'email_credits' => 200,
                'sms_credits' => 0,
                'paid_tickets' => false,
                'event_materials' => false,
                'remove_platform_branding' => false,
                'custom-domains' => false,
                'csv_settlement_export' => false,
                'white_label' => false,
                'sso' => false,
                'api_access' => false,
            ],
        ],
        'starter' => [
            'name' => 'Starter',
            'description' => 'For teams running a few events a month.',
            'monthly' => 29,
            'yearly' => 264,
            'sort' => 1,
            'is_free' => false,
            'default_fee' => 3.5,
            'features' => [
                'events_in_flight' => 3,
                'event_registrations' => 500,
                'team_seats' => 3,
                'email_credits' => 1000,
                'sms_credits' => 100,
                'paid_tickets' => true,
                'event_materials' => true,
                'remove_platform_branding' => false,
                'custom-domains' => false,
                'csv_settlement_export' => true,
                'white_label' => false,
                'sso' => false,
                'api_access' => false,
            ],
        ],
        'growth' => [
            'name' => 'Growth',
            'description' => 'For organizations running events at scale.',
            'monthly' => 99,
            'yearly' => 900,
            'sort' => 2,
            'is_free' => false,
            'default_fee' => 2.0,
            'features' => [
                'events_in_flight' => 15,
                'event_registrations' => 3000,
                'team_seats' => 10,
                'email_credits' => 10000,
                'sms_credits' => 1000,
                'paid_tickets' => true,
                'event_materials' => true,
                'remove_platform_branding' => true,
                'custom-domains' => true,
                'csv_settlement_export' => true,
                'white_label' => false,
                'sso' => false,
                'api_access' => false,
            ],
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'description' => 'Custom limits and negotiated commission for large organizations.',
            'monthly' => 0,
            'yearly' => 0,
            'sort' => 3,
            'is_free' => false,
            'default_fee' => null,
            'features' => [
                'events_in_flight' => -1,
                'event_registrations' => -1,
                'team_seats' => -1,
                'email_credits' => -1,
                'sms_credits' => -1,
                'paid_tickets' => true,
                'event_materials' => true,
                'remove_platform_branding' => true,
                'custom-domains' => true,
                'csv_settlement_export' => true,
                'white_label' => true,
                'sso' => true,
                'api_access' => true,
            ],
        ],
    ];

    public function run(): void
    {
        $this->seedFeatures();
        $this->seedPackages();
    }

    private function seedFeatures(): void
    {
        foreach ($this->features as $slug => $data) {
            Feature::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'description' => $data['description'],
                ]
            );
        }

        $this->command?->info('Seeded '.count($this->features).' event-tier features.');
    }

    private function seedPackages(): void
    {
        foreach ($this->packages as $slug => $config) {
            $package = Package::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $config['name'],
                    'description' => $config['description'],
                    'price' => $config['monthly'],
                    'yearly_price' => $config['yearly'],
                    'interval' => 'month',
                    'billing_model' => Package::BILLING_MODEL_FLAT_RATE,
                    'is_active' => true,
                    'is_free' => $config['is_free'],
                    'sort_order' => $config['sort'],
                    'default_platform_fee_percentage' => $config['default_fee'],
                ]
            );

            $syncData = [];
            foreach ($config['features'] as $featureSlug => $value) {
                $feature = Feature::where('slug', $featureSlug)->first();
                if ($feature) {
                    $syncData[$feature->id] = ['value' => $value];
                }
            }

            $package->features()->sync($syncData);

            $this->command?->info("Seeded package: {$config['name']} ({$slug}) with ".count($syncData).' features.');
        }
    }
}
