<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Community\ConfirmMemberImportRequest;
use App\Http\Requests\Tenant\Community\UploadMemberImportRequest;
use App\Models\Tenant;
use App\Services\Community\MemberImport;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MemberImportController extends Controller
{
    public function show(): Response
    {
        $this->authorize('manage members');

        return Inertia::render('Tenant/Community/ImportMembers');
    }

    public function template(): StreamedResponse
    {
        $this->authorize('manage members');

        return response()->streamDownload(function (): void {
            echo implode(',', MemberImport::HEADERS)."\n";
        }, 'members-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(UploadMemberImportRequest $request, MemberImport $import): JsonResponse
    {
        return response()->json($import->preview($this->tenant(), $request->file('file')));
    }

    public function confirm(ConfirmMemberImportRequest $request, MemberImport $import): JsonResponse
    {
        try {
            return response()->json($import->confirm($this->tenant(), $request->validated('token'), $request->validated('existing_policy'), (int) $request->user()->id));
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'This import is being confirmed. Wait a moment and try again.'], 409);
        }
    }

    private function tenant(): Tenant
    {
        return app(TenantContext::class)->getTenant() ?? abort(403, 'Tenant context not resolved.');
    }
}
