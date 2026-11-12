<?php

declare(strict_types=1);

namespace App\Services\Badges;

use App\Models\BadgeDesignProvenance;
use App\Models\BadgeLibraryVersion;
use App\Models\BadgeTemplateLicence;
use App\Models\BadgeTemplateUse;
use App\Models\Event;
use App\Models\EventBadgeTemplate;
use App\Models\User;
use App\Services\Events\EventStaffScope;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BadgeTemplateAccess
{
    /** @return list<string> */
    public function families(string $type, string $id): array
    {
        $ids = BadgeDesignProvenance::query()->where('source_type', $type)->where('source_id', $id)->pluck('family_id')->all();
        if ($type === 'version') {
            $version = BadgeLibraryVersion::query()->with('design')->whereKey($id)->firstOrFail();
            if ($version->design->price_pesewas > 0) {
                $ids[] = $version->design_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    public function eventFamilies(Event $event): array
    {
        $ids = $this->families('event', $event->id);
        $source = EventBadgeTemplate::withoutGlobalScopes()->where('event_id', $event->id)->value('source_version_id');
        if (is_string($source)) {
            $ids = [...$ids, ...$this->families('version', $source)];
        }

        return array_values(array_unique($ids));
    }

    /** @param list<string> $familyIds */
    public function attach(string $type, string $id, array $familyIds): void
    {
        foreach (array_unique($familyIds) as $familyId) {
            BadgeDesignProvenance::query()->firstOrCreate(['source_type' => $type, 'source_id' => $id, 'family_id' => $familyId]);
        }
    }

    /** @param list<string> $familyIds */
    public function assertLicensed(Event $event, array $familyIds): void
    {
        foreach (array_unique($familyIds) as $familyId) {
            $licence = BadgeTemplateLicence::query()->where('buyer_tenant_id', $event->tenant_id)->where('event_id', $event->id)
                ->where('family_id', $familyId)->when($event->getConnection()->transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first();
            if ($licence === null || $licence->state !== 'active' || $licence->current_purchase_id === null
                || $licence->currentPurchase?->state !== 'paid') {
                $this->refuse();
            }
        }
    }

    /**
     * @param  list<string>  $familyIds
     * @param  array<string, mixed>  $context
     * @return array{uses: list<string>, template: EventBadgeTemplate}
     */
    public function claimUse(Event $event, User $actor, array $familyIds, string $purpose, string $outputKey, array $context = []): array
    {
        if (! Str::isUuid($outputKey) || ! in_array($purpose, ['clean_preview', 'artwork_download', 'badge_pdf', 'production_pack'], true)) {
            $this->refuse();
        }

        return $event->getConnection()->transaction(function () use ($event, $actor, $familyIds, $purpose, $outputKey, $context): array {
            $event = Event::query()->whereKey($event->id)->lock('for no key update')->firstOrFail();
            $template = app(BadgeTemplateService::class)->forEvent($event);
            $template = EventBadgeTemplate::withoutGlobalScopes()->where('event_id', $event->id)->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $event);
            $families = array_values(array_unique([...$familyIds, ...$this->eventFamilies($event)]));
            sort($families, SORT_STRING);
            $uses = [];
            $snapshot = $template->only([...BadgeLibraryService::FIELDS, 'design_version']);
            $hash = $this->fingerprint([$snapshot, $context]);
            foreach ($families as $familyId) {
                $licence = BadgeTemplateLicence::query()->where('buyer_tenant_id', $event->tenant_id)->where('event_id', $event->id)
                    ->where('family_id', $familyId)->lockForUpdate()->first();
                if ($licence === null || $licence->state !== 'active' || $licence->currentPurchase?->state !== 'paid') {
                    $this->refuse();
                }
                $prior = BadgeTemplateUse::query()->where('licence_id', $licence->id)->where('output_key', $outputKey)->first();
                if ($prior !== null) {
                    if ((string) $prior->actor_id !== (string) $actor->id || $prior->purpose !== $purpose || ! hash_equals($prior->snapshot_hash, $hash)) {
                        throw ValidationException::withMessages(['output' => 'This output request has changed. Review and request it again.']);
                    }
                    $uses[] = $prior->id;

                    continue;
                }
                $use = BadgeTemplateUse::query()->create(['licence_id' => $licence->id, 'purchase_id' => $licence->current_purchase_id,
                    'actor_id' => $actor->id, 'version_id' => $template->source_version_id ?? $licence->currentPurchase->version_id,
                    'purpose' => $purpose, 'output_key' => $outputKey, 'snapshot_hash' => $hash, 'render_snapshot' => $snapshot]);
                if ($licence->first_used_at === null) {
                    $licence->update(['first_used_at' => now()]);
                    \App\Models\BadgeTemplateLicenceEvent::query()->create(['licence_id' => $licence->id, 'purchase_id' => $licence->current_purchase_id, 'actor_id' => $actor->id, 'action' => 'first_used', 'operation_key' => $use->id, 'evidence' => ['use_id' => $use->id, 'purpose' => $purpose, 'snapshot_hash' => $hash]]);
                }
                $uses[] = $use->id;
            }

            return ['uses' => $uses, 'template' => $template];
        });
    }

    public function cleanEnabled(Event $event): bool
    {
        foreach ($this->eventFamilies($event) as $familyId) {
            $licence = BadgeTemplateLicence::query()->where('event_id', $event->id)->where('buyer_tenant_id', $event->tenant_id)->where('family_id', $familyId)->first();
            if ($licence === null || $licence->state !== 'active' || $licence->first_used_at === null || $licence->currentPurchase?->state !== 'paid') {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $useIds */
    public function assertOutputClaim(Event $event, EventBadgeTemplate $template, array $useIds): void
    {
        $families = $this->eventFamilies($event);
        if (is_string($template->source_version_id)) {
            $families = array_unique([...$families, ...$this->families('version', $template->source_version_id)]);
        }
        $snapshot = $template->only([...BadgeLibraryService::FIELDS, 'design_version']);
        foreach ($families as $familyId) {
            $use = BadgeTemplateUse::query()->whereIn('id', $useIds)->whereIn('purpose', ['badge_pdf', 'production_pack'])
                ->whereHas('licence', fn ($query) => $query->where('event_id', $event->id)->where('buyer_tenant_id', $event->tenant_id)->where('family_id', $familyId)->where('state', 'active')->whereColumn('current_purchase_id', 'badge_template_uses.purchase_id'))->first();
            if ($use === null || ! hash_equals($this->fingerprint($use->render_snapshot), $this->fingerprint($snapshot)) || $use->purchase->state !== 'paid') {
                $this->refuse();
            }
            $actor = User::withoutGlobalScopes()->whereKey($use->actor_id)->firstOrFail();
            if (auth()->check() && (string) auth()->id() !== (string) $actor->id) {
                $this->refuse();
            }
            $this->authorize($actor, $event);
        }
    }

    public function authorize(User $actor, Event $event): void
    {
        $actor = User::withoutGlobalScopes()->whereKey($actor->id)->firstOrFail();
        abort_unless(($actor->status === null || $actor->status === User::STATUS_ACTIVE)
            && ($actor->isGlobalSuperAdmin() || $actor->tenants()->whereKey($event->tenant_id)->exists()), 403);
        $previous = getPermissionsTeamId();
        try {
            setPermissionsTeamId($event->tenant_id);
            abort_unless($actor->can('read badge-template') && EventStaffScope::canAccessEvent($actor, $event), 403);
        } finally {
            setPermissionsTeamId($previous);
        }
    }

    /** @param array<array-key, mixed> $data */
    private function fingerprint(array $data): string
    {
        $canonical = function (array $value) use (&$canonical): array {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn (mixed $part): mixed => is_array($part) ? $canonical($part) : $part, $value);
        };

        return hash('sha256', json_encode($canonical($data), JSON_THROW_ON_ERROR));
    }

    private function refuse(): never
    {
        throw ValidationException::withMessages(['licence' => 'An active paid design licence for this event is required for clean output.']);
    }
}
