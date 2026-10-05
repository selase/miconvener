<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\StoreListing;
use App\Services\Moderation\ContentTakedown;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Superadmin takedown and restore of an event or marketplace listing.
 * See ContentTakedown.
 */
final class TakedownController extends Controller
{
    public function store(Request $request, string $type, string $id, ContentTakedown $takedown): RedirectResponse
    {
        $this->authorize('access-superadmin-dashboard');

        $validated = $request->validate(
            ['reason' => ['required', 'string', 'min:5', 'max:1000']],
            ['reason.required' => 'Say why it is being taken down; the organiser will see this reason.', 'reason.min' => 'Say why it is being taken down; the organiser will see this reason.'],
        );

        $item = $this->find($type, $id);
        $takedown->takeDown($item, $request->user(), $validated['reason']);

        return back()->with('success', 'Taken down. It is hidden from the public and its owner cannot republish it.');
    }

    public function destroy(Request $request, string $type, string $id, ContentTakedown $takedown): RedirectResponse
    {
        $this->authorize('access-superadmin-dashboard');

        $takedown->restore($this->find($type, $id), $request->user());

        return back()->with('success', 'Restored to the status it had before.');
    }

    private function find(string $type, string $id): Event|StoreListing
    {
        return match ($type) {
            'event' => Event::query()->withoutGlobalScopes()->findOrFail($id),
            'listing' => StoreListing::query()->withoutGlobalScopes()->findOrFail($id),
            default => abort(404),
        };
    }
}
