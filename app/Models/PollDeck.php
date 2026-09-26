<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\TenantScope;
use App\Traits\BelongsToTenant;
use Database\Factories\PollDeckFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

final class PollDeck extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PollDeckFactory> */
    use HasFactory;

    use HasUuids;

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_LIVE = 'live';

    public const string STATUS_ENDED = 'ended';

    /**
     * Read aloud across a room and typed on a phone, so the alphabet leaves out
     * the pairs people confuse: O and zero, I and one.
     */
    private const string CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'session_id',
        'title',
        'join_code',
        'status',
        'current_poll_id',
        'present_token',
    ];

    /**
     * Never serialise the presenter's secret URL token.
     *
     * @var list<string>
     */
    protected $hidden = [
        'present_token',
    ];

    /**
     * Human-readable, collision-checked join code offered to attendees.
     *
     * The uniqueness check deliberately bypasses TenantScope: the join_code
     * column is unique across every tenant, not per tenant, so the check has
     * to see every deck or a cross-tenant collision would pass here and fail
     * at insert with a database-level error instead.
     */
    public static function generateJoinCode(): string
    {
        $alphabet = self::CODE_ALPHABET;

        do {
            $code = '';

            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, mb_strlen($alphabet) - 1)];
            }
        } while (self::withoutGlobalScope(TenantScope::class)->where('join_code', $code)->exists());

        return $code;
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return BelongsTo<EventSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(EventSession::class);
    }

    /**
     * Ordered by position, with created_at as a tiebreaker so a repeated
     * read never reorders two polls that happen to share a position.
     *
     * @return HasMany<EventPoll, $this>
     */
    public function polls(): HasMany
    {
        return $this->hasMany(EventPoll::class, 'deck_id')
            ->orderBy('position')
            ->orderBy('created_at');
    }

    /**
     * Deliberately unconstrained. A `where('deck_id', $this->getKey())` here
     * looks like a safety net but breaks eager loading: Laravel builds the
     * constraint once, from one instance, and then applies that single deck's
     * id to every row, so `with('currentPoll')` over two decks resolves all
     * but one to null. The pointer is guarded on the write path instead, by
     * setCurrentPoll(), which is where a guard can actually hold.
     *
     * @return BelongsTo<EventPoll, $this>
     */
    public function currentPoll(): BelongsTo
    {
        return $this->belongsTo(EventPoll::class, 'current_poll_id');
    }

    /**
     * The one supported way to move the pointer. A deck may only ever point at
     * a question it owns: pointing at another deck's -- or another tenant's --
     * would put a stranger's question on this deck's public wall.
     *
     * @throws InvalidArgumentException
     */
    public function setCurrentPoll(?EventPoll $poll): void
    {
        if ($poll !== null && $poll->deck_id !== $this->getKey()) {
            throw new InvalidArgumentException('A deck can only point at a question it owns.');
        }

        $this->update(['current_poll_id' => $poll?->id]);
    }
}
