<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Store;

use App\Services\Marketplace\CommerceCatalogRollout;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

abstract class CatalogRequest extends FormRequest
{
    final public function authorize(): bool
    {
        $tenant = app(TenantContext::class)->getTenant();

        return $tenant !== null && app(CommerceCatalogRollout::class)->baseEnabled($tenant)
            && (bool) $this->user()?->can('manage store catalog');
    }
}
