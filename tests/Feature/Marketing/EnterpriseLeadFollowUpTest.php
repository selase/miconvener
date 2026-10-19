<?php

declare(strict_types=1);

use App\Mail\EnterpriseEnquiryReceived;
use App\Mail\NewEnterpriseLead;
use App\Models\Lead;
use App\Models\PlatformNotificationRecipient;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * An Enterprise enquiry reaches the people chosen to receive it, a reply goes
 * straight to the enquirer, the enquirer hears back at once, and the lead is
 * followed through to won or lost in the console.
 */
beforeEach(function (): void {
    Mail::fake();
    RateLimiter::clear('lead-submission:127.0.0.1');
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function leadStaff(string $role): User
{
    $user = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $user->assignRole(Role::query()->where('name', $role)->whereNull('tenant_id')->firstOrFail());

    return $user;
}

function submitEnquiry(): void
{
    test()->post(route('product.enterprise.lead'), [
        'name' => 'Ama Mensah', 'email' => 'ama@institute.example', 'company' => 'Regional Institute',
        'message' => 'We run a five-day assembly for 1,200 delegates.',
    ])->assertSessionHas('success');
}

test('enquiries go to the owner from the start', function (): void {
    expect(PlatformNotificationRecipient::query()->where('topic', PlatformNotificationRecipient::ENTERPRISE_LEADS)->pluck('email')->all())->toBe(['hiselase@gmail.com']);
});

test('an enquiry goes to every chosen recipient, with replies going to the enquirer', function (): void {
    PlatformNotificationRecipient::query()->delete();
    PlatformNotificationRecipient::query()->create(['topic' => PlatformNotificationRecipient::ENTERPRISE_LEADS, 'email' => 'sales@example.com']);
    PlatformNotificationRecipient::query()->create(['topic' => PlatformNotificationRecipient::ENTERPRISE_LEADS, 'email' => 'owner@example.com']);

    submitEnquiry();

    Mail::assertQueued(NewEnterpriseLead::class, fn (NewEnterpriseLead $mail): bool => $mail->hasTo('sales@example.com')
        && $mail->hasTo('owner@example.com')
        && $mail->hasReplyTo('ama@institute.example'));
});

test('the enquirer is told it arrived, and their reply reaches the recipients', function (): void {
    PlatformNotificationRecipient::query()->delete();
    PlatformNotificationRecipient::query()->create(['topic' => PlatformNotificationRecipient::ENTERPRISE_LEADS, 'email' => 'sales@example.com']);

    submitEnquiry();

    Mail::assertQueued(EnterpriseEnquiryReceived::class, fn (EnterpriseEnquiryReceived $mail): bool => $mail->hasTo('ama@institute.example')
        && $mail->hasReplyTo('sales@example.com'));
});

test('with no recipients set, the enquiry is still saved and goes to staff who follow up signups', function (): void {
    PlatformNotificationRecipient::query()->delete();
    $marketer = leadStaff('Platform Marketing');

    submitEnquiry();

    expect(Lead::query()->where('email', 'ama@institute.example')->exists())->toBeTrue();
    Mail::assertQueued(NewEnterpriseLead::class, fn (NewEnterpriseLead $mail): bool => $mail->hasTo($marketer->email));
});

test('staff who follow up signups record progress and notes on a lead', function (): void {
    $lead = Lead::query()->create(['name' => 'Ama', 'email' => 'ama@institute.example', 'message' => 'Five-day assembly', 'status' => 'new']);

    $this->actingAs(leadStaff('Platform Marketing'))
        ->patch(route('admin.leads.update', $lead), ['status' => 'contacted', 'notes' => 'Called; sending a proposal for 1,200 delegates.'])
        ->assertSessionHas('success');

    expect($lead->fresh()->status)->toBe('contacted')
        ->and($lead->fresh()->notes)->toBe('Called; sending a proposal for 1,200 delegates.');

    $this->actingAs(leadStaff('Platform Marketing'))
        ->patch(route('admin.leads.update', $lead), ['status' => 'bought', 'notes' => ''])
        ->assertSessionHasErrors('status');
});

test('leads are only for staff who may see signups', function (): void {
    $lead = Lead::query()->create(['name' => 'Ama', 'email' => 'ama@institute.example', 'message' => 'Five-day assembly', 'status' => 'new']);
    $finance = leadStaff('Platform Finance');
    $finance->roles()->first()->revokePermissionTo('view signups');

    $this->actingAs($finance)->get(route('admin.leads.show', $lead))->assertForbidden();
    $this->actingAs($finance)->patch(route('admin.leads.update', $lead), ['status' => 'won'])->assertForbidden();
    $this->actingAs($finance)->delete(route('admin.leads.destroy', $lead))->assertForbidden();
    $this->actingAs(leadStaff('Platform Marketing'))->get(route('admin.leads.show', $lead))->assertOk()->assertSee('Five-day assembly');
});

test('a superadmin chooses who receives enquiries, and nobody else can', function (): void {
    PlatformNotificationRecipient::query()->delete();
    $this->actingAs(leadStaff('Superadmin'))
        ->post(route('admin.lead-recipients.store'), ['email' => 'Sales@Example.com'])
        ->assertSessionHas('success');

    $recipient = PlatformNotificationRecipient::query()->where('email', 'sales@example.com')->firstOrFail();

    $this->actingAs(leadStaff('Superadmin'))
        ->post(route('admin.lead-recipients.store'), ['email' => 'sales@example.com'])
        ->assertSessionHasErrors('email');

    $this->actingAs(leadStaff('Platform Marketing'))
        ->post(route('admin.lead-recipients.store'), ['email' => 'other@example.com'])
        ->assertForbidden();

    $this->actingAs(leadStaff('Superadmin'))
        ->delete(route('admin.lead-recipients.destroy', $recipient))
        ->assertSessionHas('success');

    expect(PlatformNotificationRecipient::query()->count())->toBe(0);
});
