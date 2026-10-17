<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TakedownInboxMessageRequest;
use App\Models\CommunityAccessAudit;
use App\Models\CommunityMessage;
use App\Services\Community\InboxWriter;
use Illuminate\Http\RedirectResponse;

final class CommunityInboxTakedownController extends Controller
{
    public function __invoke(TakedownInboxMessageRequest $request, string $message): RedirectResponse
    {
        $this->authorize('access-superadmin-dashboard');
        $model = CommunityMessage::withoutGlobalScopes()->findOrFail($message);
        $model->getConnection()->transaction(function () use ($request, $model): void {
            $locked = CommunityMessage::withoutGlobalScopes()->lockForUpdate()->findOrFail($model->id);
            app(InboxWriter::class)->withdrawMessage($locked);
            CommunityAccessAudit::query()->create(['tenant_id' => $locked->tenant_id, 'actor_id' => $request->user()->id, 'action' => 'inbox_superadmin_takedown', 'method' => 'superadmin', 'reason' => $request->validated('reason')]);
        });

        return back()->with('success', 'In-app announcement withdrawn. Email and SMS already submitted are unaffected.');
    }
}
