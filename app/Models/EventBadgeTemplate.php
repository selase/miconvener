<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Design\ArtifactArtworkCleanup;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EventBadgeTemplate extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id', 'event_id', 'width_mm', 'height_mm', 'orientation',
        'background_disk', 'background_path', 'layout', 'tier_styles',
        'sheet_settings', 'background_settings', 'design_version',
    ];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return array<string, array<string, bool|float|int|string>> */
    public function layoutSettings(): array
    {
        $layout = $this->getAttribute('layout');

        return is_array($layout) ? $layout : [];
    }

    /** @return array<string, array<string, string>> */
    public function tierStyleSettings(): array
    {
        $styles = $this->getAttribute('tier_styles');

        return is_array($styles) ? $styles : [];
    }

    /** @return array{paper: string, margin_mm: float, gap_mm: float, crop_marks: bool} */
    public function sheetSettings(): array
    {
        $settings = $this->getAttribute('sheet_settings');

        return [
            'paper' => is_array($settings) && is_string($settings['paper'] ?? null) ? $settings['paper'] : 'a4',
            'margin_mm' => is_array($settings) && is_numeric($settings['margin_mm'] ?? null) ? (float) $settings['margin_mm'] : 8.0,
            'gap_mm' => is_array($settings) && is_numeric($settings['gap_mm'] ?? null) ? (float) $settings['gap_mm'] : 3.0,
            'crop_marks' => is_array($settings) && is_bool($settings['crop_marks'] ?? null) ? $settings['crop_marks'] : true,
        ];
    }

    /** @return array{fit: string, position: string} */
    public function backgroundSettings(): array
    {
        $settings = $this->getAttribute('background_settings');

        return [
            'fit' => is_array($settings) && in_array($settings['fit'] ?? null, ['stretch', 'contain', 'cover'], true) ? $settings['fit'] : 'stretch',
            'position' => is_array($settings) && in_array($settings['position'] ?? null, ['top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right'], true) ? $settings['position'] : 'center',
        ];
    }

    protected static function booted(): void
    {
        self::deleted(function (self $design): void {
            app(ArtifactArtworkCleanup::class)->afterDeletionCommit($design, app(ArtifactArtworkCleanup::class)->assets($design));
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'width_mm' => 'float',
            'height_mm' => 'float',
            'layout' => 'array',
            'tier_styles' => 'array',
            'sheet_settings' => 'array',
            'background_settings' => 'array',
            'design_version' => 'integer',
        ];
    }
}
