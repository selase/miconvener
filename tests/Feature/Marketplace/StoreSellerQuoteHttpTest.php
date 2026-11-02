<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\StoreCheckoutScenario;

beforeEach(function (): void {
    [$this->seller, $this->variant, $this->actor, $this->buyerSession, $this->order, $this->quote] = StoreCheckoutScenario::sellerQuote();
    $this->seller->users()->syncWithoutDetaching([$this->actor->id]);
    $this->host = $this->seller->slug.'.miconvener.test';
    $this->staffUrl = 'http://'.$this->host.'/store/quotes';
    $arrangement = $this->order->fulfilmentArrangement;
    $this->input = ['operation_key' => (string) Str::uuid(), 'buyer_email' => 'buyer@example.test', 'valid_for_hours' => 48,
        'production_terms' => 'Seven working days after payment.', 'customisation' => 'Red association name.', 'printing_pesewas' => 5000, 'delivery_pesewas' => 2500,
        'lines' => [['variant_id' => $this->variant->id, 'quantity' => 2, 'unit_price_pesewas' => 20000]],
        'choice' => ['policy_id' => $arrangement->policy_id, 'method' => 'delivery', 'destination' => $arrangement->destination]];
    Http::preventStrayRequests();
    Http::fake(['*api.paystack.co/customer*' => Http::response(['data' => ['customer_code' => 'CUS_QUOTES']]),
        '*api.paystack.co/transaction/initialize' => Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/quoted-test']])]);
});

it('offers seller issuance and a canonical private payment link through existing tenant authentication', function (): void {
    $this->withSession(['active_tenant_id' => $this->seller->id])->actingAs($this->actor)
        ->getJson($this->staffUrl, ['HTTP_HOST' => $this->host])->assertOk()->assertJsonPath('can_issue', true)->assertJsonPath('variants.0.id', $this->variant->id)
        ->assertJsonPath('quotes.data.0.order.destination.address_line_1', '12 Test Street');
    $response = $this->postJson($this->staffUrl, $this->input, ['HTTP_HOST' => $this->host])->assertCreated()
        ->assertJsonPath('quote.order.total_pesewas', 47500)->assertHeader('Cache-Control', 'no-store, private');
    expect($response->json('quote.payment_link'))->toBe(mb_rtrim((string) config('app.url'), '/').'/my/store-orders/'.$response->json('quote.order.id'));
});

it('does not grant quote management through catalogue-only permission or a foreign organisation', function (): void {
    $this->actor->revokePermissionTo('manage store orders');
    $this->withSession(['active_tenant_id' => $this->seller->id])->actingAs($this->actor)
        ->getJson($this->staffUrl, ['HTTP_HOST' => $this->host])->assertForbidden();
});

it('rejects caller-supplied commercial overrides and bounds withdrawal to the current seller', function (): void {
    $this->withSession(['active_tenant_id' => $this->seller->id])->actingAs($this->actor);
    $this->postJson($this->staffUrl, [...$this->input, 'percentage' => 0], ['HTTP_HOST' => $this->host])->assertUnprocessable()->assertJsonValidationErrors('percentage');
    $this->postJson($this->staffUrl.'/'.$this->quote->id.'/withdraw', ['reason' => 'New agreed print specification.'], ['HTTP_HOST' => $this->host])->assertOk();
    expect($this->quote->fresh()->withdrawn_at)->not->toBeNull();
});

it('reveals the agreed breakdown only to the original verified buyer', function (): void {
    $url = 'http://miconvener.test/my/store-orders/'.$this->order->id;
    $this->getJson($url, ['HTTP_HOST' => 'miconvener.test'])->assertUnauthorized()->assertDontSee('Red association name');
    $this->withSession(proofFor('other@example.test'))->getJson($url, ['HTTP_HOST' => 'miconvener.test'])->assertNotFound();
    $this->withSession(proofFor('buyer@example.test'))->getJson($url, ['HTTP_HOST' => 'miconvener.test'])->assertOk()
        ->assertJsonPath('order.seller_quote.production_terms', 'Seven working days after payment.')
        ->assertJsonPath('order.seller_quote.customisation', 'Red association name.')
        ->assertJsonPath('order.seller_quote.acceptance_hash', $this->order->terms_hash)
        ->assertJsonPath('order.charges.0.amount_pesewas', 5000)->assertJsonPath('order.charges.1.amount_pesewas', 2500)
        ->assertJsonPath('order.fulfilment_options', [])->assertHeader('Cache-Control', 'no-store, private');
});

it('requires explicit acceptance of the exact quote at the payment HTTP boundary', function (): void {
    $url = 'http://miconvener.test/my/store-orders/'.$this->order->id.'/checkout';
    $this->withSession(proofFor('buyer@example.test'))->postJson($url, ['submission_key' => (string) Str::uuid()], ['HTTP_HOST' => 'miconvener.test'])->assertUnprocessable();
    $this->postJson($url, ['submission_key' => (string) Str::uuid(), 'accepted_quote_hash' => $this->order->terms_hash], ['HTTP_HOST' => 'miconvener.test'])
        ->assertOk()->assertJsonPath('status', 'ready');
});

it('does not give a read-only order manager a new pricing or withdrawal power', function (): void {
    $this->actor->revokePermissionTo('manage store catalog');
    $this->withSession(['active_tenant_id' => $this->seller->id])->actingAs($this->actor)
        ->getJson($this->staffUrl, ['HTTP_HOST' => $this->host])->assertOk()->assertJsonPath('can_issue', false)->assertJsonPath('quotes.data.0.can_withdraw', false);
    $this->postJson($this->staffUrl, $this->input, ['HTTP_HOST' => $this->host])->assertForbidden();
    $this->postJson($this->staffUrl.'/'.$this->quote->id.'/withdraw', ['reason' => 'Change the amount'], ['HTTP_HOST' => $this->host])->assertForbidden();
});

it('never exposes or withdraws another seller quote through the current seller routes', function (): void {
    $foreign = App\Models\StoreSellerQuote::factory()->create();
    $this->withSession(['active_tenant_id' => $this->seller->id])->actingAs($this->actor)
        ->postJson($this->staffUrl.'/'.$foreign->id.'/withdraw', ['reason' => 'Unrelated seller'], ['HTTP_HOST' => $this->host])->assertNotFound();
    $response = $this->getJson($this->staffUrl, ['HTTP_HOST' => $this->host])->assertOk();
    expect(collect($response->json('quotes.data'))->pluck('id')->all())->not->toContain($foreign->id);
});
