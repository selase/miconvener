<?php

declare(strict_types=1);

namespace App\Services\Operations;

/**
 * The commands a global Superadmin may run from the console.
 *
 * This list is the security boundary. A request names a key from it and
 * nothing else: the Artisan string, and every option that may accompany it,
 * are written here rather than taken from the browser. Anything absent is
 * simply not runnable from the web, which is where the one-off and
 * infrastructure commands are meant to stay.
 */
final class OperationalCommands
{
    /**
     * @var array<string, array{
     *     command: string,
     *     label: string,
     *     description: string,
     *     group: string,
     *     guarded: bool,
     *     options: array<string, string>
     * }>
     */
    private const array COMMANDS = [
        'health-check' => [
            'command' => 'health:check',
            'label' => 'Run health checks',
            'description' => 'Re-run every health check now instead of waiting for the schedule.',
            'group' => 'Diagnostics',
            'guarded' => false,
            'options' => [],
        ],
        'diagnose' => [
            'command' => 'app:diagnose',
            'label' => 'Diagnose environment',
            'description' => 'Report how this environment is configured: drivers, connections and queues.',
            'group' => 'Diagnostics',
            'guarded' => false,
            'options' => [],
        ],
        'prompt-recovery-codes' => [
            'command' => 'auth:prompt-recovery-codes',
            'label' => 'Ask users to set up recovery codes',
            'description' => 'Emails anyone with two-factor authentication on but no recovery codes. Skips those who already have them.',
            'group' => 'Accounts',
            'guarded' => false,
            'options' => ['--dry-run' => 'List who would be emailed, without sending'],
        ],
        'check-usage-alerts' => [
            'command' => 'usage:check-alerts',
            'label' => 'Check usage limits',
            'description' => 'Evaluate every tenant against their plan limits and raise alerts.',
            'group' => 'Usage',
            'guarded' => false,
            'options' => [],
        ],
        'process-rollups' => [
            'command' => 'usage:process-rollups',
            'label' => 'Process usage rollups',
            'description' => 'Aggregate usage into hourly and daily rollups.',
            'group' => 'Usage',
            'guarded' => false,
            'options' => [],
        ],
        'aggregate-llm-usage' => [
            'command' => 'llm:aggregate-usage',
            'label' => 'Aggregate LLM usage',
            'description' => 'Roll raw token logs into the summaries the usage pages read.',
            'group' => 'Usage',
            'guarded' => false,
            'options' => [],
        ],
        'reconcile-payouts' => [
            'command' => 'payouts:reconcile',
            'label' => 'Reconcile stuck payouts',
            'description' => 'Ask the settlement provider to resolve transfers still sitting in processing.',
            'group' => 'Money',
            'guarded' => false,
            'options' => [],
        ],
        'reconcile-payout-schedules' => [
            'command' => 'app:reconcile-payout-schedules',
            'label' => 'Reconcile payout schedules',
            'description' => 'Apply event payout schedules and holdback reserves. Records scheduled payouts; does not send transfers.',
            'group' => 'Money',
            'guarded' => false,
            'options' => [],
        ],

        // Everything below either takes money or writes to people's inboxes.
        'process-renewals' => [
            'command' => 'billing:process-renewals',
            'label' => 'Process plan renewals',
            'description' => 'Charges saved cards, sends renewal reminders, and moves lapsed tenants to Free. Runs daily at 07:00 on its own.',
            'group' => 'Money',
            'guarded' => true,
            'options' => [],
        ],
        'generate-invoices' => [
            'command' => 'billing:generate-invoices',
            'label' => 'Generate monthly invoices',
            'description' => 'Create this period\'s invoices from usage rollups.',
            'group' => 'Money',
            'guarded' => true,
            'options' => [],
        ],
        'daily-summary' => [
            'command' => 'billing:daily-summary',
            'label' => 'Send billing summary',
            'description' => 'Email superadmins what billing did in the last day.',
            'group' => 'Money',
            'guarded' => true,
            'options' => [],
        ],
        'dispatch-notifications' => [
            'command' => 'app:dispatch-automated-notifications',
            'label' => 'Dispatch event notifications',
            'description' => 'Send scheduled attendee notifications that are due. This reaches event attendees, not staff.',
            'group' => 'Communications',
            'guarded' => true,
            'options' => [],
        ],
    ];

    /**
     * @return array<string, array{command: string, label: string, description: string, group: string, guarded: bool, options: array<string, string>}>
     */
    public static function all(): array
    {
        return self::COMMANDS;
    }

    /**
     * @return array<string, array<string, array{command: string, label: string, description: string, group: string, guarded: bool, options: array<string, string>, key: string}>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::COMMANDS as $key => $definition) {
            $grouped[$definition['group']][$key] = $definition + ['key' => $key];
        }

        return $grouped;
    }

    /**
     * @return array{command: string, label: string, description: string, group: string, guarded: bool, options: array<string, string>}|null
     */
    public static function find(string $key): ?array
    {
        return self::COMMANDS[$key] ?? null;
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::COMMANDS);
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::COMMANDS);
    }
}
