<?php

declare(strict_types=1);
use App\Models\DuesCharge;
use App\Models\Member;
use App\Services\Community\PhoneVerification;
use App\Services\Events\PlatformAttendeeVerification;

require_once dirname(__DIR__, 2).'/Fixtures/community/portal-test-setup.php';
beforeEach(fn () => setupCommunityPortalTest($this));
it('paginates only authorised dues and separates all currencies without a wallet balance', function (): void {
    foreach (['GHS' => 12000, 'USD' => 500] as $currency => $amount) {
        DuesCharge::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'membership_type_id' => $this->member->membership_type_id, 'currency' => $currency, 'amount' => $amount, 'period_start' => $currency === 'USD' ? '2026-09-01' : '2026-10-01']);
    }
    DuesCharge::factory()->create(['tenant_id' => $this->other->id, 'member_id' => $this->foreign->id, 'membership_type_id' => $this->foreign->membership_type_id, 'amount' => 999999]);
    Member::factory()->count(21)->create(['tenant_id' => $this->tenant->id, 'email' => 'a@example.test']);
    $response = $this->withSession($this->session->all())->getJson($this->base.'/money')->assertOk()->assertJsonPath('dues.total', 22)->assertJsonCount(20, 'dues.data')->assertJsonCount(2, 'totals_by_currency')->assertJsonMissingPath('available_balance')->assertDontSee($this->foreign->name);
    expect(collect($response->json('totals_by_currency'))->pluck('open_total', 'currency')->all())->toBe(['GHS' => 12000, 'USD' => 500]);
    $this->getJson($this->base.'/money?page=2')->assertOk()->assertJsonCount(2, 'dues.data');
});
it('requires fresh member access and valid date ranges for private read-only statements', function (): void {
    $url = $this->base.'/communities/'.$this->tenant->slug.'/members/'.$this->member->id.'/statement';
    $this->withSession($this->session->all())->getJson($url)->assertUnprocessable();
    $this->getJson($url.'?from=2020-01-01&to=2026-12-31')->assertUnprocessable();
    $this->getJson($url.'?from=2026-10-17&to=2026-01-01')->assertUnprocessable();
    $this->get($url.'?from=2026-01-01&to=2026-12-31')->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->member->update(['email' => 'changed@example.test']);
    $this->get($url.'?from=2026-01-01&to=2026-12-31')->assertNotFound();
});
it('does not infer event giving history from phone possession', function (): void {
    $this->session->forget(PlatformAttendeeVerification::SESSION_KEY);
    $this->session->put(PhoneVerification::SESSION_KEY, ['phone' => $this->member->phone, 'expires_at' => now()->addHour()->timestamp]);
    $this->withSession($this->session->all())->getJson($this->base.'/money')->assertOk()->assertJsonCount(0, 'dues.data')->assertJsonPath('contributions', null);
});
