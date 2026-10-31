<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CommunityQuestionnaire extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunityQuestionnaireFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'title', 'description', 'kind', 'audience', 'state', 'opens_at', 'closes_at', 'results_published_at', 'revision', 'created_by'];

    protected $casts = ['audience' => 'array', 'opens_at' => 'immutable_datetime', 'closes_at' => 'immutable_datetime', 'results_published_at' => 'immutable_datetime', 'revision' => 'integer'];

    protected $attributes = ['state' => 'draft', 'kind' => 'poll', 'revision' => 1];

    /** @return HasMany<CommunityQuestion,$this> */
    public function questions(): HasMany
    {
        return $this->hasMany(CommunityQuestion::class, 'questionnaire_id');
    }

    /** @return HasMany<CommunitySubmission,$this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(CommunitySubmission::class, 'questionnaire_id');
    }
}
