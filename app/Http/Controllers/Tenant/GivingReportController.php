<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Giving\GivingReportRequest;
use App\Models\CommunitySettings;
use App\Models\GivingFund;
use App\Services\Giving\GivingReport;
use App\Services\Giving\GivingRollout;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class GivingReportController extends Controller
{
    public function index(GivingReportRequest $request, GivingReport $report): Response
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_unless(app(GivingRollout::class)->enabled($tenant) || $tenant->hasGivingMoney(), 404);
        $input = $request->validated();
        $tz = CommunitySettings::for($tenant)->timezone;
        $from = isset($input['from']) ? CarbonImmutable::parse($input['from'], $tz)->startOfDay() : CarbonImmutable::now($tz)->startOfMonth();
        $to = isset($input['to']) ? CarbonImmutable::parse($input['to'], $tz)->endOfDay() : CarbonImmutable::now($tz)->endOfDay();
        $summary = $report->summary($tenant, $from->utc(), $to->utc(), $input['fund'] ?? null, $input['currency'] ?? null);
        if (($input['format'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($summary): void {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Currency', 'Collected online', 'Recorded cash', 'Refunds', 'Corrections', 'Net online', 'Available', 'Undated legacy refunds (excluded from dated net)'], ',', '"', '');
                foreach ($summary as $currency => $values) {
                    fputcsv($file, [$currency, ...array_values($values)], ',', '"', '');
                }
                fclose($file);
            }, 'giving-summary.csv', ['Cache-Control' => 'private, no-store', 'Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $response = Inertia::render('Tenant/Community/GivingReport', ['summary' => $summary, 'filters' => [...$input, 'from' => $from->toDateString(), 'to' => $to->toDateString()], 'funds' => GivingFund::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name'])])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
