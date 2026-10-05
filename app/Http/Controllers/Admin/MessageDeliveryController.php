<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Notifications\MessageDeliverySearch;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * "Did this person get our message?" Search one recipient by email or phone
 * across every organisation. See MessageDeliverySearch.
 */
final class MessageDeliveryController extends Controller
{
    public function index(Request $request, MessageDeliverySearch $search): View
    {
        $this->authorize('access-superadmin-dashboard');

        $term = mb_trim((string) $request->query('q', ''));

        return view('admin.messages.index', [
            'term' => $term,
            'results' => $term === '' ? null : $search->find($term),
        ]);
    }
}
