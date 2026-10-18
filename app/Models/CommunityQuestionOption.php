<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CommunityQuestionOption extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunityQuestionOptionFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'questionnaire_id', 'question_id', 'label', 'position'];

    protected $casts = ['position' => 'integer'];

    protected $attributes = [];

    /** @return BelongsTo<CommunityQuestion,$this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(CommunityQuestion::class, 'question_id');
    }
}
