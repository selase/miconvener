<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Http\Requests\Tenant\Store\SaveMerchandiseRequest;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\StoreMerchandise;
use App\Models\StoreVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Scopes\MerchandiseVisibilityScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class MerchandiseCatalog
{
    public function __construct(private CommerceCatalogRollout $rollout, private MerchandiseInventory $inventory) {}

    public function authorize(Tenant $tenant, User $actor): void
    {
        if (! $this->rollout->baseEnabled($tenant) || (! User::query()->whereKey($actor->id)->where('tenant_id', $tenant->id)->exists() && ! $actor->tenants()->whereKey($tenant->id)->exists())) {
            throw new AuthorizationException;
        }
        $team = getPermissionsTeamId();
        try {
            setPermissionsTeamId($tenant->id);
            Gate::forUser($actor->fresh())->authorize('manage store catalog');
        } finally {
            setPermissionsTeamId($team);
        }
        if ($tenant->shop()->where('is_active', false)->exists()) {
            throw new AuthorizationException('This store is inactive.');
        }
    }

    /** @param array<string,mixed> $input */
    public function save(Tenant $tenant, User $actor, ?StoreListing $listing, array $input): StoreListing
    {
        $this->authorize($tenant, $actor);
        $data = Validator::make($input, SaveMerchandiseRequest::catalogRules())->validate();
        try {
            return $tenant->getConnection()->transaction(function () use ($tenant, $actor, $listing, $data): StoreListing {
                $lockedTenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
                $this->authorize($lockedTenant, $actor);
                $shop = $lockedTenant->shop()->first();
                if ($shop === null) {
                    $slug = Shop::query()->where('slug', $lockedTenant->slug)->exists() ? $lockedTenant->slug.'-'.mb_substr($lockedTenant->id, 0, 8) : $lockedTenant->slug;
                    $shop = Shop::query()->create(['tenant_id' => $lockedTenant->id, 'name' => $lockedTenant->name,
                        'slug' => $slug, 'email' => $lockedTenant->email ?? '', 'phone' => $lockedTenant->phone_number ?? '',
                        'address' => $lockedTenant->address ?? '', 'city' => $lockedTenant->city ?? '', 'region' => $lockedTenant->state ?? '', 'is_active' => true]);
                }
                $current = $listing === null ? new StoreListing(['shop_id' => $shop->id, 'listing_kind' => StoreListing::KIND_MERCHANDISE,
                    'category' => StoreListing::CATEGORY_OTHER, 'status' => StoreListing::STATUS_DRAFT, 'audience' => StoreListing::AUDIENCE_PUBLIC, 'is_bookable' => false])
                    : $this->owned($lockedTenant, $listing->id)->lockForUpdate()->firstOrFail();
                if (StoreListing::withoutGlobalScope(MerchandiseVisibilityScope::class)->where('slug', $data['slug'])->when($current->exists, fn ($query) => $query->whereKeyNot($current->id))->exists()) {
                    throw ValidationException::withMessages(['slug' => 'Choose another item address.']);
                }
                $current->fill(['title' => $data['title'], 'slug' => $data['slug'], 'description' => $data['description'] ?? null])->save();
                $details = StoreMerchandise::query()->firstOrNew(['listing_id' => $current->id], ['shop_id' => $shop->id]);
                $details->fill(['order_mode' => $data['order_mode'] ?? $details->order_mode ?? 'stock', 'enquiry_phone' => array_key_exists('enquiry_phone', $data) ? $data['enquiry_phone'] : $details->enquiry_phone, 'visibility' => $data['visibility'], 'currency' => $data['currency'], 'collection_instructions' => $data['collection_instructions'] ?? null])->save();
                $this->saveVariants($current, $data['variants'], $actor);
                if ($current->isPublished()) {
                    $this->assertPublishable($current);
                }

                return $current->refresh()->load('merchandise');
            });
        } catch (QueryException $error) {
            if ($error->getCode() === '23505') {
                throw ValidationException::withMessages(['variants' => 'Item addresses, seller SKUs and option combinations must be unique.']);
            }
            throw $error;
        }
    }

    public function publish(Tenant $tenant, User $actor, StoreListing $listing): StoreListing
    {
        return $this->changeState($tenant, $actor, $listing, 'publish');
    }

    public function archive(Tenant $tenant, User $actor, StoreListing $listing): StoreListing
    {
        return $this->changeState($tenant, $actor, $listing, 'archive');
    }

    public function draft(Tenant $tenant, User $actor, StoreListing $listing): StoreListing
    {
        return $this->changeState($tenant, $actor, $listing, 'draft');
    }

    /** @return \Illuminate\Database\Eloquent\Builder<StoreListing> */
    public function owned(Tenant $tenant, ?string $id = null): \Illuminate\Database\Eloquent\Builder
    {
        return StoreListing::withoutGlobalScope(MerchandiseVisibilityScope::class)->where('listing_kind', StoreListing::KIND_MERCHANDISE)
            ->whereHas('shop', fn ($shop) => $shop->where('tenant_id', $tenant->id))
            ->when($id !== null, fn ($query) => $query->whereKey($id));
    }

    private function changeState(Tenant $tenant, User $actor, StoreListing $listing, string $state): StoreListing
    {
        $this->authorize($tenant, $actor);

        return $tenant->getConnection()->transaction(function () use ($tenant, $actor, $listing, $state): StoreListing {
            $lockedTenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $this->authorize($lockedTenant, $actor);
            $current = $this->owned($lockedTenant, $listing->id)->lockForUpdate()->firstOrFail();
            if ($state === 'archive') {
                $current->merchandise()->firstOrFail()->update(['archived_at' => now()]);
            } elseif ($state === 'draft') {
                $current->merchandise()->firstOrFail()->update(['archived_at' => null]);
                $current->update(['status' => $current->taken_down_at !== null ? StoreListing::STATUS_SUSPENDED : StoreListing::STATUS_DRAFT]);
            } else {
                abort_if($current->taken_down_at !== null, 403, 'This item has been taken down by the platform.');
                $this->assertPublishable($current);
                $current->update(['status' => StoreListing::STATUS_PUBLISHED]);
            }

            return $current->refresh()->load('merchandise');
        });
    }

    private function assertPublishable(StoreListing $listing): void
    {
        $details = $listing->merchandise()->first();
        if ($details === null || $details->archived_at !== null || ! in_array($details->order_mode, ['stock', 'made_to_order'], true)
            || ($details->order_mode === 'made_to_order' && ! preg_match('/^\+[1-9][0-9]{7,14}$/D', $details->enquiry_phone ?? ''))
            || ($details->order_mode === 'stock' && blank($details->collection_instructions) && ! $this->hasFulfilmentPolicy($listing))
            || ! $listing->variants()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['publication' => 'Add at least one active variant and collection instructions, or a valid enquiry phone for made-to-order items. Archived items cannot be published.']);
        }
    }

    private function hasFulfilmentPolicy(StoreListing $listing): bool
    {
        $policy = \App\Models\StoreFulfilmentPolicy::query()->where('shop_id', $listing->shop_id)->orderByDesc('version')->first();

        return $policy !== null && count($policy->snapshot['methods'] ?? []) > 0;
    }

    /** @param list<array<string,mixed>> $variants */
    private function saveVariants(StoreListing $listing, array $variants, User $actor): void
    {
        $skus = [];
        $options = [];
        $kept = [];
        foreach ($variants as $index => $data) {
            $sku = mb_strtoupper(mb_trim($data['sku']));
            $key = StoreVariant::optionKey($data['options']);
            if (in_array($sku, $skus, true) || in_array($key, $options, true)) {
                throw ValidationException::withMessages(['variants' => 'Each variant needs a distinct SKU and option combination.']);
            }
            $skus[] = $sku;
            $options[] = $key;
            $variant = isset($data['id']) ? $listing->variants()->whereKey($data['id'])->firstOrFail()
                : new StoreVariant(['listing_id' => $listing->id, 'shop_id' => $listing->shop_id]);
            if ($variant->exists && array_key_exists('initial_stock', $data)) {
                throw ValidationException::withMessages(["variants.{$index}.initial_stock" => 'Use an audited stock correction to change existing stock.']);
            }
            $new = ! $variant->exists;
            $variant->fill(['sku' => $sku, 'options' => $data['options'], 'price_pesewas' => $data['price_pesewas'], 'is_active' => $data['is_active']])->save();
            $kept[] = $variant->id;
            if ($new && ($data['initial_stock'] ?? 0) > 0) {
                if (empty($data['idempotency_key'])) {
                    throw ValidationException::withMessages(["variants.{$index}.idempotency_key" => 'Provide an opening-stock adjustment key.']);
                }
                $this->inventory->adjust($variant, (int) $data['initial_stock'], 'Opening stock', $data['idempotency_key'], $actor);
            }
        }
        $listing->variants()->whereNotIn('id', $kept)->update(['is_active' => false]);
    }
}
