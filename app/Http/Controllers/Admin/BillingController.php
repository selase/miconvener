<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\Transaction;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class BillingController extends Controller
{
    /**
     * Display a global list of all transactions.
     */
    public function transactions(Request $request): View
    {
        $this->authorize('access-superadmin-dashboard');

        $query = Transaction::with('tenant')
            ->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('transaction_id', 'like', "%{$search}%")
                    ->orWhereHas('tenant', function ($q) use ($search): void {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $transactions = $query->paginate(15)->withQueryString();

        return view('admin.billing.transactions.index', [
            'transactions' => $transactions,
        ]);
    }

    /**
     * Display a global list of all subscriptions.
     */
    public function subscriptions(Request $request): View
    {
        $this->authorize('access-superadmin-dashboard');

        $query = Subscription::with(['tenant'])
            ->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('tenant', function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $subscriptions = $query->paginate(15)->withQueryString();

        return view('admin.billing.subscriptions.index', [
            'subscriptions' => $subscriptions,
        ]);
    }
}
