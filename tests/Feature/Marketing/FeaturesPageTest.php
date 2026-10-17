<?php

declare(strict_types=1);

test('the full features page lists every capability with its description', function (): void {
    $response = $this->get(route('features'))->assertOk()->assertSee('Full features');

    foreach (config('product-page.capabilities.items') as $item) {
        $response->assertSee($item['title'])->assertSee($item['body']);
    }
});

test('the footer links to the full features page', function (): void {
    $this->get('/')->assertOk()->assertSee('href="'.route('features').'"', false)->assertSee('Full features');
});

test('the homepage shows the feature map with its heading, areas and key features, not every description', function (): void {
    $response = $this->get('/')->assertOk()
        ->assertSee('data-feature-map', false)
        ->assertSee(config('product-page.capabilities.title'))
        ->assertSee(config('product-page.capabilities.subtitle'));

    foreach (config('product-page.feature_map.areas') as $area) {
        $response->assertSee($area['group']);
        foreach ($area['features'] as $feature) {
            $response->assertSee($feature);
        }
    }

    $response->assertDontSee('Receive abstracts, assign reviewers, record decisions and export an abstract book.');
});

test('each map area is a capability group with three key features and an icon', function (): void {
    $groups = collect(config('product-page.capabilities.items'))->pluck('group')->unique()->values();
    $areas = collect(config('product-page.feature_map.areas'));

    expect($areas)->toHaveCount(6)
        ->and($areas->pluck('group')->sort()->values()->all())->toBe($groups->sort()->values()->all());

    foreach ($areas as $area) {
        expect($area['features'])->toHaveCount(3)
            ->and(view()->exists('product.partials.feature-map-icons.'.$area['icon']))->toBeTrue();
    }
});
