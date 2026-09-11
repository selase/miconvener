<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class EventDynamicForm extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string TYPE_GENERAL = 'general';

    public const string TYPE_REGISTRATION = 'registration';

    public const string TYPE_SURVEY = 'survey';

    public const string TYPE_FEEDBACK = 'feedback';

    public const string TYPE_CME_EVALUATION = 'cme_evaluation';

    public const string TYPE_WORKSHOP_SIGNUP = 'workshop_signup';

    public const string TYPE_ABSTRACT_DISCLOSURE = 'abstract_disclosure';

    public const array TYPES = [
        self::TYPE_GENERAL,
        self::TYPE_REGISTRATION,
        self::TYPE_SURVEY,
        self::TYPE_FEEDBACK,
        self::TYPE_CME_EVALUATION,
        self::TYPE_WORKSHOP_SIGNUP,
        self::TYPE_ABSTRACT_DISCLOSURE,
    ];

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'title',
        'slug',
        'description',
        'type',
        'is_active',
        'is_public',
        'requires_check_in',
        'schema',
        'starts_at',
        'ends_at',
        'submission_limit',
    ];

    public function isOpen(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();
        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        if ($this->submission_limit && $this->submissions()->count() >= $this->submission_limit) {
            return false;
        }

        return true;
    }

    public function publicUrl(): string
    {
        return url("/e/{$this->event->slug}/forms/{$this->slug}");
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(EventDynamicFormSubmission::class, 'form_id')->latest('submitted_at');
    }

    protected static function booted(): void
    {
        self::creating(function (self $form): void {
            if (empty($form->slug)) {
                $form->slug = Str::slug($form->title);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'requires_check_in' => 'boolean',
            'schema' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'submission_limit' => 'integer',
        ];
    }
}
