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

    /** @return array<string,string> */
    public function keys(string $name, ?string $phone, ?string $email): array
    {
        $phone = $this->normalisePhone($phone);
        $email = $this->normaliseEmail($email);
        $keys = [];
        if ($phone !== null) {
            $keys['person'] = 'person:'.Str::lower(Str::squish($name)).':'.$phone;
            $keys['phone'] = 'phone:'.$phone;
        }
        if ($email !== null) {
            $keys['email'] = 'email:'.$email;
        }

        return $keys;
    }

    /** @param array<string,list<string>|string> $index
     * @return list<string>
     */
    public function matchingIds(array $index, string $name, ?string $phone, ?string $email): array
    {
        $matches = [];
        foreach ($this->keys($name, $phone, $email) as $key) {
            foreach ((array) ($index[$key] ?? []) as $id) {
                $matches[] = $id;
            }
        }

        return array_values(array_unique($matches));
    }

    /** @param array<string,list<string>|string> $index */
    public function matchingId(array $index, string $name, ?string $phone, ?string $email): ?string
    {
        return $this->matchingIds($index, $name, $phone, $email)[0] ?? null;
    }

    /** @return array{index:array<string,list<string>>,records:array<string,array<string,mixed>>} */
    public function register(Tenant $tenant, ?string $ignoreId = null): array
    {
        $register = ['index' => [], 'records' => []];
        foreach (Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->select(['id', 'name', 'phone', 'email', 'access_version', 'status', 'membership_type_id'])->cursor() as $member) {
            $record = $member->toArray();
            $record['status'] = $member->status->value;
            $this->addToRegister($register, $record);
        }

        return $register;
    }

    /** @param array{index:array<string,list<string>>,records:array<string,array<string,mixed>>} $register
     * @param  array<string,mixed>  $record
     */
    public function addToRegister(array &$register, array $record): void
    {
        $register['records'][$record['id']] = $record;
        foreach ($this->keys($record['name'], $record['phone'] ?? null, $record['email'] ?? null) as $key) {
            $register['index'][$key][] = $record['id'];
            $register['index'][$key] = array_values(array_unique($register['index'][$key]));
        }
    }

    /** @return array<string,list<string>> */
    public function index(Tenant $tenant, ?string $ignoreId = null): array
    {
        return $this->register($tenant, $ignoreId)['index'];
    }

    /** Staff-only callers; no public identity lookup.
     * @return list<array<string,mixed>>
     */
    public function candidates(Tenant $tenant, string $name, ?string $phone, ?string $email): array
    {
        return $this->candidatesIn($this->register($tenant), $name, $phone, $email);
    }

    /** @param array{index:array<string,list<string>>,records:array<string,array<string,mixed>>} $register
     * @return list<array<string,mixed>>
     */
    public function candidatesIn(array $register, string $name, ?string $phone, ?string $email): array
    {
        $matches = [];
        foreach ($this->keys($name, $phone, $email) as $reason => $key) {
            foreach ($register['index'][$key] ?? [] as $id) {
                $matches[$id] ??= [...$register['records'][$id], 'reasons' => []];
                $matches[$id]['reasons'][] = $reason;
            }
        }
        ksort($matches);
        foreach ($matches as &$match) {
            sort($match['reasons']);
        }

        return array_values($matches);
    }

    /** @param array<string,mixed> $data */
    public function duplicateRevision(Tenant $tenant, array $data, ?string $ignoreId = null): string
    {
        return hash_hmac('sha256', json_encode([$tenant->id, (int) auth()->id(), $ignoreId, $this->normaliseEmail($data['email'] ?? null), $this->normalisePhone($data['phone'] ?? null), Str::lower(Str::squish($data['name'])),
            $this->candidatesIn($this->register($tenant, $ignoreId), $data['name'], $data['phone'] ?? null, $data['email'] ?? null)], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function isDuplicate(Tenant $tenant, string $name, ?string $phone, ?string $email, ?string $ignoreId = null): bool
    {
        return $this->matchingIds($this->index($tenant, $ignoreId), $name, $phone, $email) !== [];
    }

    /** @param array<string,mixed> $data */
    public function assertUnique(Tenant $tenant, array $data, ?string $ignoreId = null): void
    {
        $candidates = $this->candidatesIn($this->register($tenant, $ignoreId), $data['name'], $data['phone'] ?? null, $data['email'] ?? null);
        if ($candidates === []) {
            return;
        }
        foreach ($candidates as $candidate) {
            if (in_array('email', $candidate['reasons'], true)) {
                throw ValidationException::withMessages(['email' => 'This email is already recorded for another member. Review that membership instead of creating a duplicate.']);
            }
        }
        if (filter_var($data['confirm_distinct'] ?? false, FILTER_VALIDATE_BOOLEAN) && is_string($data['duplicate_revision'] ?? null)
            && hash_equals($this->duplicateRevision($tenant, $data, $ignoreId), $data['duplicate_revision'])) {
            return;
        }
        throw ValidationException::withMessages(['phone' => 'Possible matching members need review. Confirm a distinct person only after checking the existing records.']);
    }
}
