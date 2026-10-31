<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\StoreOrder;

interface StoreReservationAdapter
{
    public function reserve(StoreOrder $order): void;

    public function release(StoreOrder $order, string $reason): void;

    public function consume(StoreOrder $order): void;
}
