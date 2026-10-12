<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TenantFollowUp extends Model
{
    /** @use HasFactory<\Database\Factories\TenantFollowUpFactory> */
    use HasFactory;

    use HasUuids;

    public const string STATUS_CONTACTED = 'contacted';

    public const string STATUS_INTERESTED = 'interested';

    public const string STATUS_NEEDS_HELP = 'needs_help';

    public const string STATUS_NO_RESPONSE = 'no_response';

    public const string STATUS_NOT_INTERESTED = 'not_interested';

    /**
     * @var array<string, string> status => label
     */
    public const array STATUSES = [
        self::STATUS_CONTACTED => 'Contacted',
        self::STATUS_INTERESTED => 'Interested',
        self::STATUS_NEEDS_HELP => 'Needs help',
        self::STATUS_NO_RESPONSE => 'No response',
        self::STATUS_NOT_INTERESTED => 'Not interested',
    ];

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'status',
        'note',
    ];

    public function label(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
