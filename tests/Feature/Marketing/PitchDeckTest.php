<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

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
    $response->assertSee('Interactive ROI Calculator', false);
    $response->assertSee('Presenter Talking Points', false);

});

test('the pitch deck respects tone guidelines and exclusions', function (): void {
    $response = $this->get('/deck');

    // "military grade" should not be present
    $response->assertDontSee('military-grade', false);
    $response->assertDontSee('military grade', false);

    // WhatsApp and SMS excluded for now
    $response->assertDontSee('WhatsApp', false);
    $response->assertDontSee('SMS', false);
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
