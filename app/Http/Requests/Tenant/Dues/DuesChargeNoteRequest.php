<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

/**
 * Waiving or voiding a charge takes the same optional note as recording an
 * offline payment.
 */
final class DuesChargeNoteRequest extends ChargeNoteRequest {}
