<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Community\MessagingPreferences;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;

final class CommunityPreferenceController extends Controller
{
    public function show(string $subdomain, string $token, MessagingPreferences $preferences): View
    {
        $tenant = app(TenantContext::class)->getTenant();
        $row = $preferences->forToken($tenant, $token);

        return view('community.preferences', ['organisation' => $tenant->name, 'disabled' => $row->marketing_disabled_at !== null]);
    }

    public function update(string $subdomain, string $token, MessagingPreferences $preferences): View
    {
        $tenant = app(TenantContext::class)->getTenant();
        $row = $preferences->forToken($tenant, $token);
        $preferences->disableMarketing($tenant, $row->channel, $row->address);

        return view('community.preferences', ['organisation' => $tenant->name, 'disabled' => true]);
    }
}
