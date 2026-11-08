<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Libraries\Helper;
use App\Models\Event;
use App\Models\EventHost;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class EventHosts
{
    /** @return list<array{id: string, name: string, type: string, website_url: ?string, logo_url: ?string}> */
    public function payload(Event $event): array
    {
        return $event->hosts->map(fn (EventHost $host): array => [
            'id' => (string) $host->id,
            'name' => $host->name,
            'type' => $host->type,
            'website_url' => $host->website_url,
            'logo_url' => Helper::storageUrl($host->logo_path),
        ])->all();
    }

    /** @param list<array{id?: ?string, name: string, type: string, website_url?: ?string, logo?: ?UploadedFile, remove_logo?: bool}> $hosts */
    public function replace(Event $event, int $version, array $hosts): Event
    {
        $disk = config('app.env') === 'production' ? 's3' : 'public';
        $createdPaths = [];
        try {
            return $event->getConnection()->transaction(function () use ($event, $version, $hosts, $disk, &$createdPaths): Event {
                $locked = Event::query()->where('tenant_id', $event->tenant_id)->lockForUpdate()->findOrFail($event->id);
                abort_if((int) $locked->hosts_version !== $version, 409, 'Hosts changed in another session. Reload the event before saving again.');
                $existing = $locked->hosts()->get()->keyBy('id');
                $kept = [];
                $retired = [];
                foreach ($hosts as $index => $data) {
                    $host = ! empty($data['id']) ? $existing->get($data['id']) : new EventHost(['tenant_id' => $locked->tenant_id, 'event_id' => $locked->id]);
                    if (! $host) {
                        throw ValidationException::withMessages(["hosts.$index.id" => 'Choose a host belonging to this event.']);
                    }
                    $oldPath = $host->logo_path;
                    if (($data['logo'] ?? null) instanceof UploadedFile) {
                        $path = $data['logo']->store("event-hosts/{$locked->tenant_id}/{$locked->id}", $disk);
                        if ($path === false) {
                            throw new RuntimeException('The host logo could not be stored.');
                        }
                        $createdPaths[] = $path;
                        $host->logo_path = $path;
                    } elseif ($data['remove_logo'] ?? false) {
                        $host->logo_path = null;
                    }
                    if ($oldPath && $oldPath !== $host->logo_path) {
                        $retired[] = $oldPath;
                    }
                    $host->fill(['name' => $data['name'], 'type' => $data['type'], 'website_url' => $data['website_url'] ?? null, 'sort_order' => $index])->save();
                    $kept[] = $host->id;
                }
                foreach ($existing as $host) {
                    if (! in_array($host->id, $kept, true)) {
                        if ($host->logo_path) {
                            $retired[] = $host->logo_path;
                        }
                        $host->delete();
                    }
                }
                $locked->hosts_version = $version + 1;
                $locked->save();
                $locked->getConnection()->afterCommit(function () use ($retired, $disk): void {
                    if ($retired !== []) {
                        try {
                            Storage::disk($disk)->delete($retired);
                        } catch (Throwable $exception) {
                            report($exception);
                        }
                    }
                });

                return $locked->load('hosts');
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($createdPaths);
            throw $exception;
        }
    }
}
