<?php

declare(strict_types=1);

namespace App\Models;

use App\Libraries\Helper;
use App\Traits\BelongsToTenant;
use App\Traits\HasUuid;
use App\Traits\SpatieActivityLogs;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Jenssegers\Agent\Agent;

final class UserLoginHistory extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuid;
    use SpatieActivityLogs;

    protected $table = 'user_login_histories';

    protected $connection = 'landlord';

    protected $fillable = [
        'uuid',
        'tenant_id',
        'user_id',
        'ip_address',
        'login_at',
        'logout_at',
        'session_id',
        'client_device',
        'platform',
        'browser',
        'location',
    ];

    protected $casts = [
        'login_at' => 'datetime',
        'logout_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function setLoginLog(): void
    {
        $agent = new Agent();
        $data = [];

        if ($agent->isDesktop()) {
            $data['client_device'] = 'desktop';
        } elseif ($agent->isMobile()) {
            $data['client_device'] = 'mobile';
        } elseif ($agent->isTablet()) {
            $data['client_device'] = 'tablet';
        } else {
            $data['client_device'] = 'unknown';
        }

        $this->create([
            'uuid' => Str::uuid(),
            'tenant_id' => app(\App\Services\Tenancy\TenantContext::class)->activeTenantId(),
            'user_id' => Auth::user()->id,
            'ip_address' => request()->ip(),
            'location' => Helper::getClientLocation(),
            'platform' => $agent->platform(),
            'browser' => $agent->browser(),
            'login_at' => now(),
            'session_id' => request()->session()->getId(),
        ] + $data);
    }

    public function setLogoutLog(): void
    {
        $this->where('session_id', Session::getId())->update([
            'logout_at' => now(),
        ]);
    }

    public function getDevice(): string
    {
        return $this->browser.'-'.$this->platform;
    }

    public function getLogoutTime(): string
    {
        return $this->logout_at ? Helper::getReadableDate($this->logout_at) : '-';
    }

    #[Scope]
    protected function currentMonth($query)
    {
        return $query->whereBetween('created_at', [
            now()->startOfMonth(),
            now()->endOfMonth(),
        ]);
    }

    #[Scope]
    protected function thisYear($query)
    {
        return $query->whereBetween('created_at', [
            now()->startOfYear(),
            now()->endOfYear(),
        ]);
    }
}
