<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CommunitySubmission extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunitySubmissionFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'questionnaire_id', 'member_id', 'revision'];

    protected $casts = ['revision' => 'integer'];

    protected $attributes = [];

    /** @return HasMany<CommunityAnswer,$this> */
    public function answers(): HasMany
    {
        return $this->hasMany(CommunityAnswer::class, 'submission_id');
    }

    /** @return BelongsTo<Member,$this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    /** @return BelongsTo<CommunityQuestionnaire,$this> */
    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(CommunityQuestionnaire::class, 'questionnaire_id');
    }
}
