<?php

declare(strict_types=1);

namespace App\Exports;

use App\Models\User;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;

final class UserExport implements FromCollection
{
    /**
     * @return Collection
     */
    public function collection()
    {
        return User::all();
    }
}
