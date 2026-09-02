<?php

declare(strict_types=1);

use App\Mail\NewEnterpriseLead;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Mail::fake();
    RateLimiter::clear('lead-submission:127.0.0.1');
});

it('captures a lead and sends a notification', function () {
    $data = [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'company' => 'Doe Inc.',
        'message' => 'We are interested in your enterprise plan.',
    ];

    $response = $this->post(route('product.enterprise.lead'), $data);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    // Verify DB
    $this->assertDatabaseHas('leads', [
        'email' => 'john@example.com',
        'company' => 'Doe Inc.',
    ], 'landlord');

    // Verify Mail
    Mail::assertSent(NewEnterpriseLead::class, function ($mail) {
        return $mail->lead->email === 'john@example.com';
    });
});

it('enforces rate limiting on lead submissions', function () {
    $data = [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'message' => 'Repeated interest message.',
    ];

    // First 3 should pass
    for ($i = 0; $i < 3; $i++) {
        $this->post(route('product.enterprise.lead'), $data)->assertSessionHas('success');
    }

    // 4th should fail
    $response = $this->post(route('product.enterprise.lead'), $data);
    $response->assertSessionHas('error', 'Too many requests. Please try again later.');
});

it('validates lead submission fields', function () {
    $response = $this->post(route('product.enterprise.lead'), [
        'name' => '',
        'email' => 'invalid-email',
        'message' => 'short',
    ]);

    $response->assertSessionHasErrors(['name', 'email', 'message']);
});
