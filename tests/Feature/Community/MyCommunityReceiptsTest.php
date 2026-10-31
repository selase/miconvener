<?php

declare(strict_types=1);
use App\Enum\DuesChargeStatusEnum;
use App\Services\Community\MemberAccess;
use App\Services\Community\PhoneVerification;
use App\Services\Events\PlatformAttendeeVerification;

require_once dirname(__DIR__, 2).'/Fixtures/community/portal-test-setup.php';
beforeEach(function (): void {
    setupCommunityPortalTest($this);
    $this->paid = App\Models\DuesCharge::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'membership_type_id' => $this->member->membership_type_id, 'status' => DuesChargeStatusEnum::Paid, 'paid_at' => now(), 'payment_reference' => 'test-paid-reference']);
    $this->receipt = $this->base.'/communities/'.$this->tenant->slug.'/members/'.$this->member->id.'/receipts/'.$this->paid->id;
});
it('downloads only an authorised members paid receipt with private cache headers', function (): void {
    $response = $this->withSession($this->session->all())->get($this->receipt);
    $response->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->and($response->getContent())->toStartWith('%PDF-');
    $foreign = App\Models\DuesCharge::factory()->create();
    $this->get($this->base.'/communities/'.$this->tenant->slug.'/members/'.$this->member->id.'/receipts/'.$foreign->id)->assertNotFound();
    $this->paid->update(['status' => DuesChargeStatusEnum::Open]);
    $this->get($this->receipt)->assertNotFound();
});
it('rechecks a phone grant at download time without relying on an earlier page view', function (): void {
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => $this->member->phone, 'expires_at' => now()->addHour()->timestamp]);
    $credential = app(MemberAccess::class)->enrolPhone($this->session, $this->member);
    $this->session->forget(PlatformAttendeeVerification::SESSION_KEY);
    $this->withSession($this->session->all())->get($this->receipt)->assertOk();
    App\Models\CommunityAccessCredential::findOrFail($credential['selector'])->update(['revoked_at' => now()]);
    $this->get($this->receipt)->assertNotFound();
});
