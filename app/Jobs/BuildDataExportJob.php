<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enum\DataExportStatus;
use App\Models\DataExportRequest;
use App\Notifications\DataExportReadyNotification;
use App\Services\Compliance\DataExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BuildDataExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        private readonly DataExportRequest $exportRequest,
    ) {}

    public function handle(DataExportService $exportService): void
    {
        try {
            $exportService->buildExport($this->exportRequest);

            $user = $this->exportRequest->requestedBy;

            if ($user) {
                $user->notify(new DataExportReadyNotification($this->exportRequest));
            }
        } catch (Throwable $e) {
            Log::error('Data export failed', [
                'export_request_id' => $this->exportRequest->id,
                'error' => $e->getMessage(),
            ]);

            $this->exportRequest->update([
                'status' => DataExportStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
