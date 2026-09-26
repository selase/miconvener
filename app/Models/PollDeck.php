<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\PollDeckFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Human-readable, collision-checked join code offered to attendees.
     */
    public static function generateJoinCode(): string
    {
        $alphabet = self::CODE_ALPHABET;

        do {
            $code = '';

            for ($i = 0; $i < 6; $i++) {
                $code .= $alphabet[random_int(0, mb_strlen($alphabet) - 1)];
            }
        } while (self::where('join_code', $code)->exists());

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
     * @return HasMany<EventPoll, $this>
     */
    public function polls(): HasMany
    {
        return $this->hasMany(EventPoll::class, 'deck_id')->orderBy('position');
    }

    /**
     * @return BelongsTo<EventPoll, $this>
     */
    public function currentPoll(): BelongsTo
    {
        return $this->belongsTo(EventPoll::class, 'current_poll_id');
    }
}
