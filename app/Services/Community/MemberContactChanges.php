<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Jobs\Community\DeliverContactNotice;
use App\Models\CommunityContactNotice;
use App\Models\Member;
use App\Models\User;
use App\Scopes\TenantScope;

final class MemberContactChanges
{
    public function __construct(private readonly MemberAccess $access, private readonly MemberIdentity $identity, private readonly MessagingPreferences $addresses) {}

    /** @param array<string,mixed> $validated */
    public function update(Member $member, array $validated, User $actor): Member
    {
        $this->access->authorizeStaff($member->tenant, $actor);

        return $member->getConnection()->transaction(function () use ($member, $validated): Member {
            $locked = Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $member->tenant_id)->lockForUpdate()->findOrFail($member->id);
            $old = ['email' => $locked->email, 'sms' => $locked->phone];
            $oldVersion = $locked->access_version;
            if (array_key_exists('email', $validated)) {
                $validated['email'] = $this->identity->normaliseEmail($validated['email']);
            }
            if (array_key_exists('phone', $validated) && filled($validated['phone'])) {
                $validated['phone'] = $this->identity->normalisePhone($validated['phone']) ?? throw \Illuminate\Validation\ValidationException::withMessages(['phone' => 'Enter a valid phone number.']);
            }
            $locked->update($validated);
            if ($locked->access_version !== $oldVersion) {
                foreach (['email' => $locked->email, 'sms' => $locked->phone] as $channel => $current) {
                    $destinations = array_unique(array_filter([$this->addresses->normalise($channel, (string) $old[$channel]), $this->addresses->normalise($channel, (string) $current)]));
                    foreach ($destinations as $address) {
                        $notice = CommunityContactNotice::query()->firstOrCreate(['member_id' => $locked->id, 'contact_version' => $locked->access_version, 'channel' => $channel, 'address_hash' => $this->addresses->hash($channel, $address)], ['tenant_id' => $locked->tenant_id, 'address' => $address, 'status' => 'pending']);
                        DeliverContactNotice::dispatch($notice->id)->afterCommit();
                    }
                }
            }

            return $locked;
        });
    }
}
