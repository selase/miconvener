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
    $response->assertSee('Offline-First PWA Scanner', false);
    $response->assertSee('Print Tags with Names', false);

    // Speaker portal and PowerPoint sharing
    $response->assertSee('Dedicated Speaker Portal', false);
    $response->assertSee('PowerPoint Submissions', false);
    $response->assertSee('PowerPoint & Slide Sharing', false);

    // Food menu and during-event lunch tracking
    $response->assertSee('Custom Forms & Food Menus', false);
    $response->assertSee('During-Event Lunch & Menu Tracking', false);

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
