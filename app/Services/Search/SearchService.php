<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Invoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final readonly class SearchService
{
    public function __construct(
        private TenantContext $tenantContext,
    ) {}

    /**
     * @return Collection<int, SearchResult>
     */
    public function search(string $query): Collection
    {
        if (mb_strlen($query) < 2) {
            return collect();
        }

        $results = collect();
        $tenant = $this->tenantContext->getTenant();

        // 1. Search Users
        // Users are typically scoped by tenant via relationships or if they belong to the tenant
        if ($tenant instanceof \App\Models\Tenant) {
            // Search Tenant Users
            $users = $tenant->users()
                ->where(function ($q) use ($query): void {
                    $q->where('name', 'like', "%{$query}%")
                        ->orWhere('email', 'like', "%{$query}%");
                })
                ->take(5)
                ->get();

            foreach ($users as $user) {
                $results->push(new SearchResult(
                    title: $user->name,
                    url: route('tenant.users.show', $user), // Adjust route name if needed
                    type: 'User',
                    description: $user->email,
                    icon: 'user'
                ));
            }

            // 2. Search Invoices
            $invoices = Invoice::where('tenant_id', $tenant->id)
                ->where('number', 'like', "%{$query}%")
                ->take(5)
                ->get();

            foreach ($invoices as $invoice) {
                $results->push(new SearchResult(
                    title: $invoice->number,
                    url: route('billing.invoices.show', $invoice),
                    type: 'Invoice',
                    description: 'Amount: '.$invoice->total.' '.$invoice->currency,
                    icon: 'file-text'
                ));
            }

            // 3. Navigation Shortcuts (Static)
            $this->addNavigationShortcuts($results, $query);
        }

        return $results;
    }

    private function addNavigationShortcuts(Collection $results, string $query): void
    {
        $shortcuts = [
            ['title' => 'Dashboard', 'url' => route('tenant.dashboard'), 'keywords' => 'home dashboard'],
            ['title' => 'Billing', 'url' => route('billing.index'), 'keywords' => 'finance billing payments'],
            ['title' => 'Settings', 'url' => route('tenant.settings.index'), 'keywords' => 'config settings configuration'],
            ['title' => 'Users', 'url' => route('tenant.users.index'), 'keywords' => 'people team members users'],
        ];

        foreach ($shortcuts as $shortcut) {
            if (Str::contains(mb_strtolower($shortcut['keywords']), mb_strtolower($query)) ||
                Str::contains(mb_strtolower($shortcut['title']), mb_strtolower($query))) {

                $results->push(new SearchResult(
                    title: $shortcut['title'],
                    url: $shortcut['url'],
                    type: 'Page',
                    description: 'Go to '.$shortcut['title'],
                    icon: 'arrow-right'
                ));
            }
        }
    }
}
