<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\BadgeTemplatePurchase;
use App\Services\Badges\BadgeTemplatePurchasePayload;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class BadgeTemplateReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        $email = app(PlatformAttendeeVerification::class)->verifiedEmail($request->session());
        if ($email === null) {
            return redirect('/my?return_to='.rawurlencode($request->getPathInfo()))->header('Cache-Control', 'private, no-store');
        }
        $purchases = BadgeTemplatePurchase::query()->whereHas('order', fn ($query) => $query->where('buyer_email', PlatformAttendeeVerification::normalise($email)))
            ->with(['order.receipt.outbox', 'order.attempts', 'licence', 'event' => fn ($query) => $query->withTrashed()->with('tenant'), 'version'])->withExists('uses')->latest()->orderByDesc('id')->paginate(20);
        $purchases->through(fn (BadgeTemplatePurchase $purchase): array => app(BadgeTemplatePurchasePayload::class)->forPurchase($purchase));
        $response = $request->expectsJson() ? response()->json(['purchases' => $purchases])
            : Inertia::render('Public/Marketplace/BadgeTemplatePurchases', ['purchases' => $purchases])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function show(Request $request, string $purchase): Response
    {
        $email = app(PlatformAttendeeVerification::class)->verifiedEmail($request->session());
        if ($email === null) {
            return redirect('/my?return_to='.rawurlencode($request->getPathInfo()))->header('Cache-Control', 'private, no-store');
        }
        $purchase = BadgeTemplatePurchase::query()->whereHas('order', fn ($query) => $query->where('buyer_email', PlatformAttendeeVerification::normalise($email)))->findOrFail($purchase);
        $payload = app(BadgeTemplatePurchasePayload::class)->forPurchase($purchase);
        $response = $request->expectsJson() ? response()->json($payload)
            : Inertia::render('Public/Marketplace/BadgeTemplateReceipt', ['purchase' => $payload])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
