<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Models\Event;
use App\Models\EventPoll;
use App\Models\Package;
use App\Services\Events\EventLexicon;
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

test('the homepage promises offline scanning, which staff links do', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertSee('without signal', false)
        ->assertSee('Door scanning that works offline', false);

    // The page's promises are the ones StaffLinkOfflineTest holds the code to.
    expect(file_exists(base_path('resources/js/lib/staffDoor/store.js')))->toBeTrue()
        ->and(file_exists(base_path('tests/Feature/Events/StaffLinkOfflineTest.php')))->toBeTrue();
});

test('the public pages allow marketplace browsing without promising venue bookings', function (): void {
    foreach (['/', '/deck'] as $path) {
        $this->get($path)
            ->assertOk()
            ->assertDontSee('hold the date', false)
            ->assertDontSee('Venue marketplace', false)
            ->assertDontSee('Vendor quotes', false);
    }

    $links = collect(config('product-page.nav.links'));
    expect($links->firstWhere('label', 'Marketplace')['target'])->toBe('_blank');
});

test('the homepage names no customer', function (): void {
    $this->get('/')->assertOk()->assertDontSee('UGMC', false)->assertDontSee('Nkabom', false);
    $this->get('/deck')->assertOk()->assertDontSee('UGMC', false)->assertDontSee('Nkabom', false);
});

test('the homepage poll claim matches the question types the code offers', function (): void {
    $count = count(EventPoll::TYPES);
    $words = [8 => 'eight', 9 => 'nine', 10 => 'ten', 11 => 'eleven', 12 => 'twelve'];

    $polling = collect(config('product-page.capabilities.items'))->firstWhere('title', 'Live polling & Q&A');

    expect($polling['body'])->toContain($words[$count].' question types');
});

test('the communities cards use the wording the event types really show', function (): void {
    $cards = collect(config('product-page.communities.cards'))->pluck('body')->implode(' ');

    foreach ([Event::CATEGORY_FAITH, Event::CATEGORY_MEMORIAL, Event::CATEGORY_ACADEMIC, Event::CATEGORY_FUNDRAISER] as $category) {
        $lexicon = EventLexicon::forCategory($category);

        expect($cards)->toContain($lexicon['contributions_title'])
            ->and($cards)->toContain($lexicon['wall_title']);
    }

    $this->get('/')->assertSee('Tribute &amp; Condolence Wall', false);
});

test('the SMS allowance each plan advertises is the one it grants', function (): void {
    Artisan::call('db:seed', ['--class' => EventPackageSeeder::class]);

    foreach (['starter', 'growth'] as $slug) {
        $plan = collect(config('product-page.plans'))->firstWhere('slug', $slug);
        $granted = Package::query()->where('slug', $slug)->firstOrFail()
            ->features()->where('slug', 'sms_credits')->firstOrFail()->pivot->value;

        expect($plan['features'])->toContain("{$granted} SMS credits per month");
    }

    $free = Package::query()->where('slug', 'free')->firstOrFail()->features()->where('slug', 'sms_credits')->firstOrFail()->pivot->value;
    expect((int) $free)->toBe(0)
        ->and(collect(config('product-page.plans'))->firstWhere('slug', 'free')['features'])->not->toContain('0 SMS credits per month');
});

test('no plan advertises API access, which is not delivered yet', function (): void {
    $features = collect(config('product-page.plans'))->pluck('features')->flatten();

    expect($features->filter(fn (string $line): bool => str_contains($line, 'API')))->toBeEmpty();
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

    $this->get('/')->assertSee('Your crew scans', false)->assertSee('your crew sees the request', false)->assertDontSee('phone buzzes', false);
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

test('the terms disclose the commission on voluntary contributions', function (): void {
    $this->get('/terms')
        ->assertOk()
        ->assertSee('Voluntary contributions')
        ->assertSee('shown in the contribution settings', false);
});

test('the leftover template documentation about LLM tokens is not served', function (): void {
    $this->get('/product-docs')->assertNotFound();
    $this->get('/product-docs/start-guide')->assertNotFound();
});

test('the terms disclose the marketplace service fee and what verification means', function (): void {
    $this->get('/terms')
        ->assertOk()
        ->assertSee('MiConvener service fee')
        ->assertSee('none for organisers on Growth or Enterprise', false)
        ->assertSee('not an endorsement', false);
});
