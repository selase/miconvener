<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Contracts\Session\Session;

final class InboxAccess
{
    public function __construct(private CommunityHistory $history, private MemberAccess $members, private InboxReaderKey $keys, private PlatformAttendeeVerification $emails, private PhoneVerification $phones) {}

    /** @return list<array{tenant_id:string,recipient_id:string,recipient_type:string,reader_key:string,context_key:string,label:?string,consented_at:?string}> */
    public function contexts(Session $session): array
    {
        $result = [];
        foreach ($this->history->memberQuery($session)->cursor() as $member) {
            $role = $this->members->role($session, $member);
            if ($role === null) {
                continue;
            }
            $representative = $role === 'representative';
            $subject = $representative ? 'member:'.$member->id.':rep:'.PhoneVerification::digest((string) $this->phones->verifiedPhone($session)) : 'member:'.$member->id.':self';
            $result[] = ['tenant_id' => $member->tenant_id, 'recipient_id' => $member->id, 'recipient_type' => 'member', 'reader_key' => $this->keys->forSubject($subject), 'context_key' => $representative ? 'representative:'.$member->id : 'self', 'label' => $representative ? 'For '.$member->name.' (representative)' : null, 'consented_at' => null];
        }
        $email = $this->emails->verifiedEmail($session);
        if ($email !== null) {
            foreach ($this->history->followerQuery($session)->cursor() as $follower) {
                $result[] = ['tenant_id' => $follower->tenant_id, 'recipient_id' => $follower->id, 'recipient_type' => 'follower', 'reader_key' => $this->keys->forSubject('email:'.$email), 'context_key' => 'self', 'label' => null, 'consented_at' => $follower->consented_at->format('Y-m-d H:i:s.uP')];
            }
        }

        return $result;
    }
}
