<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventAbstractAuthor extends Model
{
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'abstract_id',
        'first_name',
        'last_name',
        'email',
        'affiliation',
        'country',
        'is_presenting',
        'is_corresponding',
        'sort_order',
    ];

    public function abstract(): BelongsTo
    {
        return $this->belongsTo(EventAbstract::class, 'abstract_id');
    }

    public function getFullNameAttribute(): string
    {
        return mb_trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_presenting' => 'boolean',
            'is_corresponding' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
