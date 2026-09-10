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

final class EventForumThread extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'session_id',
        'title',
        'body',
        'author_name',
        'author_email',
        'is_anonymous',
        'attachment_path',
        'attachment_name',
        'attachment_size',
        'is_pinned',
        'is_hidden',
        'is_answered',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'is_pinned' => 'boolean',
        'is_hidden' => 'boolean',
        'is_answered' => 'boolean',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(EventForumReply::class, 'thread_id')->orderBy('created_at');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(EventForumVote::class, 'thread_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(EventForumReport::class, 'thread_id');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    public function attachmentUrl(): ?string
    {
        return \App\Libraries\Helper::storageUrl($this->attachment_path);
    }
}
