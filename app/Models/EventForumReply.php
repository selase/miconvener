<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventForumReply extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const array TAGS = ['official_answer', 'important', 'action_item', 'faq'];

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'thread_id',
        'host_user_id',
        'author_name',
        'body',
        'tag',
        'attachment_path',
        'attachment_name',
        'attachment_size',
    ];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(EventForumThread::class, 'thread_id');
    }

    public function hostUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function isFromHost(): bool
    {
        return $this->host_user_id !== null;
    }

    public function attachmentUrl(): ?string
    {
        return $this->attachment_path ? asset('storage/'.$this->attachment_path) : null;
    }
}
