<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Models\Package;
use Database\Seeders\EventPackageSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * What the public pages promise has to be something the product does. A buyer
 * who asks for the SOC 2 report the page mentioned, or scans a ticket offline
 * because the page said they could, finds out the hard way -- and so does the
 * business. These tests hold the copy to the code.
 */
test('the enterprise page makes no certification, uptime or compliance claim', function (): void {
    $page = $this->get('/product-enterprise')->assertOk();

    // None of these is held. Add one back only with the certificate, the SLA
    // contract or the assessment that stands behind it.
    foreach (['SOC 2', 'Type II', 'Uptime SLA', '99.9%', 'GDPR', 'Fully compliant', '24 / 7', 'SaaS', 'billions'] as $claim) {
        $page->assertDontSee($claim, false);
    }

    $page->assertSee('Paystack', false);
});

test('the homepage does not promise offline scanning, which the scanner does not do', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertDontSee('without a network', false)
        ->assertDontSee('Queued scans', false);
});

test('the homepage does not promise proration, quiz leaderboards or vendor milestone payments', function (): void {
    // An upgrade charges the new plan's full price, a quiz shows the answer
    // but ranks nobody, and a vendor quote takes one deposit.
    $this->get('/')
        ->assertOk()
        ->assertDontSee('prorated', false)
        ->assertDontSee('leaderboard', false)
        ->assertDontSee('milestone', false);
});

test('the staff link FAQ quotes the allowances the plans grant', function (): void {
    Artisan::call('db:seed', ['--class' => EventPackageSeeder::class]);

    $answer = collect(config('product-page.faqs'))->firstWhere('q', 'Do my ushers need accounts?')['a'];

    foreach (['free' => 'Free', 'starter' => 'Starter', 'growth' => 'Growth'] as $slug => $name) {
        $granted = Package::query()->where('slug', $slug)->firstOrFail()
            ->features()->where('slug', 'staff_links')->firstOrFail()->pivot->value;

        expect($answer)->toContain("{$granted} on {$name}");
    }

    $this->get('/')->assertSee('Your crew scans', false)->assertSee('someone’s phone buzzes', false);
});

test('no plan advertises single sign-on, which is not built', function (): void {
    $this->get('/')->assertDontSee('Single sign-on', false);
});

test('every internal link in the homepage navigation, calls to action and footer resolves', function (): void {
    $config = config('product-page');

    $links = [
        ...array_column($config['nav']['links'], 'href'),
        $config['nav']['cta_secondary']['href'],
        $config['nav']['cta_primary']['href'],
        $config['hero']['cta_primary']['href'],
        $config['hero']['cta_secondary']['href'],
        $config['final_cta']['cta_primary']['href'],
        $config['final_cta']['cta_secondary']['href'],
        ...array_merge(...array_map(fn (array $column): array => array_column($column, 'href'), array_values($config['footer']['columns']))),
    ];

    foreach (array_unique($links) as $href) {
        // A bare '#' goes nowhere; it was the About link's target.
        expect($href)->not->toBe('#');

        $path = strtok($href, '#') ?: '/';

        // "Contact Sales" pointed at /contact, which never existed.
        expect($this->get($path)->status())->toBeLessThan(400, "{$href} does not resolve");
    }
});

test('the Terms and Privacy pages exist, since every form says people agree to them', function (): void {
    $this->get('/terms')->assertOk()->assertSee('Terms of Service')->assertSee('Paystack');
    $this->get('/privacy')->assertOk()->assertSee('Privacy Policy')->assertSee('Act 843')->assertSee('marketplace');

    // What established platforms make explicit: who sells the ticket, who owes
    // the tax, what a cancellation refunds, and the marketplace's own rules.
    $this->get('/terms')
        ->assertSee('is the seller of its tickets', false)
        ->assertSee('not refunded to the organizer', false)
        ->assertSee('Marketplace', false);

    // The enterprise form's consent line once pointed at '#'.
    $this->get('/product-enterprise')->assertSee(route('privacy'), false);
});

test('no plan advertises SMS or API access, neither of which is delivered yet', function (): void {
    $features = collect(config('product-page.plans'))->pluck('features')->flatten();

    expect($features->filter(fn (string $line): bool => str_contains($line, 'SMS') || str_contains($line, 'API')))->toBeEmpty();
});

test('the template-era address sends people to the real homepage', function (): void {
    $this->get('/product-template')->assertStatus(301)->assertRedirect('/');
});

test('the commission cap each plan advertises is the cap billing applies', function (): void {
    Artisan::call('db:seed', ['--class' => EventPackageSeeder::class]);

    foreach (config('product-page.plans') as $plan) {
        $package = Package::query()->where('slug', $plan['slug'])->first();
        $advertised = collect($plan['features'])->first(fn (string $line): bool => str_contains($line, 'cap)'));

        if ($package === null || $advertised === null) {
            continue;
        }

        preg_match('/GHS (\d+) cap/', $advertised, $match);

        // The page and the seeded package must agree, or buyers are promised one cap and charged another.
        expect((int) $match[1] * 100)->toBe((int) $package->default_platform_fee_cap_amount, "{$plan['slug']} cap");
    }
});

test('the staff links each plan advertises are the staff links it grants', function (): void {
    Artisan::call('db:seed', ['--class' => EventPackageSeeder::class]);

    foreach (config('product-page.plans') as $plan) {
        $advertised = collect($plan['features'])->first(fn (string $line): bool => (bool) preg_match('/^(\d+) staff links?\b/', $line));

        if ($advertised === null) {
            continue;
        }

        preg_match('/^(\d+) staff link/', $advertised, $match);
        $granted = Package::query()->where('slug', $plan['slug'])->firstOrFail()
            ->features()->where('slug', 'staff_links')->firstOrFail()->pivot->value;

        expect((int) $match[1])->toBe((int) $granted, "{$plan['slug']} staff links");
    }
});
