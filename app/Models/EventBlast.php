<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

final class EventBlast extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string STATUS_SCHEDULED = 'scheduled';

    public const string STATUS_SENT = 'sent';

    public const string STATUS_CANCELLED = 'cancelled';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'sent_by',
        'subject',
        'body',
        'audience',
        'audience_label',
        'recipients_count',
        'status',
        'scheduled_at',
        'sent_at',
    ];

    protected $casts = [
        'recipients_count' => 'integer',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    /**
     * Resolve an audience key to a query of matching registrations. Base
     * keys are fixed; `ticket_type:{id}` and `session:{id}` are dynamic,
     * scoped to this event so a key can't reach across tenants or events.
     */
    public static function audienceQuery(Event $event, string $audience): HasMany
    {
        return match (true) {
            $audience === 'all' => $event->registrations()->confirmed(),
            $audience === 'confirmed' => $event->registrations()->where('status', EventRegistration::STATUS_CONFIRMED),
            $audience === 'checked_in' => $event->registrations()->where('status', EventRegistration::STATUS_CHECKED_IN),
            $audience === 'pending_payment' => $event->registrations()->where('status', EventRegistration::STATUS_PENDING_PAYMENT),
            str_starts_with($audience, 'ticket_type:') => $event->registrations()->confirmed()
                ->where('ticket_type_id', mb_substr($audience, mb_strlen('ticket_type:'))),
            str_starts_with($audience, 'session:') => $event->registrations()->confirmed()
                ->whereHas('sessions', fn (Builder $q) => $q->where('event_sessions.id', mb_substr($audience, mb_strlen('session:')))),
            default => throw new InvalidArgumentException("Unknown audience: {$audience}"),
        };
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public static function audienceOptions(Event $event): array
    {
        $options = [
            ['key' => 'all', 'label' => 'All confirmed'],
            ['key' => 'confirmed', 'label' => 'Confirmed, not yet checked in'],
            ['key' => 'checked_in', 'label' => 'Checked in'],
            ['key' => 'pending_payment', 'label' => 'Awaiting payment'],
        ];

        foreach ($event->ticketTypes()->get(['id', 'name']) as $ticketType) {
            $options[] = ['key' => "ticket_type:{$ticketType->id}", 'label' => "Ticket: {$ticketType->name}"];
        }

        foreach ($event->sessions()->get(['id', 'title']) as $session) {
            $options[] = ['key' => "session:{$session->id}", 'label' => "In their day: {$session->title}"];
        }

        return $options;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(EventBlastRecipient::class, 'blast_id');
    }
}
