<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CommunityAnswer extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunityAnswerFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'questionnaire_id', 'submission_id', 'question_id', 'option_id', 'response_text', 'response_number', 'response_payload'];

    protected $casts = ['response_number' => 'float', 'response_payload' => 'array'];

    protected $attributes = [];

    /** @return BelongsTo<CommunityQuestion,$this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(CommunityQuestion::class, 'question_id');
    }
}
