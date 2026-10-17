<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\MyGivingRequest;
use App\Models\GivingPaymentAttempt;
use App\Models\Tenant;
use App\Services\Giving\GivingHistory;
use App\Services\Giving\GivingSource;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MyGivingController extends Controller
{
    public function index(MyGivingRequest $request, GivingHistory $history): Response
    {
        $data = ['giving' => $history->page($request->session(), $request->validated())->toArray(), 'filters' => $request->validated(), 'identity' => $this->context($request, $history)];
        if ($request->expectsJson()) {
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }
        $response = \Inertia\Inertia::render('Public/Giving/History', $data)->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function session(Request $request, GivingHistory $history): JsonResponse
    {
        return response()->json($this->context($request, $history))->header('Cache-Control', 'private, no-store');
    }

    public function receipt(Request $request, string $type, string $source, GivingHistory $history): Response
    {
        $class = $type === 'gift' ? \App\Models\GivingGift::class : ($type === 'event_contribution' ? \App\Models\EventContribution::class : null);
        abort_unless($class !== null, 404);
        $record = $class::withoutGlobalScopes()->findOrFail($source);
        $tenant = Tenant::query()->findOrFail($record->tenant_id);

        return $this->pdf($history->sourceForReceipt($request->session(), $tenant, $type, $source), $history);
    }

    public function guestReceipt(Request $request, string $subdomain, string $reference, GivingHistory $history): Response
    {
        $model = app(\App\Services\Tenancy\TenantContext::class)->getTenant();
        $attempt = GivingPaymentAttempt::withoutGlobalScopes()->where('tenant_id', $model->id)->where('reference', $reference)->firstOrFail();

        return $this->pdf($history->sourceForReceipt($request->session(), $model, $attempt->gift_id === null ? 'event_contribution' : 'gift', $attempt->gift_id ?? $attempt->event_contribution_id), $history);
    }

    public function statement(MyGivingRequest $request, GivingHistory $history): Response
    {
        $rows = $history->statement($request->session(), $request->validated());
        if ($request->validated('format') === 'csv') {
            return response()->streamDownload(function () use ($rows): void {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Organisation', 'Currency', 'Fund', 'Type', 'Date', 'Amount (minor units)', 'Access', 'For member', 'Undated legacy refund (excluded from dated net)'], ',', '"', '');
                foreach ($rows as $row) {
                    $fields = [$row['organisation'], $row['currency'], $row['fund'], $row['kind'], $row['date'], $row['amount'], $row['access_role'], $row['member_name'], $row['undated_amount']];
                    fputcsv($file, array_map(fn ($value) => is_string($value) && preg_match('/\A[\x00-\x20]*[=+@-]|\A[\t\r\n]/u', $value) ? "'".$value : $value, $fields), ',', '"', '');
                }fclose($file);
            }, 'contribution-statement.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
        }

        return Pdf::loadView('pdf.giving-statement', ['rows' => $rows, 'from' => $request->validated('from'), 'to' => $request->validated('to')])->setPaper('a4')->download('contribution-statement.pdf')->header('Cache-Control', 'private, no-store');
    }

    /** @return array<string,mixed> */
    private function context(Request $request, GivingHistory $history): array
    {
        return [...app(\App\Services\Events\AttendeeIdentityContext::class)->for($request->session()), 'access_key' => $history->accessKey($request->session())];
    }

    private function pdf(GivingSource $source, GivingHistory $history): Response
    {
        if ($source->model instanceof \App\Models\EventContribution) {
            $service = app(\App\Services\Events\DonationReceiptPdfService::class);

            return response($service->generate($source->model), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$service->filename($source->model).'"', 'Cache-Control' => 'private, no-store']);
        }

        $changes = \App\Models\GivingAdjustment::withoutGlobalScopes()->where('tenant_id', $source->fund->tenant_id)->where($source->type === 'gift' ? 'gift_id' : 'event_contribution_id', $source->id)->where('status', 'confirmed')->orderBy('confirmed_at')->get();

        return Pdf::loadView('pdf.giving-receipt', ['receipt' => $history->originalReceipt($source), 'adjustments' => $changes])->setPaper('a4')->download('giving-receipt-'.$source->id.'.pdf')->header('Cache-Control', 'private, no-store');
    }
}
