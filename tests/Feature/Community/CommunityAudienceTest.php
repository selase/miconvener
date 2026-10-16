<?php

declare(strict_types=1);

use App\Models\CommunityGroup;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Community\AudienceAddress;
use App\Services\Community\AudienceResolver;
use App\Services\Community\AudienceSource;
use App\Services\Community\MemberAudienceSource;
use App\Services\Community\MessagingPreferences;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

test('audiences deduplicate channel addresses without linking identifiers across tenants', function () {
    Member::factory()->count(2)->create(['tenant_id' => $this->tenant->id, 'email' => 'AMA@example.test', 'phone' => '+233241234567']);
    Member::factory()->create(['email' => 'outsider@example.test']);
    $resolver = app(AudienceResolver::class);
    $email = iterator_to_array($resolver->resolve($this->tenant, ['kind' => 'all_members'], 'email'));
    $sms = iterator_to_array($resolver->resolve($this->tenant, ['kind' => 'all_members'], 'sms'));
    expect(array_column($email, 'address'))->toBe(['ama@example.test'])->and(array_column($sms, 'address'))->toBe(['+233241234567']);
});

test('a future follower source overlaps members only once per channel', function () {
    Member::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '+233241234567']);
    $source = new class implements AudienceSource
    {
        public function addresses(Tenant $tenant, array $selector, string $channel): iterable
        {
            yield new AudienceAddress('0241234567', 'sms');
        }
    };
    $resolver = new AudienceResolver(app(MemberAudienceSource::class), app(MessagingPreferences::class), [$source]);
    expect(iterator_count($resolver->resolve($this->tenant, ['kind' => 'all_members'], 'sms')))->toBe(1);
});

test('status and group intersection excludes lapsed exited and foreign group members', function () {
    $group = CommunityGroup::factory()->create(['tenant_id' => $this->tenant->id]);
    $active = Member::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'active@example.test']);
    $lapsed = Member::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'lapsed']);
    $group->members()->attach([$active->id, $lapsed->id], ['tenant_id' => $this->tenant->id]);
    $resolver = app(AudienceResolver::class);
    $addresses = iterator_to_array($resolver->resolve($this->tenant, ['kind' => 'groups', 'group_ids' => [$group->id]], 'email'));
    expect(array_column($addresses, 'address'))->toBe(['active@example.test']);
    $foreign = CommunityGroup::factory()->create();
    expect(fn () => iterator_to_array($resolver->resolve($this->tenant, ['kind' => 'groups', 'group_ids' => [$foreign->id]], 'email')))->toThrow(Illuminate\Validation\ValidationException::class);
});

test('unknown selectors and invalid channels are rejected and opt-outs are removed', function () {
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id]);
    app(MessagingPreferences::class)->disableMarketing($this->tenant, 'email', $member->email);
    expect(iterator_count(app(AudienceResolver::class)->resolve($this->tenant, ['kind' => 'all_members'], 'email')))->toBe(0);
    expect(fn () => iterator_to_array(app(AudienceResolver::class)->resolve($this->tenant, ['kind' => 'arbitrary_sql'], 'email')))->toThrow(Illuminate\Validation\ValidationException::class);
    expect(fn () => iterator_to_array(app(AudienceResolver::class)->resolve($this->tenant, ['kind' => 'all_members'], 'fax')))->toThrow(Illuminate\Validation\ValidationException::class);
});

test('arrears resolution is denied without a finance-authorized actor', function () {
    expect(fn () => iterator_to_array(app(AudienceResolver::class)->resolve($this->tenant, ['kind' => 'arrears'], 'email')))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('invalid addresses are skipped and temporary audience data is cleaned', function () {
    Member::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'not-an-email', 'phone' => 'bad']);
    expect(iterator_count(app(AudienceResolver::class)->resolve($this->tenant, ['kind' => 'all_members'], 'email')))->toBe(0);
    expect(iterator_count(app(AudienceResolver::class)->resolve($this->tenant, ['kind' => 'all_members'], 'sms')))->toBe(0);
    expect(App\Models\CommunityAudienceAddress::query()->count())->toBe(0);
});
