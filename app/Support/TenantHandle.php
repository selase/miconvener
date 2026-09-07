<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * A tenant's handle is the subdomain its organizers and attendees reach it on,
 * so it is printed on tickets, shared in messages and read aloud. That rules out
 * an opaque generated id, which is short and predictable but costs the organizer
 * every bit of recognition the URL could have earned them.
 *
 * It also cannot be the organization's name slugged without limit: "Ghana
 * Association of Event Planners" becomes a thirty-six character subdomain that
 * nobody will type twice.
 *
 * So: the organizer chooses, within constraints that keep handles short, valid
 * as a DNS label, and clear of the hostnames the platform needs for itself.
 */
final class TenantHandle
{
    public const int MIN_LENGTH = 3;

    public const int MAX_LENGTH = 30;

    /**
     * Hostnames that share the domain with tenants and must never be claimable,
     * either because the platform already serves them or because handing one to a
     * tenant would let them impersonate the platform to their own attendees.
     *
     * @var list<string>
     */
    public const array RESERVED = [
        'admin', 'api', 'app', 'assets', 'auth', 'billing', 'blog', 'cdn',
        'dashboard', 'dev', 'docs', 'ftp', 'help', 'imap', 'localhost', 'login',
        'mail', 'me', 'media', 'ns1', 'ns2', 'pay', 'payment', 'payments',
        'portal', 'register', 'root', 'secure', 'security', 'signup', 'smtp',
        'staging', 'static', 'status', 'support', 'system', 'test', 'tickets',
        'webhook', 'webhooks', 'www',
    ];

    /**
     * The validation rules a submitted handle must satisfy.
     *
     * The pattern enforces a valid DNS label: lowercase alphanumerics separated
     * by single hyphens, never leading, trailing or doubled.
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return [
            'required',
            'string',
            'min:'.self::MIN_LENGTH,
            'max:'.self::MAX_LENGTH,
            'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
            'not_in:'.implode(',', self::RESERVED),
            'unique:landlord.tenants,slug',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'slug.regex' => 'Use lowercase letters, numbers and single hyphens only — no spaces, and it cannot start or end with a hyphen.',
            'slug.not_in' => 'That address is reserved. Please choose another.',
            'slug.unique' => 'That address is already taken. Please choose another.',
            'slug.min' => 'Your address must be at least :min characters.',
            'slug.max' => 'Your address must be :max characters or fewer.',
        ];
    }

    /**
     * Propose a handle from an organization name, so most organizers never have
     * to think about this field at all.
     *
     * Falls back to a short generated handle when the name yields nothing usable
     * -- a name written entirely in non-Latin characters, for instance.
     */
    public static function suggestFrom(string $organizationName): string
    {
        $base = self::trimToLabel(Str::slug($organizationName));

        if (mb_strlen($base) < self::MIN_LENGTH) {
            $base = 'org-'.mb_strtolower(Str::random(6));
        }

        return self::makeUnique($base);
    }

    /**
     * Append a numeric suffix until the handle is free, keeping the result within
     * the length limit rather than growing past it.
     */
    public static function makeUnique(string $base): string
    {
        $base = self::trimToLabel($base);
        $candidate = $base;
        $suffix = 2;

        while (self::isTaken($candidate)) {
            $tail = '-'.$suffix;
            $candidate = self::trimToLabel(mb_substr($base, 0, self::MAX_LENGTH - mb_strlen($tail))).$tail;
            $suffix++;
        }

        return $candidate;
    }

    public static function isReserved(string $handle): bool
    {
        return in_array(mb_strtolower($handle), self::RESERVED, true);
    }

    private static function isTaken(string $handle): bool
    {
        return self::isReserved($handle) || Tenant::where('slug', $handle)->exists();
    }

    /**
     * Cut to the maximum length without leaving a trailing hyphen, which would be
     * an invalid DNS label.
     */
    private static function trimToLabel(string $value): string
    {
        return mb_trim(mb_substr($value, 0, self::MAX_LENGTH), '-');
    }
}
