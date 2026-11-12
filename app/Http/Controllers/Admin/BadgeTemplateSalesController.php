<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BadgeTemplateFinanceRequest;
use App\Models\BadgeTemplatePurchase;
use App\Models\StorePaymentAttempt;
use App\Models\StoreRefund;
use App\Services\Badges\BadgeTemplateRefunds;
use App\Services\Marketplace\StorePaymentNotifications;
use App\Services\Marketplace\StoreRefunds;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class BadgeTemplateSalesController extends Controller
{
    public function index(): View
    {
        app(BadgeTemplateRefunds::class)->authorize(request()->user());
        $purchases = BadgeTemplatePurchase::query()->with(['event' => fn ($query) => $query->withTrashed(), 'licence', 'order.attempts.observations', 'order.refunds'])->withExists('uses')->latest()->paginate(20);

        return view('admin.badge-template-sales', compact('purchases'));
    }

    public function update(BadgeTemplateFinanceRequest $request, string $purchase): RedirectResponse
    {
        app(BadgeTemplateRefunds::class)->authorize($request->user());
        $purchase = BadgeTemplatePurchase::query()->findOrFail($purchase);
        $action = $request->validated('action');
        if ($action === 'refund') {
            app(StoreRefunds::class)->request($purchase->order, $request->user(), $request->validated('operation_key'), $request->validated('reason'), $request->validated('capture_id'), $request->boolean('exception'));
        } elseif ($action === 'verify') {
            $attempt = StorePaymentAttempt::query()->where('order_id', $purchase->order_id)->findOrFail($request->validated('attempt_id'));
            app(StorePaymentNotifications::class)->reconcile($attempt);
        } else {
            $refund = StoreRefund::query()->where('order_id', $purchase->order_id)->findOrFail($request->validated('refund_id'));
            if ($action === 'restore') {
                app(BadgeTemplateRefunds::class)->restore($refund, $request->user(), $request->validated('operation_key'), $request->validated('reason'));
            } else {
                if ($refund->submitted_at === null && $refund->status === 'needs_funding') {
                    app(StoreRefunds::class)->request($purchase->order, $request->user(), $refund->operation_key, $refund->reason, $refund->observation_id);
                } else {
                    app(StoreRefunds::class)->reconcile($refund);
                }
            }
        }

        return back()->with('success', 'The recorded status has been refreshed. Review the result before taking another action.');
    }
}
