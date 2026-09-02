<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Package;
use Illuminate\Database\Seeder;

final class MasterFeaturePackageSeeder extends Seeder
{
    /**
     * All features in the system with their type and description.
     *
     * @var array<string, array{name: string, type: string, description: string}>
     */
    private array $features = [
        // Core meetings
        'meetings' => ['name' => 'Meetings', 'type' => 'boolean', 'description' => 'Core meeting creation, scheduling, and participant management.'],
        'meeting_quota' => ['name' => 'Meeting Quota', 'type' => 'limit', 'description' => 'Maximum meetings per billing period.'],
        'meeting_recording' => ['name' => 'Meeting Recording', 'type' => 'boolean', 'description' => 'Audio recording with push-to-talk or open mic modes.'],
        'meeting_transcription' => ['name' => 'Meeting Transcription', 'type' => 'boolean', 'description' => 'AI-powered audio transcription via AWS Transcribe.'],
        'custom_vocabulary' => ['name' => 'Custom Vocabulary', 'type' => 'boolean', 'description' => 'Tenant-specific vocabulary for improved transcription accuracy.'],
        'room_capture' => ['name' => 'Room Capture', 'type' => 'boolean', 'description' => 'Secretary ambient room audio capture with speaker diarization.'],

        // AI & Minutes
        'meeting_minutes_ai' => ['name' => 'AI Meeting Minutes', 'type' => 'boolean', 'description' => 'AI-generated meeting minutes from transcriptions.'],
        'meeting_minutes_editor' => ['name' => 'Advanced Minutes Editor', 'type' => 'boolean', 'description' => 'Version history, diff comparison, comments for meeting minutes.'],
        'meeting_action_tracking' => ['name' => 'Action Item Tracking', 'type' => 'boolean', 'description' => 'Track action items, assignments, and reminders from meetings.'],

        // Meeting tools
        'secretary_markers' => ['name' => 'Secretary Markers', 'type' => 'boolean', 'description' => 'Real-time markers for tagging decisions, actions, risks during meetings.'],
        'floor_control' => ['name' => 'Floor Control', 'type' => 'boolean', 'description' => 'Structured speaker queue with floor management for formal meetings.'],

        // Integrations
        'calendar_integrations' => ['name' => 'Calendar Integrations', 'type' => 'boolean', 'description' => 'Google Calendar and Outlook calendar sync.'],
        'pm_integrations' => ['name' => 'PM Integrations', 'type' => 'boolean', 'description' => 'Jira, Asana, Linear, and Monday project management sync.'],
        'team_communication' => ['name' => 'Team Communication', 'type' => 'boolean', 'description' => 'Slack and Microsoft Teams notifications and commands.'],
        'custom_webhooks' => ['name' => 'Custom Webhooks', 'type' => 'boolean', 'description' => 'Custom webhook endpoints for event-driven integrations.'],

        // Platform
        'analytics' => ['name' => 'Analytics', 'type' => 'boolean', 'description' => 'Access to analytics and reporting dashboard.'],
        'custom-alerts' => ['name' => 'Custom Alerts', 'type' => 'boolean', 'description' => 'Custom notification alerts and thresholds.'],
        'priority-support' => ['name' => 'Priority Support', 'type' => 'boolean', 'description' => 'Priority email and chat support.'],
        'sso' => ['name' => 'SSO Integration', 'type' => 'boolean', 'description' => 'Single Sign-On with SAML 2.0 support.'],
        'custom-domains' => ['name' => 'Custom Domains', 'type' => 'boolean', 'description' => 'Use your own domain for your workspace.'],
        'white_label' => ['name' => 'White Label', 'type' => 'boolean', 'description' => 'Remove branding and use your own identity.'],
        'commerce' => ['name' => 'Commerce / Finance', 'type' => 'boolean', 'description' => 'Collect payments from your own customers.'],

        // Compliance
        'compliance_audit_log' => ['name' => 'Audit Log', 'type' => 'boolean', 'description' => 'Complete audit trail of all system actions.'],
        'compliance_legal_hold' => ['name' => 'Legal Hold', 'type' => 'boolean', 'description' => 'Prevent deletion of meeting data under legal hold.'],
        'compliance_data_retention' => ['name' => 'Data Retention', 'type' => 'boolean', 'description' => 'Configurable data retention policies.'],
        'compliance_data_export' => ['name' => 'Data Export', 'type' => 'boolean', 'description' => 'Export meeting data in standard formats.'],
        'compliance_ediscovery' => ['name' => 'E-Discovery', 'type' => 'boolean', 'description' => 'Full-text search across all meeting content.'],
        'compliance_reports' => ['name' => 'Compliance Reports', 'type' => 'boolean', 'description' => 'Generate compliance and governance reports.'],

        // Limits
        'users-limit' => ['name' => 'Users Limit', 'type' => 'limit', 'description' => 'Maximum number of team members.'],
        'projects-limit' => ['name' => 'Projects Limit', 'type' => 'limit', 'description' => 'Maximum number of projects.'],
        'file-retention' => ['name' => 'File Retention (Days)', 'type' => 'limit', 'description' => 'Days to keep uploaded files.'],
        'llm_token_quota' => ['name' => 'LLM Token Quota', 'type' => 'limit', 'description' => 'AI token allocation per billing period.'],
        'llm_byok' => ['name' => 'LLM Bring Your Own Key', 'type' => 'boolean', 'description' => 'Use your own API keys for AI providers.'],
    ];

