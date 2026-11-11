<?php

declare(strict_types=1);

namespace App\Services\Badges;

use App\Models\BadgeDesignRevision;
use App\Models\BadgeLibraryDesign;
use App\Models\BadgeLibraryVersion;
use App\Models\Event;
use App\Models\EventBadgeTemplate;
use App\Services\Design\ArtifactArtworkService;
use App\Services\Design\ArtifactLayoutValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class BadgeLibraryService
{
    public const array FIELDS = ['width_mm', 'height_mm', 'orientation', 'layout', 'tier_styles', 'sheet_settings', 'background_settings', 'background_disk', 'background_path', 'logo_source', 'logo_disk', 'logo_path', 'back', 'source_version_id'];

    public function __construct(private readonly BadgeTemplateService $templates, private readonly ArtifactArtworkService $artwork, private readonly ArtifactLayoutValidator $layouts) {}

    /** @return Builder<BadgeLibraryDesign> */
    public function visible(Event $event): Builder
    {
        return BadgeLibraryDesign::query()->whereNull('withdrawn_at')->where(function (Builder $query) use ($event): void {
            $query->where(fn (Builder $global) => $global->where('scope', 'platform')->whereNull('tenant_id'))
                ->orWhere(fn (Builder $own) => $own->where('tenant_id', $event->tenant_id)->where(fn (Builder $scope) => $scope->where('scope', 'organisation')->orWhere(fn (Builder $private) => $private->where('scope', 'event')->where('event_id', $event->id))));
        });
    }

    public function visibleVersion(Event $event, string $id): BadgeLibraryVersion
    {
        return BadgeLibraryVersion::query()->whereKey($id)->whereIn('design_id', $this->visible($event)->select('id'))->with('design')->firstOrFail();
    }

    /** @param array<string, mixed> $input */
    public function save(Event $event, array $input): BadgeLibraryVersion
    {
        $template = $this->templates->forEvent($event);
        $files = [];
        try {
            return $template->getConnection()->transaction(function () use ($template, $event, $input, &$files): BadgeLibraryVersion {
                $current = $template->newQuery()->whereKey($template->id)->lockForUpdate()->firstOrFail();
                $this->assertVersion($current, (int) $input['expected_version']);
                $design = isset($input['design_id']) ? $this->visible($event)->whereKey($input['design_id'])->lockForUpdate()->firstOrFail() : null;
                if ($design !== null && ($design->tenant_id !== ($input['scope'] === 'platform' ? null : $event->tenant_id) || $design->scope !== $input['scope'])) {
                    throw ValidationException::withMessages(['design_id' => 'Save a new design to change its sharing scope.']);
                }
                $snapshot = $this->snapshot($event, $current);
                if ($input['scope'] === 'platform') {
                    foreach ([$snapshot['layout'], $snapshot['back']['layout'] ?? []] as $layout) {
                        foreach ($layout as $field) {
                            if (str_starts_with($field['font_family'] ?? '', 'artifact-')) {
                                throw ValidationException::withMessages(['scope' => 'Choose bundled fonts on both faces before publishing a design for other organisations.']);
                            }
                        }
                    }
                }
                $snapshot = $this->copyAssets($snapshot, (string) $event->tenant_id, $files);
                $design ??= BadgeLibraryDesign::query()->create([
                    'tenant_id' => $input['scope'] === 'platform' ? null : $event->tenant_id,
                    'event_id' => $input['scope'] === 'event' ? $event->id : null,
                    'scope' => $input['scope'], 'name' => mb_trim($input['name']),
                    'price_pesewas' => $input['scope'] === 'platform' ? ($input['price_pesewas'] ?? 0) : 0,
                ]);
                $number = (int) $design->versions()->max('version') + 1;

                return $design->versions()->create(['version' => $number, 'snapshot' => $snapshot]);
            });
        } catch (Throwable $exception) {
            $this->removeFiles($files);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $input */
    public function apply(Event $event, array $input): EventBadgeTemplate
    {
        $revision = isset($input['revision_id']) ? BadgeDesignRevision::query()->where('tenant_id', $event->tenant_id)->where('event_id', $event->id)->findOrFail($input['revision_id']) : null;
        $version = $revision === null ? $this->visibleVersion($event, $input['version_id']) : null;
        if ($version !== null && $version->design->price_pesewas > 0) {
            throw ValidationException::withMessages(['version_id' => 'This GH₵50 design is preview-only until template purchasing is available.']);
        }
        $snapshot = $revision !== null ? $revision->snapshotSettings() : $version->snapshotSettings();
        if ($revision === null && isset($input['composition'], $snapshot['compositions'][$input['composition']])) {
            $snapshot = $snapshot['compositions'][$input['composition']];
        }
        unset($snapshot['compositions']);
        if ($version !== null) {
            $snapshot['source_version_id'] = $version->id;
        }
        $template = $this->templates->forEvent($event);
        $files = [];
        try {
            return $template->getConnection()->transaction(function () use ($event, $template, $snapshot, $revision, $input, &$files): EventBadgeTemplate {
                $current = $template->newQuery()->whereKey($template->id)->lockForUpdate()->firstOrFail();
                $this->assertVersion($current, (int) $input['expected_version']);
                $cleanup = app(\App\Services\Design\ArtifactArtworkCleanup::class);
                $oldAssets = $cleanup->assets($current);
                $previous = $this->snapshot($event, $current);
                $previous['sheet_settings']['category_labels'] = $current->sheetSettings()['category_labels'];
                $previous = $this->copyAssets($previous, (string) $event->tenant_id, $files);
                $next = array_replace($this->templates->defaults($event), [
                    'background_disk' => null, 'background_path' => null,
                    'logo_disk' => null, 'logo_path' => null, 'logo_source' => 'organization',
                    'back' => null, 'source_version_id' => null,
                ], $snapshot);
                $next['logo_source'] ??= 'organization';
                $next = $this->copyAssets($next, (string) $event->tenant_id, $files);
                if ((isset($input['width_mm']) && (float) $input['width_mm'] !== (float) $next['width_mm']) || (isset($input['height_mm']) && (float) $input['height_mm'] !== (float) $next['height_mm'])) {
                    $next['width_mm'] = $input['width_mm'] ?? $next['width_mm'];
                    $next['height_mm'] = $input['height_mm'] ?? $next['height_mm'];
                    $next['background_settings'] = array_replace($next['background_settings'] ?? [], ['fit' => 'contain']);
                }
                $next['orientation'] = $next['width_mm'] > $next['height_mm'] ? 'landscape' : 'portrait';
                $next['layout'] = $this->layouts->validate($next['layout'], 'badge');
                if (is_array($next['back'])) {
                    $next['back']['layout'] = $this->layouts->validate($next['back']['layout'], 'badge');
                }
                if ($revision === null) {
                    $next['sheet_settings']['category_labels'] = $this->categoryLabels($event, $input['category_labels'] ?? []);
                }
                $this->assertPaperFit($next);
                BadgeDesignRevision::query()->create(['tenant_id' => $event->tenant_id, 'event_id' => $event->id, 'design_version' => $current->design_version, 'snapshot' => $previous]);
                unset($next['categories']);
                $current->fill(array_intersect_key($next, array_flip(self::FIELDS)));
                $current->design_version++;
                $current->save();
                $current->getConnection()->afterCommit(function () use ($cleanup, $oldAssets): void {
                    try {
                        $cleanup->deleteUnreferenced($oldAssets);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                });

                return $current;
            });
        } catch (Throwable $exception) {
            $this->removeFiles($files);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function snapshot(Event $event, EventBadgeTemplate $template): array
    {
        $snapshot = $template->only(self::FIELDS);
        $logoSource = $template->getAttribute('logo_source');
        $snapshot['logo_source'] = is_string($logoSource) && $logoSource !== '' ? $logoSource : 'organization';
        $labels = $template->sheetSettings()['category_labels'];
        $snapshot['categories'] = $event->ticketTypes()->get(['id', 'name'])->map(fn ($ticket): array => ['ticket_name' => $ticket->name, 'label' => $labels[$ticket->id] ?? $ticket->name])->all();
        $snapshot['sheet_settings'] = $template->sheetSettings();
        $snapshot['sheet_settings']['category_labels'] = [];
        if ($template->logo_source !== 'none' && $template->logo_source !== 'custom' && is_string($event->tenant?->logo)) {
            $snapshot['logo_source'] = 'custom';
            $snapshot['logo_disk'] = config('app.env') === 'production' ? 's3' : 'public';
            $snapshot['logo_path'] = $event->tenant->logo;
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    public function previewImage(array $snapshot, bool $watermarked = true): string
    {
        $width = 600;
        $height = (int) round($width * $snapshot['height_mm'] / $snapshot['width_mm']);
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        if (is_string($snapshot['background_path'] ?? null)) {
            $source = imagecreatefromstring($this->artwork->contents($snapshot['background_disk'], $snapshot['background_path']));
            if ($source !== false) {
                $scale = min($width / imagesx($source), $height / imagesy($source));
                $w = (int) round(imagesx($source) * $scale);
                $h = (int) round(imagesy($source) * $scale);
                imagecopyresampled($image, $source, (int) (($width - $w) / 2), (int) (($height - $h) / 2), 0, 0, $w, $h, imagesx($source), imagesy($source));
                unset($source);
            }
        }
        $ink = imagecolorallocate($image, 20, 39, 75);
        foreach ($snapshot['layout'] as $key => $item) {
            if (($item['visible'] ?? true) === false || ($item['removed'] ?? false) === true) {
                continue;
            }
            $text = match ($key) {
                'attendee_name' => 'Sample Attendee', 'event_name' => 'Your conference', 'ticket_type' => 'Category', default => '',
            };
            if ($text !== '') {
                $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
                $size = min(40, max(12, $item['font_size'] * $width / ($snapshot['width_mm'] * 72 / 25.4)));
                imagettftext($image, $size, 0, (int) ($item['x'] * $width), (int) ($item['y'] * $height + $size), $ink, $font, $text);
            }
        }
        $watermark = imagecolorallocatealpha($image, 100, 40, 80, 45);
        for ($y = 80; $watermarked && $y < $height + 150; $y += 150) {
            imagettftext($image, 36, 25, 15, $y, $watermark, base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf'), 'SAMPLE - GH50');
        }
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        unset($image);

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    /**
     * @param  array<string, mixed>  $labels
     * @return array<string, string>
     */
    private function categoryLabels(Event $event, array $labels): array
    {
        if (array_diff(array_keys($labels), $event->ticketTypes()->pluck('id')->all()) !== []) {
            throw ValidationException::withMessages(['category_labels' => 'Choose ticket types belonging to this event.']);
        }

        return array_filter(array_map(fn ($value): string => mb_trim((string) $value), $labels), fn ($value): bool => $value !== '');
    }

    private function assertVersion(EventBadgeTemplate $template, int $expected): void
    {
        if ($template->design_version !== $expected) {
            throw ValidationException::withMessages(['expected_version' => 'The event design has changed. Reload before replacing it.']);
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function assertPaperFit(array $snapshot): void
    {
        $settings = $snapshot['sheet_settings'];
        $paper = $settings['paper'] === 'letter' ? [215.9, 279.4] : [210, 297];
        if ($snapshot['width_mm'] > $paper[0] - 2 * $settings['margin_mm'] || $snapshot['height_mm'] > $paper[1] - 2 * $settings['margin_mm']) {
            throw ValidationException::withMessages(['width_mm' => 'These dimensions do not fit the selected print paper.']);
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<array{string, string}>  $files
     * @return array<string, mixed>
     */
    private function copyAssets(array $snapshot, string $tenantId, array &$files): array
    {
        foreach (['background', 'logo'] as $kind) {
            if (! is_string($snapshot[$kind.'_disk'] ?? null) || ! is_string($snapshot[$kind.'_path'] ?? null)) {
                continue;
            }
            $uri = $this->artwork->dataUri($snapshot[$kind.'_disk'], $snapshot[$kind.'_path']);
            $disk = config('app.env') === 'production' ? 's3' : 'local';
            $path = 'tenants/'.$tenantId.'/badge-library/'.Str::uuid().(str_starts_with($uri, 'data:image/png;') ? '.png' : '.jpg');
            $files[] = [$disk, $path];
            if (! Storage::disk($disk)->put($path, base64_decode(explode(',', $uri, 2)[1], true))) {
                throw new RuntimeException('Design artwork could not be copied.');
            }
            $snapshot[$kind.'_disk'] = $disk;
            $snapshot[$kind.'_path'] = $path;
        }
        if (is_array($snapshot['back'] ?? null)) {
            $snapshot['back'] = $this->copyAssets($snapshot['back'], $tenantId, $files);
        }

        return $snapshot;
    }

    /** @param list<array{string, string}> $files */
    private function removeFiles(array $files): void
    {
        foreach ($files as [$disk, $path]) {
            $this->artwork->delete($disk, $path);
        }
    }
}
