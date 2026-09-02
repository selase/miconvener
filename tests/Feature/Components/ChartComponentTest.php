<?php

declare(strict_types=1);

namespace Tests\Feature\Components;

use Tests\TestCase;

final class ChartComponentTest extends TestCase
{
    public function test_it_renders_the_chart_component_html(): void
    {
        $view = $this->blade(
            '<x-chart id="test-chart" type="bar" :data="[\'labels\' => [\'A\'], \'datasets\' => []]" />'
        );

        $view->assertSee('<canvas id="test-chart"', false);
        $view->assertSee('x-data="{', false);
        $view->assertSee("type: 'bar'", false);
    }
}
