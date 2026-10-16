<?php

declare(strict_types=1);

namespace App\Http\Resources\Community;

use App\Services\Community\MemberAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Member */
final class MyMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'status' => $this->status->value, 'access_role' => app(MemberAccess::class)->role($request->session(), $this->resource),
            'membership_type' => ['id' => $this->membershipType->id, 'name' => $this->membershipType->name, 'amount' => $this->effectiveAmount(), 'currency' => $this->membershipType->currency, 'period' => $this->membershipType->period?->value],
            'open_total' => (int) $this->getAttribute('open_total'), 'joined_on' => $this->joined_on?->toDateString(),
            'pay_url' => route('public.dues.show', ['subdomain' => $this->tenant->slug, 'token' => $this->pay_token])];
    }
}
