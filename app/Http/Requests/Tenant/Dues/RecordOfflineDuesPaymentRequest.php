<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

/**
 * Records that a member paid a charge in cash or by transfer, optionally with
 * a note saying how.
 */
final class RecordOfflineDuesPaymentRequest extends ChargeNoteRequest {}
