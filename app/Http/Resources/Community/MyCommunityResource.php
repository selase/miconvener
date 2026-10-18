<?php

declare(strict_types=1);

namespace App\Http\Resources\Community;

use App\Models\CommunitySettings;
use App\Services\Community\Following;
use App\Services\Community\JoinSettings;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** @mixin \App\Models\Tenant */
final class MyCommunityResource extends JsonResource
{
    private ?Session $identitySession = null;

    public function withIdentitySession(Session $session): self
    {
        $this->identitySession = $session;

        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $following = app(Following::class);
        $settings = CommunitySettings::for($this->resource);
        $session = $this->identitySession ?? $request->session();
        try {
            app(JoinSettings::class)->publicConfiguration($this->resource);
            $canJoin = true;
        } catch (HttpException) {
            $canJoin = false;
        }

        return ['id' => $this->id, 'slug' => $this->slug, 'name' => $this->name, 'logo_url' => $this->logo_url,
            'following' => ['email' => $following->isFollowing($this->resource, $session, 'email'), 'sms' => $following->isFollowing($this->resource, $session, 'sms')],
            'actions' => ['follow' => $this->followingEnabled(), 'join' => $canJoin,
                'library' => app(\App\Services\Community\CommunityLibraryRollout::class)->available($this->resource) && app(\App\Services\Community\CommunityContentAccess::class)->readers($session, $this->resource, ['kind' => 'all_members'])->isNotEmpty(),
                'join_mode' => $settings->join_mode]];
    }
}
