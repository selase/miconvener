<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunitySettings;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeVerification;
use App\Support\ContactMask;
use Illuminate\Contracts\Session\Session;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CommunityPublicContext
{
    /** @return array<string,mixed> */
    public function for(Tenant $tenant, Session $session, ?\App\Models\Event $source = null): array
    {
        $email = app(PlatformAttendeeVerification::class)->verifiedEmail($session);
        $phone = app(PhoneVerification::class)->verifiedPhone($session);
        $identity = $email === null && $phone === null ? null : ['email' => $email === null ? null : ['masked' => ContactMask::email($email)], 'phone' => $phone === null ? null : ['masked' => '••••'.mb_substr($phone, -4)]];
        $settings = CommunitySettings::for($tenant);
        $type = null;
        $enabled = false;
        try {
            $configuration = app(JoinSettings::class)->publicConfiguration($tenant);
            $type = $configuration['type'];
            $enabled = true;
        } catch (HttpException) {
        }
        $about = route('public.community.about', ['subdomain' => $tenant->slug]);
        $return = $source === null ? $about : route('public.events.show', ['subdomain' => $tenant->slug, 'event' => $source->slug]);
        $my = mb_rtrim((string) config('app.url'), '/').route('attendee.my', [], false);

        return ['organisation' => ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'logo_url' => $tenant->logo_url, 'about_url' => $about],
            'identity' => $identity, 'following' => ['email' => app(Following::class)->isFollowing($tenant, $session, 'email'), 'sms' => app(Following::class)->isFollowing($tenant, $session, 'sms')],
            'followingEnabled' => $tenant->status === \App\Enum\TenantStatusEnum::ACTIVE && $tenant->followingEnabled(),
            'communityEnabled' => $tenant->status === \App\Enum\TenantStatusEnum::ACTIVE && $tenant->communityEnabled(),
            'joinOptions' => ['enabled' => $enabled, 'mode' => $settings->join_mode, 'reason' => $enabled ? null : 'The organisation has not opened public joining. Please contact its team.',
                'type' => $type === null ? null : ['name' => $type->name, 'amount' => $type->amount, 'currency' => $type->currency, 'period' => $type->period?->value]],
            'followUrl' => route('public.community.follow.store', ['subdomain' => $tenant->slug]), 'joinUrl' => route('public.community.join.store', ['subdomain' => $tenant->slug]),
            'signInUrl' => $my.'?'.http_build_query(['organiser' => $tenant->slug, 'return_to' => $return]),
            'portalUrl' => $my.'?'.http_build_query(['organiser' => $tenant->slug]).'#communities'];
    }
}
