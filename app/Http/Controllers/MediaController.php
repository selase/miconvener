<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MediaController extends Controller
{
    /**
     * Allowed upload directory prefixes that can be publicly served.
     *
     * @var array<int, string>
     */
    private const ALLOWED_PREFIXES = [
        'events/',
        'speakers/',
        'event-sponsors/',
        'event-materials/',
        'event-forum/',
        'tenant/',
        'logos/',
        'users/',
    ];

    /**
     * Stream a stored media file with edge caching headers.
     */
    public function show(Request $request): StreamedResponse
    {
        $rawPath = (string) $request->route('path');
        $path = mb_ltrim($rawPath, '/');

        if (str_starts_with($path, 'storage/')) {
            $path = mb_substr($path, 8);
        }

        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '\\')) {
            abort(404);
        }

        $isAllowed = false;
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $isAllowed = true;
                break;
            }
        }

        if (! $isAllowed) {
            abort(404);
        }

        $disk = Event::uploadDisk();

        if (! Storage::disk($disk)->exists($path)) {
            $fallback = $disk === 's3' ? 'public' : 's3';
            if (Storage::disk($fallback)->exists($path)) {
                $disk = $fallback;
            } else {
                abort(404);
            }
        }

        return Storage::disk($disk)->response(
            $path,
            null,
            [
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]
        );
    }
}
