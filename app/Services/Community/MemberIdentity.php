<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\Member;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MemberIdentity
{
    public function normalisePhone(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }
        $phone = preg_replace('/[\s().-]+/', '', mb_trim($phone)) ?? '';
        if (preg_match('/^0[1-9][0-9]{8}$/D', $phone)) {
            return '+233'.mb_substr($phone, 1);
        }
        if (preg_match('/^(?:\+|00)?233[1-9][0-9]{8}$/D', $phone)) {
            return '+'.preg_replace('/^(?:\+|00)/', '', $phone);
        }
        if (! preg_match('/^(?:\+|00)?233/', $phone) && preg_match('/^(?:\+|00)[1-9][0-9]{9,14}$/D', $phone)) {
            return '+'.preg_replace('/^(?:\+|00)/', '', $phone);
        }

        return null;
    }

    public function normaliseEmail(?string $email): ?string
    {
        return blank($email) ? null : Str::lower(mb_trim($email));
    }

    /** @return array<string, string> */
    public function keys(string $name, ?string $phone, ?string $email): array
    {
        $phone = filled($phone) ? ($this->normalisePhone($phone) ?? Str::lower(Str::squish($phone))) : null;
        $email = $this->normaliseEmail($email);
        $keys = [];
        if ($phone !== null) {
            $keys['person'] = 'phone:'.Str::lower(Str::squish($name)).':'.$phone;
        } elseif ($email !== null) {
            $keys['email'] = 'email:'.$email;
        }

        return $keys;
    }

    /** @param array<string, string> $index */
    public function matchingId(array $index, string $name, ?string $phone, ?string $email): ?string
    {
        return $this->matchingIds($index, $name, $phone, $email)[0] ?? null;
    }

    /**
     * @param  array<string, string>  $index
     * @return list<string>
     */
    public function matchingIds(array $index, string $name, ?string $phone, ?string $email): array
    {
        $matches = [];
        $email = $this->normaliseEmail($email);
        if ($email !== null && isset($index['email:'.$email])) {
            $matches[] = $index['email:'.$email];
        }
        $key = $this->keys($name, $phone, $email)['person'] ?? null;
        if ($key !== null && isset($index[$key])) {
            $matches[] = $index[$key];
        }

        return array_values(array_unique($matches));
    }

    /** @return array<string, string> */
    public function index(Tenant $tenant, ?string $ignoreId = null): array
    {
        $index = [];
        foreach (Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->select(['id', 'name', 'phone', 'email'])->cursor() as $member) {
            foreach ($this->keys($member->name, $member->phone, $member->email) as $key) {
                $index[$key] = $member->id;
            }
        }

        return $index;
    }

    public function isDuplicate(Tenant $tenant, string $name, ?string $phone, ?string $email, ?string $ignoreId = null): bool
    {
        return $this->matchingId($this->index($tenant, $ignoreId), $name, $phone, $email) !== null;
    }

    /** @param array<string, mixed> $data */
    public function assertUnique(Tenant $tenant, array $data, ?string $ignoreId = null): void
    {
        $index = $this->index($tenant, $ignoreId);
        if ($this->matchingId($index, $data['name'], $data['phone'] ?? null, $data['email'] ?? null) !== null) {
            $email = $this->normaliseEmail($data['email'] ?? null);
            $field = $email !== null && isset($index['email:'.$email]) ? 'email' : 'phone';
            throw ValidationException::withMessages([$field => 'This person is already a member.']);
        }
    }
}
