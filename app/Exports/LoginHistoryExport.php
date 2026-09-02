<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\UserLoginHistory;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;

final class LoginHistoryExport implements FromCollection
{
    /**
     * @return Collection
     */
    public function collection()
    {
        return UserLoginHistory::query()->select(['id', 'ip_address', 'location', 'client_device', 'platform', 'browser', 'login_at', 'logout_at'])
            ->latest()
            ->get();
    }
}
