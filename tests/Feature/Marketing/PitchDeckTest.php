<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Models\Package;
use Database\Seeders\EventPackageSeeder;

test('the pitch deck renders across /deck, /pitch, and /slides routes', function (): void {
    $this->get('/deck')->assertOk();
    $this->get('/pitch')->assertOk();
    $this->get('/slides')->assertOk();
});

test('the pitch deck explicitly features core event production and sales capabilities', function (): void {
    $response = $this->get('/deck');

    // Notifications and pre-event communication
    $response->assertSee('Notifications & Reminders', false);
    $response->assertSee('Pre-Event Communications', false);

    // Check-in and name tag printing
    $response->assertSee('Scanning That Survives Bad Signal', false);
    $response->assertSee('Print Tags with Names', false);

    // Speaker portal and PowerPoint sharing
    $response->assertSee('Dedicated Speaker Portal', false);
    $response->assertSee('PowerPoint Submissions', false);
    $response->assertSee('PowerPoint & Slide Sharing', false);

    // Food menu and during-event lunch tracking
    $response->assertSee('Custom Forms & Food Menus', false);
    $response->assertDontSee('During-Event Lunch & Menu Tracking', false);

    // Room management and in-seat service requests
    $response->assertSee('In-Seat Service Requests (Room Management)', false);
    $response->assertSee('Water & Refreshments', false);

    // Live quizzes and certificates
    $response->assertSee('Live Quizzes & Leaderboards', false);
    $response->assertSee('Certificates of Participation', false);

    // Interactive ROI & Presenter talking points
    $response->assertSee('One Commission, Shown Before You Sell', false);
    $response->assertSee('Presenter Talking Points', false);

});

test('the pitch deck respects tone guidelines and exclusions', function (): void {
    $response = $this->get('/deck');

    // "military grade" should not be present
    $response->assertDontSee('military-grade', false);
    $response->assertDontSee('military grade', false);

    // WhatsApp is not integrated. SMS is, and the deck may say so.
    $response->assertDontSee('WhatsApp', false);
});

test('the public deck makes no claim the product cannot back', function (): void {
    $html = $this->get('/deck')->assertOk()->getContent();

    foreach ([
        'summarize with AI',
        'Zero gate failure',
        'Zero Ticket Scalping',
        'zero Wi-Fi dependencies',
        '<500ms',
        '65–80%',
        'across Africa and the world',
        'instant automated settlement',
        'point multipliers',
        'negative marking',
        'Lunch & Menu Tracking',
        'Flags badge if lunch already claimed',
        'Cryptographic signed QR',
        'watermarking',
        'Over 120 staff hours',
        'COI disclosures',
        'in 60 seconds',
    ] as $claim) {
        expect($html)->not->toContain($claim);
    }

    // What is real stays: offline door scanning and in-seat requests.
    expect($html)->toContain('keep checking tickets without a connection')
        ->toContain('In-Seat Water & Tech Requests');
});

test('the deck prices ticketing from the seeded plans rather than invented competitor costs', function (): void {
    $this->seed(EventPackageSeeder::class);

    $html = $this->get('/deck')->assertOk()->getContent();

    foreach (['Slido', 'Mentimeter', 'Whova', 'Hopin', 'Estimated Savings', 'Staff Hours Saved', 'sponsor ROI'] as $claim) {
        expect($html)->not->toContain($claim);
    }

    // The calculator is fed the commission billing applies, cap in cedis.
    foreach (['starter', 'growth'] as $slug) {
        $package = Package::query()->where('slug', $slug)->firstOrFail();

        expect($html)->toContain('"name":"'.$package->name.'"')
            ->toContain('"cap":'.($package->default_platform_fee_cap_amount / 100));
    }
});
