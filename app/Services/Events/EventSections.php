<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;

/**
 * The sections of an event's workspace, grouped by the job the organizer is
 * doing rather than listed as 25 peers.
 *
 * This is the one place a section's name, group and gate are defined. The menu
 * is built from it, and each section's page checks the same gate, so a section
 * cannot be shown to someone who would be refused on opening it.
 *
 * `permission` is what viewing the section needs; the section's own endpoints
 * still enforce their stricter write permissions. `feature` is a plan feature
 * the section depends on.
 */
final class EventSections
{
    public const string OVERVIEW = 'overview';

    /**
     * @var list<array{group: string|null, sections: array<string, array{label: string, permission: string, feature?: string}>}>
     */
    private const array GROUPS = [
        ['group' => null, 'sections' => [
            'overview' => ['label' => 'Overview', 'permission' => 'read event'],
        ]],
        ['group' => 'Registration', 'sections' => [
            'guests' => ['label' => 'Guests', 'permission' => 'read event'],
            'tickets' => ['label' => 'Tickets', 'permission' => 'read event'],
            'promo-codes' => ['label' => 'Promo codes', 'permission' => 'read event'],
            'registration-form' => ['label' => 'Registration form', 'permission' => 'read event'],
            'attendee-groups' => ['label' => 'Attendee groups', 'permission' => 'manage participant-groups'],
        ]],
        ['group' => 'Programme', 'sections' => [
            'schedule' => ['label' => 'Schedule', 'permission' => 'read event'],
            'speakers' => ['label' => 'Speakers', 'permission' => 'read event'],
            'abstracts' => ['label' => 'Abstracts', 'permission' => 'read abstract'],
            'materials' => ['label' => 'Materials', 'permission' => 'read event', 'feature' => 'event_materials'],
        ]],
        ['group' => 'Engagement', 'sections' => [
            'announcements' => ['label' => 'Announcements', 'permission' => 'read event'],
            'automations' => ['label' => 'Automations', 'permission' => 'read notification-rule'],
            'surveys' => ['label' => 'Surveys', 'permission' => 'read dynamic-form'],
            'live-polls' => ['label' => 'Live polls', 'permission' => 'read event'],
            'forum' => ['label' => 'Forum', 'permission' => 'read event'],
        ]],
        ['group' => 'Event day', 'sections' => [
            // Every check-in endpoint needs "update event": someone who could
            // open this section but not scan would only ever be refused.
            'check-in' => ['label' => 'Check-in', 'permission' => 'update event'],
            'badges' => ['label' => 'Badges', 'permission' => 'read event'],
            'room-headcount' => ['label' => 'Room headcount', 'permission' => 'read event'],
            'help-requests' => ['label' => 'Help requests', 'permission' => 'read event'],
        ]],
        ['group' => 'Logistics', 'sections' => [
            'venue' => ['label' => 'Venue & seating', 'permission' => 'read event'],
            'planning' => ['label' => 'Planning', 'permission' => 'read event-operation'],
            'sponsors' => ['label' => 'Sponsors', 'permission' => 'read event'],
        ]],
        ['group' => 'Results', 'sections' => [
            'finance' => ['label' => 'Finance', 'permission' => 'read finance', 'feature' => 'finance'],
            'reports' => ['label' => 'Reports', 'permission' => 'read event'],
            'certificates' => ['label' => 'Certificates', 'permission' => 'read certificate'],
        ]],
    ];

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_merge(...array_map(
            fn (array $group): array => array_keys($group['sections']),
            self::GROUPS,
        ));
    }

    public static function exists(string $slug): bool
    {
        return in_array($slug, self::slugs(), true);
    }

    public static function label(string $slug): ?string
    {
        foreach (self::GROUPS as $group) {
            if (isset($group['sections'][$slug])) {
                return $group['sections'][$slug]['label'];
            }
        }

        return null;
    }

    public function allows(User $user, Tenant $tenant, Event $event, string $slug): bool
    {
        foreach (self::GROUPS as $group) {
            if (isset($group['sections'][$slug])) {
                return $this->passes($user, $tenant, $event, $group['sections'][$slug]);
            }
        }

        return false;
    }

    /**
     * The menu for this user: only the groups and sections they can open.
     *
     * @return list<array{name: string|null, sections: list<array{slug: string, label: string}>}>
     */
    public function menuFor(User $user, Tenant $tenant, Event $event): array
    {
        $menu = [];

        foreach (self::GROUPS as $group) {
            $sections = [];
            foreach ($group['sections'] as $slug => $definition) {
                if ($this->passes($user, $tenant, $event, $definition)) {
                    $sections[] = ['slug' => $slug, 'label' => $definition['label']];
                }
            }

            if ($sections !== []) {
                $menu[] = ['name' => $group['group'], 'sections' => $sections];
            }
        }

        return $menu;
    }

    /**
     * @param  array{label: string, permission: string, feature?: string}  $definition
     */
    private function passes(User $user, Tenant $tenant, Event $event, array $definition): bool
    {
        if (! $user->can($definition['permission'])) {
            return false;
        }

        return match ($definition['feature'] ?? null) {
            null => true,
            // Money already taken still has to be refunded or paid out after
            // a plan lapses, so this follows the money, not the plan.
            'finance' => $tenant->handlesTicketMoney(),
            // Materials already uploaded stay reachable after a downgrade.
            'event_materials' => $tenant->planAllows('event_materials') || $event->materials()->exists(),
            default => $tenant->planAllows($definition['feature']),
        };
    }
}
