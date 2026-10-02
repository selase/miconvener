<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventSession;

final class EventLexicon
{
    /**
     * @return array{
     *     category: string,
     *     category_label: string,
     *     contributions_title: string,
     *     contributions_subtitle: string,
     *     contributions_tab_label: string,
     *     contributions_cta_label: string,
     *     contributions_panel_title: string,
     *     contributions_panel_subtitle: string,
     *     message_field_label: string,
     *     message_placeholder: string,
     *     wall_title: string,
     *     wall_subtitle: string,
     *     notes_label: string,
     *     notes_placeholder: string,
     *     notes_action_label: string,
     *     session_types: array<string, string>,
     * }
     */
    public static function forEvent(Event $event): array
    {
        return self::forCategory($event->event_category ?? Event::CATEGORY_GENERAL);
    }

    /**
     * @return array{
     *     category: string,
     *     category_label: string,
     *     contributions_title: string,
     *     contributions_subtitle: string,
     *     contributions_tab_label: string,
     *     contributions_cta_label: string,
     *     contributions_panel_title: string,
     *     contributions_panel_subtitle: string,
     *     message_field_label: string,
     *     message_placeholder: string,
     *     wall_title: string,
     *     wall_subtitle: string,
     *     notes_label: string,
     *     notes_placeholder: string,
     *     notes_action_label: string,
     *     session_types: array<string, string>,
     * }
     */
    public static function forCategory(?string $category): array
    {
        $cat = $category ?? Event::CATEGORY_GENERAL;

        return match ($cat) {
            Event::CATEGORY_FAITH => [
                'category' => Event::CATEGORY_FAITH,
                'category_label' => 'Church / Faith & Ministry',
                'contributions_title' => 'Tithes & Offerings',
                'contributions_subtitle' => 'Support ministry, community outreach, and missions with your generous giving.',
                'contributions_tab_label' => 'Tithes & Offerings',
                'contributions_cta_label' => 'Give / Tithe',
                'contributions_panel_title' => 'Tithes, Offerings & Missions',
                'contributions_panel_subtitle' => 'Collect tithes, offerings, and missions support with real-time tracking.',
                'message_field_label' => 'Prayer Request / Note of Blessing (Optional)',
                'message_placeholder' => 'Share a prayer request or word of encouragement...',
                'wall_title' => 'Blessings & Prayer Wall',
                'wall_subtitle' => 'Words of faith, encouragement, and prayer requests from members and visitors',
                'notes_label' => 'Sermon Notes & Scripture Readings',
                'notes_placeholder' => 'Sermon outline, scripture texts, and practical takeaways...',
                'notes_action_label' => 'Sermon Notes',
                'session_types' => [
                    EventSession::TYPE_SERVICE => 'Sunday Service / Worship',
                    EventSession::TYPE_SUNDAY_SCHOOL => 'Sunday School / Children Ministry',
                    EventSession::TYPE_BIBLE_STUDY => 'Bible Study / Exegesis',
                    EventSession::TYPE_PRAYER => 'Prayer Meeting / Vigil',
                    EventSession::TYPE_BREAK => 'Fellowship / Break',
                ],
            ],
            Event::CATEGORY_MEMORIAL => [
                'category' => Event::CATEGORY_MEMORIAL,
                'category_label' => 'Funeral / Memorial & Celebration of Life',
                'contributions_title' => 'Funeral Donations & Support',
                'contributions_subtitle' => 'Support the family, burial expenses, and memorial foundation.',
                'contributions_tab_label' => 'Tributes & Condolences',
                'contributions_cta_label' => 'Contribute / Leave Tribute',
                'contributions_panel_title' => 'Funeral Donations & Tributes',
                'contributions_panel_subtitle' => 'Collect funeral donations, memorial gifts, and condolences with live tribute moderation.',
                'message_field_label' => 'Tribute & Condolence Note (Optional)',
                'message_placeholder' => 'Share a memory, tribute, or word of comfort for the family...',
                'wall_title' => 'Tribute & Condolence Wall',
                'wall_subtitle' => 'Words of remembrance, comfort, and sympathy from family, friends, and well-wishers',
                'notes_label' => 'Order of Service & Memorial Program',
                'notes_placeholder' => 'Order of service outline, tributes program, and hymn references...',
                'notes_action_label' => 'Order of Service',
                'session_types' => [
                    EventSession::TYPE_PRE_BURIAL => 'Pre-Burial / Filing Past',
                    EventSession::TYPE_BURIAL_SERVICE => 'Burial Service',
                    EventSession::TYPE_THANKSGIVING_SERVICE => 'Thanksgiving / Memorial Service',
                    EventSession::TYPE_REPAST => 'Repast / Reception',
                    EventSession::TYPE_BREAK => 'Break / Transition',
                ],
            ],
            Event::CATEGORY_ACADEMIC => [
                'category' => Event::CATEGORY_ACADEMIC,
                'category_label' => 'Academic / Course, Lecture & Seminar',
                'contributions_title' => 'Department & Class Fund',
                'contributions_subtitle' => 'Support students, class projects, faculty awards, and educational materials.',
                'contributions_tab_label' => 'Class Giving',
                'contributions_cta_label' => 'Contribute to Fund',
                'contributions_panel_title' => 'Department & Class Giving',
                'contributions_panel_subtitle' => 'Collect voluntary class gifts, project donations, and alumni support.',
                'message_field_label' => 'Note of Appreciation / Comment (Optional)',
                'message_placeholder' => 'Leave a comment, appreciation, or class note...',
                'wall_title' => 'Student & Alumni Message Board',
                'wall_subtitle' => 'Messages of appreciation, project notes, and community comments',
                'notes_label' => 'Lecture Notes, Readings & Slide Decks',
                'notes_placeholder' => 'Lecture outline, required readings, problem sets, and key takeaways...',
                'notes_action_label' => 'Lecture Notes',
                'session_types' => [
                    EventSession::TYPE_LECTURE => 'Lecture',
                    EventSession::TYPE_LAB => 'Laboratory / Practical',
                    EventSession::TYPE_SEMINAR => 'Seminar',
                    EventSession::TYPE_TUTORIAL => 'Tutorial',
                    EventSession::TYPE_OFFICE_HOURS => 'Office Hours',
                    EventSession::TYPE_WORKSHOP => 'Workshop',
                    EventSession::TYPE_BREAK => 'Recess / Break',
                ],
            ],
            Event::CATEGORY_FUNDRAISER => [
                'category' => Event::CATEGORY_FUNDRAISER,
                'category_label' => 'Nonprofit / Charity & Fundraiser',
                'contributions_title' => 'Donations & Pledges',
                'contributions_subtitle' => 'Support this charitable mission and help us reach our campaign target.',
                'contributions_tab_label' => 'Donate',
                'contributions_cta_label' => 'Donate to Cause',
                'contributions_panel_title' => 'Donations & Campaign Giving',
                'contributions_panel_subtitle' => 'Track campaign donations and moderate donor support messages.',
                'message_field_label' => 'Donor Message / Reason for Giving (Optional)',
                'message_placeholder' => 'Share why this cause matters to you...',
                'wall_title' => 'Donor Wall & Solidarity Messages',
                'wall_subtitle' => 'Words of solidarity and encouragement from donors and supporters',
                'notes_label' => 'Campaign Briefing & Programme',
                'notes_placeholder' => 'Programme outline, impact milestones, and key briefing notes...',
                'notes_action_label' => 'Campaign Notes',
                'session_types' => [
                    EventSession::TYPE_PLENARY => 'Plenary / Keynote',
                    EventSession::TYPE_PANEL => 'Panel Discussion',
                    EventSession::TYPE_SESSION => 'Fundraiser Session',
                    EventSession::TYPE_NETWORKING => 'Reception / Networking',
                    EventSession::TYPE_BREAK => 'Break',
                ],
            ],
            Event::CATEGORY_CONFERENCE => [
                'category' => Event::CATEGORY_CONFERENCE,
                'category_label' => 'Conference / Summit & Corporate Event',
                'contributions_title' => 'Community Sponsorship',
                'contributions_subtitle' => 'Voluntary individual sponsorships to support diversity tickets and community programs.',
                'contributions_tab_label' => 'Support Event',
                'contributions_cta_label' => 'Contribute Support',
                'contributions_panel_title' => 'Community Contributions & Support',
                'contributions_panel_subtitle' => 'Collect voluntary support with live message moderation.',
                'message_field_label' => 'Shout-Out / Community Note (Optional)',
                'message_placeholder' => 'Leave a shout-out or message for the community...',
                'wall_title' => 'Attendee Message Board',
                'wall_subtitle' => 'Messages and shout-outs from attendees and sponsors',
                'notes_label' => 'Session Notes & Presentation Decks',
                'notes_placeholder' => 'Session overview, slides link, and discussion points...',
                'notes_action_label' => 'Session Notes',
                'session_types' => [
                    EventSession::TYPE_KEYNOTE => 'Keynote',
                    EventSession::TYPE_PLENARY => 'Plenary',
                    EventSession::TYPE_PANEL => 'Panel Discussion',
                    EventSession::TYPE_WORKSHOP => 'Workshop',
                    EventSession::TYPE_BREAKOUT => 'Breakout Session',
                    EventSession::TYPE_ORAL_PRESENTATION => 'Presentation',
                    EventSession::TYPE_NETWORKING => 'Networking',
                    EventSession::TYPE_BREAK => 'Coffee Break / Lunch',
                ],
            ],
            default => [
                'category' => Event::CATEGORY_GENERAL,
                'category_label' => 'General / Community & Meetup',
                'contributions_title' => 'Voluntary Contributions',
                'contributions_subtitle' => 'Support this event and community with a voluntary contribution.',
                'contributions_tab_label' => 'Support',
                'contributions_cta_label' => 'Contribute Support',
                'contributions_panel_title' => 'Voluntary Contributions',
                'contributions_panel_subtitle' => 'Collect voluntary contributions with live message moderation.',
                'message_field_label' => 'Message to Organizers (Optional)',
                'message_placeholder' => 'Share a thought or message for the organizers...',
                'wall_title' => 'Community Wall',
                'wall_subtitle' => 'Messages and encouragement from attendees and friends',
                'notes_label' => 'Session Notes & Handouts',
                'notes_placeholder' => 'Session notes, key takeaways, and references...',
                'notes_action_label' => 'Session Notes',
                'session_types' => [
                    EventSession::TYPE_SESSION => 'General Session',
                    EventSession::TYPE_WORKSHOP => 'Workshop',
                    EventSession::TYPE_PANEL => 'Discussion',
                    EventSession::TYPE_NETWORKING => 'Networking',
                    EventSession::TYPE_BREAK => 'Break',
                ],
            ],
        };
    }
}