    /**
     * Package definitions with pricing and feature allocations.
     *
     * @var array<string, array{name: string, description: string, monthly: int, yearly: int, sort: int, is_free: bool, features: array<string, mixed>}>
     */
    private array $packages = [
        'free' => [
            'name' => 'Free',
            'description' => 'For individuals and small teams getting started.',
            'monthly' => 0,
            'yearly' => 0,
            'sort' => 0,
            'is_free' => true,
            'features' => [
                'meetings' => true,
                'meeting_quota' => 5,
                'users-limit' => 3,
                'projects-limit' => 1,
                'file-retention' => 7,
                'analytics' => true,
                'llm_token_quota' => 5000,
            ],
        ],
        'pro' => [
            'name' => 'Pro',
            'description' => 'For growing teams that need recording, transcription & AI.',
            'monthly' => 19,
            'yearly' => 190,
            'sort' => 1,
            'is_free' => false,
            'features' => [
                'meetings' => true,
                'meeting_quota' => 50,
                'meeting_recording' => true,
                'meeting_transcription' => true,
                'meeting_minutes_ai' => true,
                'meeting_action_tracking' => true,
                'secretary_markers' => true,
                'calendar_integrations' => true,
                'analytics' => true,
                'custom-alerts' => true,
                'users-limit' => 15,
                'projects-limit' => 10,
                'file-retention' => 30,
                'llm_token_quota' => 50000,
            ],
        ],
        'business' => [
            'name' => 'Business',
            'description' => 'For teams that need integrations, collaboration & higher limits.',
            'monthly' => 49,
            'yearly' => 490,
            'sort' => 2,
            'is_free' => false,
            'features' => [
                'meetings' => true,
                'meeting_quota' => 200,
                'meeting_recording' => true,
                'meeting_transcription' => true,
                'custom_vocabulary' => true,
                'meeting_minutes_ai' => true,
                'meeting_minutes_editor' => true,
                'meeting_action_tracking' => true,
                'secretary_markers' => true,
                'floor_control' => true,
                'calendar_integrations' => true,
                'pm_integrations' => true,
                'team_communication' => true,
                'analytics' => true,
                'custom-alerts' => true,
                'priority-support' => true,
                'users-limit' => 50,
                'projects-limit' => -1,
                'file-retention' => 90,
                'llm_token_quota' => 200000,
                'llm_byok' => true,
            ],
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'description' => 'For large organizations with compliance & governance needs.',
            'monthly' => 99,
            'yearly' => 990,
            'sort' => 3,
            'is_free' => false,
            'features' => [
                'meetings' => true,
                'meeting_quota' => -1,
                'meeting_recording' => true,
                'meeting_transcription' => true,
                'custom_vocabulary' => true,
                'room_capture' => true,
                'meeting_minutes_ai' => true,
                'meeting_minutes_editor' => true,
                'meeting_action_tracking' => true,
                'secretary_markers' => true,
                'floor_control' => true,
                'calendar_integrations' => true,
                'pm_integrations' => true,
                'team_communication' => true,
                'custom_webhooks' => true,
                'analytics' => true,
                'custom-alerts' => true,
                'priority-support' => true,
                'sso' => true,
                'custom-domains' => true,
                'white_label' => true,
                'commerce' => true,
                'compliance_audit_log' => true,
                'compliance_legal_hold' => true,
                'compliance_data_retention' => true,
                'compliance_data_export' => true,
                'compliance_ediscovery' => true,
                'compliance_reports' => true,
                'users-limit' => -1,
                'projects-limit' => -1,
                'file-retention' => -1,
                'llm_token_quota' => 1000000,
                'llm_byok' => true,
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
            Feature::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'description' => $data['description'],
                ]
            );
        }

        $this->command?->info('Seeded '.count($this->features).' features.');
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
                ]
            );

            // Sync features for this package
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
