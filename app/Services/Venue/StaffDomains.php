<?php

declare(strict_types=1);

namespace App\Services\Venue;

use App\Contracts\DnsTxtResolver;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Email domains an organisation has proved it owns, which make addresses there
 * count as its staff. The proof is a TXT record at `_miconvener-staff.<domain>`
 * holding a token we gave that organisation, so a person cannot claim a domain
 * they do not control. Only a verified domain grants anything, only an exact
 * match counts (`mail.ugmc.example` and `evilugmc.example` are not
 * `ugmc.example`), and a domain proved by one organisation is never another's.
 */
final class StaffDomains
{
    public const string RECORD_PREFIX = '_miconvener-staff.';

    public const string VALUE_PREFIX = 'miconvener-staff-verification=';

    public function __construct(private readonly DnsTxtResolver $dns) {}

    public function matches(Tenant $tenant, string $email): bool
    {
        $email = mb_strtolower(mb_trim($email));

        if (mb_substr_count($email, '@') !== 1) {
            return false;
        }

        $domain = mb_substr($email, (int) mb_strrpos($email, '@') + 1);

        foreach ($tenant->staff_email_domains ?? [] as $entry) {
            if (($entry['verified_at'] ?? null) !== null && mb_strtolower((string) $entry['domain']) === $domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * Start proving a domain. Adding it again returns the same record.
     *
     * @return array{name: string, value: string}
     */
    public function add(Tenant $tenant, string $domain): array
    {
        $domain = $this->normalise($domain);

        if ($this->verifiedElsewhere($tenant, $domain)) {
            throw ValidationException::withMessages(['domain' => 'That domain is already proved by another organisation.']);
        }

        $entries = $tenant->staff_email_domains ?? [];

        foreach ($entries as $entry) {
            if ($entry['domain'] === $domain) {
                return $this->record($entry);
            }
        }

        $entry = ['domain' => $domain, 'token' => Str::random(32), 'verified_at' => null];
        $tenant->update(['staff_email_domains' => [...$entries, $entry]]);

        return $this->record($entry);
    }

    /**
     * Whether the published record matches, and if so the domain is verified.
     */
    public function verify(Tenant $tenant, string $domain): bool
    {
        $domain = $this->normalise($domain);
        $entries = $tenant->staff_email_domains ?? [];

        foreach ($entries as $index => $entry) {
            if ($entry['domain'] !== $domain) {
                continue;
            }

            if ($this->verifiedElsewhere($tenant, $domain)) {
                return false;
            }

            $expected = self::VALUE_PREFIX.$entry['token'];

            if (! in_array($expected, array_map(mb_trim(...), $this->dns->txt(self::RECORD_PREFIX.$domain)), true)) {
                return false;
            }

            $entries[$index]['verified_at'] ??= now()->toIso8601String();
            $tenant->update(['staff_email_domains' => $entries]);

            return true;
        }

        return false;
    }

    public function remove(Tenant $tenant, string $domain): void
    {
        $domain = $this->normalise($domain);

        $tenant->update(['staff_email_domains' => array_values(array_filter(
            $tenant->staff_email_domains ?? [],
            fn (array $entry): bool => $entry['domain'] !== $domain,
        ))]);
    }

    /**
     * @param  array<string, mixed>  $entry  one element of `tenants.staff_email_domains`
     * @return array{name: string, value: string}
     */
    public function record(array $entry): array
    {
        return ['name' => self::RECORD_PREFIX.$entry['domain'], 'value' => self::VALUE_PREFIX.$entry['token']];
    }

    private function normalise(string $domain): string
    {
        $domain = mb_strtolower(mb_trim($domain));

        if (preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) !== 1) {
            throw ValidationException::withMessages(['domain' => 'Enter just the domain, such as ugmc.edu.gh.']);
        }

        return $domain;
    }

    private function verifiedElsewhere(Tenant $tenant, string $domain): bool
    {
        return Tenant::query()
            ->where('id', '!=', $tenant->id)
            ->whereRaw('staff_email_domains @> ?::jsonb', [json_encode([['domain' => $domain]])])
            ->get(['id', 'staff_email_domains'])
            ->contains(fn (Tenant $other): bool => collect($other->staff_email_domains)->contains(fn (array $entry): bool => $entry['domain'] === $domain && ($entry['verified_at'] ?? null) !== null));
    }
}
