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

    public const string TYPE_EMAIL = 'email';

    /** A section heading: shown on the form, never answered. */
    public const string TYPE_HEADING = 'heading';

    public const array TYPES = [
        self::TYPE_TEXT,
        self::TYPE_TEXTAREA,
        self::TYPE_SELECT,
        self::TYPE_RADIO,
        self::TYPE_CHECKBOX,
        self::TYPE_NUMBER,
        self::TYPE_EMAIL,
        self::TYPE_HEADING,
    ];

    /** Types whose answer is free text and therefore has a length. */
    public const array TEXT_TYPES = [
        self::TYPE_TEXT,
        self::TYPE_TEXTAREA,
        self::TYPE_EMAIL,
    ];

    /** Types whose answer must be one of the field's options. */
    public const array CHOICE_TYPES = [
        self::TYPE_SELECT,
        self::TYPE_RADIO,
    ];

    /** Longest answer accepted when the organiser sets no maximum. */
    public const array DEFAULT_MAX_LENGTHS = [
        self::TYPE_TEXT => 255,
        self::TYPE_TEXTAREA => 5000,
        self::TYPE_EMAIL => 255,
    ];

    /** Highest character limit an organiser may set. */
    public const int MAX_LENGTH_CEILING = 5000;

    public const string UNIT_CHARACTERS = 'characters';

    public const string UNIT_WORDS = 'words';

    public const array LENGTH_UNITS = [self::UNIT_CHARACTERS, self::UNIT_WORDS];

    /**
     * Highest word limit an organiser may set: comfortably inside the
     * long-answer character cap, which still applies to word-counted answers.
     */
    public const int MAX_WORDS_CEILING = 800;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'event_id',
        'label',
        'field_key',
        'field_type',
        'help_text',
        'is_required',
        'min_length',
        'max_length',
        'length_unit',
        'options',
        'conditional_logic',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'min_length' => 'integer',
        'max_length' => 'integer',
        'options' => 'array',
        'conditional_logic' => 'array',
        'sort_order' => 'integer',
    ];

    /**
     * Whether an answer counts as unanswered. Unlike empty(), "0" is an
     * answer: a "No" choice is often stored as 0.
     */
    public static function isBlankAnswer(mixed $value): bool
    {
        return $value === null || $value === '' || $value === false || $value === [];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function isHeading(): bool
    {
        return $this->field_type === self::TYPE_HEADING;
    }

    public function isTextType(): bool
    {
        return in_array($this->field_type, self::TEXT_TYPES, true);
    }

    public function countsWords(): bool
    {
        return $this->field_type === self::TYPE_TEXTAREA && $this->length_unit === self::UNIT_WORDS;
    }

    /**
     * The hard character cap on an answer, so free text is never unbounded.
     * A character-counted maximum replaces the type's default; a word-counted
     * field keeps the default as a safety net.
     */
    public function characterLimit(): ?int
    {
        if (! $this->isTextType()) {
            return null;
        }

        if (! $this->countsWords() && $this->max_length !== null) {
            return $this->max_length;
        }

        return self::DEFAULT_MAX_LENGTHS[$this->field_type];
    }

    /**
     * An answer's length in this field's unit: words or characters.
     */
    public function measure(string $answer): int
    {
        return $this->countsWords()
            ? preg_match_all('/\S+/u', $answer)
            : mb_strlen($answer);
    }

    /**
     * The option label for a stored value, or the value itself when the
     * field has no matching option.
     */
    public function optionLabelFor(string $value): string
    {
        foreach ($this->options ?? [] as $option) {
            if ((string) ($option['value'] ?? '') === $value) {
                return (string) ($option['label'] ?? $value);
            }
        }

        return $value;
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
            'is_empty' => self::isBlankAnswer($actualValue),
            'is_not_empty' => ! self::isBlankAnswer($actualValue),
            default => (string) $actualValue === (string) $expectedValue,
        };
    }
}
