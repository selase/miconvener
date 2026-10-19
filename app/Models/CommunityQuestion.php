<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CommunityQuestion extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunityQuestionFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'questionnaire_id', 'title', 'type', 'position', 'required', 'settings'];

    protected $casts = ['required' => 'boolean', 'settings' => 'array', 'position' => 'integer'];

    protected $attributes = ['required' => true, 'settings' => '{}'];

    /** @return HasMany<CommunityQuestionOption,$this> */
    public function options(): HasMany
    {
        return $this->hasMany(CommunityQuestionOption::class, 'question_id');
    }

    /** @return BelongsTo<CommunityQuestionnaire,$this> */
    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(CommunityQuestionnaire::class, 'questionnaire_id');
    }
}
