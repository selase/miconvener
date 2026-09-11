<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventFormField extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public const string TYPE_TEXT = 'text';

    public const string TYPE_TEXTAREA = 'textarea';

    public const string TYPE_SELECT = 'select';

    public const string TYPE_RADIO = 'radio';

    public const string TYPE_CHECKBOX = 'checkbox';

    public const string TYPE_NUMBER = 'number';

    public const array TYPES = [
        self::TYPE_TEXT,
        self::TYPE_TEXTAREA,
        self::TYPE_SELECT,
        self::TYPE_RADIO,
        self::TYPE_CHECKBOX,
        self::TYPE_NUMBER,
    ];

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'label',
        'field_key',
        'field_type',
        'help_text',
        'is_required',
        'options',
        'conditional_logic',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'options' => 'array',
        'conditional_logic' => 'array',
        'sort_order' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('created_at');
    }

    /**
     * Determine if this field should be active given a map of answers.
     *
     * @param  array<string, mixed>  $answers
     */
    public function isConditionMet(array $answers): bool
    {
        if (empty($this->conditional_logic)) {
            return true;
        }

        $parentKey = $this->conditional_logic['depends_on_field'] ?? $this->conditional_logic['depends_on'] ?? null;
        if (empty($parentKey)) {
            return true;
        }

        $expectedValue = $this->conditional_logic['value'] ?? null;
        $operator = $this->conditional_logic['operator'] ?? 'equals';

        $actualValue = $answers[$parentKey] ?? null;

        return match ($operator) {
            'not_equals' => (string) $actualValue !== (string) $expectedValue,
            'is_empty' => empty($actualValue),
            'is_not_empty' => ! empty($actualValue),
            default => (string) $actualValue === (string) $expectedValue,
        };
    }
}
