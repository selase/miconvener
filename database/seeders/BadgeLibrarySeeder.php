<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BadgeLibraryDesign;
use App\Models\Event;
use App\Services\Badges\BadgeTemplateService;
use Illuminate\Database\Seeder;

final class BadgeLibrarySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Essential' => 'd6d4ad97-24b8-4b30-9dce-a9fed03b1001', 'Spectrum lines' => 'd6d4ad97-24b8-4b30-9dce-a9fed03b1002'] as $name => $id) {
            BadgeLibraryDesign::query()->getConnection()->transaction(function () use ($name, $id): void {
                $design = BadgeLibraryDesign::query()->firstOrCreate(['id' => $id], ['scope' => 'platform', 'name' => $name, 'price_pesewas' => 0]);
                $design = BadgeLibraryDesign::query()->whereKey($design->id)->lockForUpdate()->firstOrFail();
                if ($design->versions()->exists()) {
                    return;
                }
                $compositions = [];
                foreach (['portrait' => [54, 85], 'landscape' => [100, 70]] as $orientation => [$width, $height]) {
                    $snapshot = app(BadgeTemplateService::class)->defaults(new Event);
                    unset($snapshot['tenant_id'], $snapshot['design_version']);
                    $snapshot['width_mm'] = $width;
                    $snapshot['height_mm'] = $height;
                    $snapshot['orientation'] = $orientation;
                    foreach ($snapshot['layout'] as &$field) {
                        $field['visible'] = false;
                    }
                    unset($field);
                    $snapshot['layout']['attendee_name'] = array_replace($snapshot['layout']['attendee_name'], ['visible' => true, 'x' => .08, 'y' => .30, 'width' => .84, 'height' => .32, 'font_family' => 'Lato', 'color' => '#14274B']);
                    $snapshot['layout']['ticket_type'] = array_replace($snapshot['layout']['ticket_type'], ['visible' => true, 'x' => .08, 'y' => .66, 'width' => .84, 'height' => .08, 'font_size' => 8, 'font_weight' => 400, 'color' => '#535B68']);
                    $snapshot['layout']['event_name'] = array_replace($snapshot['layout']['event_name'], ['visible' => true, 'x' => .08, 'y' => .83, 'width' => .84, 'height' => .12, 'font_size' => 7, 'font_weight' => 400]);
                    $snapshot['background_settings'] = ['fit' => 'contain', 'position' => 'center'];
                    $snapshot['logo_source'] = 'organization';
                    if ($name === 'Spectrum lines') {
                        $snapshot['background_disk'] = 'badge-catalog';
                        $snapshot['background_path'] = 'spectrum-'.$orientation.'.png';
                    }
                    $compositions[$orientation] = $snapshot;
                }
                $design->versions()->create(['version' => 1, 'snapshot' => $compositions['portrait'] + ['compositions' => $compositions]]);
            });
        }
    }
}
