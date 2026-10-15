<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Enum\MemberStatusEnum;
use App\Models\CommunitySettings;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\Import;
use Maatwebsite\Excel\Concerns\WithColumnLimit;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithLimit;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithReadFilter;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

final class MemberImport
{
    public const array HEADERS = ['name', 'phone', 'email', 'membership_type', 'joined_on'];

    public function __construct(private readonly MemberIdentity $identity, private readonly MemberCap $cap) {}

    public function preview(Tenant $tenant, UploadedFile $file): ImportPreview
    {
        $sheet = $this->read($file);
        $headers = array_map(fn (mixed $value): string => Str::lower(mb_trim(mb_ltrim((string) $value, "\xEF\xBB\xBF"))), array_shift($sheet) ?? []);
        while ($headers !== [] && end($headers) === '') {
            array_pop($headers);
        }
        if ($headers !== self::HEADERS) {
            throw ValidationException::withMessages(['file' => 'Use the template headers: '.implode(',', self::HEADERS).'.']);
        }
        if (count($sheet) > 10000) {
            throw ValidationException::withMessages(['file' => 'Import at most 10,000 rows at a time.']);
        }
        $types = $this->types($tenant);
        $register = $this->identity->index($tenant);
        $seen = [];
        $rows = [];
        $summary = ['new' => 0, 'duplicate' => 0, 'existing' => 0, 'error' => 0];
        foreach ($sheet as $offset => $values) {
            if (count(array_filter($values, fn (mixed $value): bool => filled($value))) === 0) {
                continue;
            }
            $data = array_combine(self::HEADERS, array_pad(array_slice($values, 0, 5), 5, null));
            if (Str::lower($file->getClientOriginalExtension()) === 'xlsx' && is_numeric($data['joined_on'])
                && (float) $data['joined_on'] >= 1 && (float) $data['joined_on'] <= 2958465) {
                $data['joined_on'] = Date::excelToDateTimeObject((float) $data['joined_on'])->format('Y-m-d');
            }
            $row = $this->validateRow($data, $offset + 2, $types);
            if ($row['errors'] !== []) {
                $row['status'] = 'error';
            } elseif ($this->identity->matchingId($seen, $row['name'], $row['phone'], $row['email']) !== null) {
                $row['status'] = 'duplicate';
            } else {
                $id = $this->identity->matchingId($register, $row['name'], $row['phone'], $row['email']);
                $row['status'] = $id === null ? 'new' : 'existing';
                $row['existing_id'] = $id;
                foreach ($this->identity->keys($row['name'], $row['phone'], $row['email']) as $key) {
                    $seen[$key] = (string) $row['line'];
                }
            }
            $summary[$row['status']]++;
            $rows[] = $row;
        }
        $token = (string) Str::uuid();
        Cache::put($this->cacheKey($token), ['tenant_id' => $tenant->id, 'user_id' => (int) auth()->id(), 'rows' => $rows], now()->addMinutes(30));

        return new ImportPreview($rows, $summary, $token);
    }

    public function confirm(Tenant $tenant, string $token, string $existingPolicy, int $userId): ImportResult
    {
        abort_unless(in_array($existingPolicy, ['skip', 'update'], true), 422, 'Choose skip or update.');

        return Cache::lock('community.import.lock:'.$token, 180)->block(10, function () use ($tenant, $token, $existingPolicy, $userId): ImportResult {
            $key = $this->cacheKey($token);
            $preview = Cache::get($key);
            abort_if($preview === null, 410, 'This import has already been used');
            abort_unless($preview['tenant_id'] === $tenant->id && $preview['user_id'] === $userId, 404);

            return $this->cap->withLock($tenant, function () use ($tenant, $key, $existingPolicy): ImportResult {
                $preview = Cache::pull($key);
                abort_if($preview === null, 410, 'This import has already been used');
                $register = $this->identity->index($tenant);
                $types = $this->types($tenant);
                $creates = [];
                $updates = [];
                $outcomes = [];
                $skipped = 0;
                $errors = 0;
                foreach ($preview['rows'] as $original) {
                    $row = $this->validateRow($original, $original['line'], $types);
                    $status = 'skipped';
                    if ($original['status'] === 'error' || $row['errors'] !== []) {
                        $row['errors'] = $original['status'] === 'error' ? $original['errors'] : $row['errors'];
                        $status = 'error';
                        $errors++;
                    } elseif ($original['status'] === 'duplicate') {
                        $skipped++;
                    } else {
                        $id = $this->identity->matchingId($register, $row['name'], $row['phone'], $row['email']);
                        if ($id !== null) {
                            if ($existingPolicy === 'update' && $original['status'] === 'existing' && $original['existing_id'] === $id && ! isset($updates[$id])) {
                                $updates[$id] = $row;
                                $status = 'updated';
                            } else {
                                $skipped++;
                            }
                        } else {
                            $creates[] = $row;
                            $status = 'created';
                        }
                        foreach ($this->identity->keys($row['name'], $row['phone'], $row['email']) as $identityKey) {
                            $register[$identityKey] = $id ?? 'new:'.$row['line'];
                        }
                    }
                    $outcomes[] = ['line' => $row['line'], 'status' => $status, 'errors' => $row['errors']];
                }
                $this->cap->assertRoomFor($tenant, count($creates));
                foreach ($updates as $id => $row) {
                    Member::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->findOrFail($id)->update($this->attributes($row));
                }
                $today = CarbonImmutable::now(CommunitySettings::for($tenant)->timezone)->toDateString();
                foreach ($creates as $row) {
                    $member = new Member([...$this->attributes($row), 'tenant_id' => $tenant->id, 'status' => MemberStatusEnum::Active, 'joined_on' => $row['joined_on'] ?? $today]);
                    $member->setRelation('tenant', $tenant);
                    $member->save();
                }

                return new ImportResult(count($creates), count($updates), $skipped, $errors, $outcomes);
            });
        });
    }

    /** @return array<int, array<array-key, mixed>> */
    private function read(UploadedFile $file): array
    {
        $import = new class extends StringValueBinder implements Import, WithColumnLimit, WithCustomValueBinder, WithLimit, WithMultipleSheets, WithReadFilter
        {
            public function limit(): int
            {
                return 10002;
            }

            public function readFilter(): IReadFilter
            {
                return new class implements IReadFilter
                {
                    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                    {
                        return $row <= 10002 && in_array($columnAddress, ['A', 'B', 'C', 'D', 'E', 'F'], true);
                    }
                };
            }

            public function endColumn(): string
            {
                return 'F';
            }

            public function sheets(): array
            {
                return [0 => $this];
            }
        };
        try {
            return array_values(Spreadsheet::toArray($import, $file, readerType: Str::lower($file->getClientOriginalExtension()) === 'xlsx' ? Excel::XLSX : Excel::CSV)[0] ?? []);
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['file' => 'The spreadsheet could not be read. Upload a valid CSV or XLSX file using the template.']);
        }
    }

    /** @return array<string, string> */
    private function types(Tenant $tenant): array
    {
        return MembershipType::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->where('is_active', true)->get()
            ->mapWithKeys(fn (MembershipType $type): array => [Str::lower(mb_trim($type->name)) => $type->id])->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $types
     * @return array<string, mixed>
     */
    private function validateRow(array $data, int $line, array $types): array
    {
        $phone = filled($data['phone']) ? (string) $data['phone'] : null;
        $date = $data['joined_on'];
        $row = [
            'line' => $line,
            'name' => Str::squish((string) $data['name']),
            'phone' => $this->identity->normalisePhone($phone) ?? $phone,
            'email' => $this->identity->normaliseEmail(filled($data['email']) ? (string) $data['email'] : null),
            'membership_type' => mb_trim((string) $data['membership_type']),
            'joined_on' => filled($date) ? (string) $date : null,
        ];
        $validator = Validator::make($row, [
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->identity->normalisePhone($value) === null) {
                    $fail('Enter a valid phone number, such as 0241234567 or +233241234567.');
                }
            }],
            'email' => ['nullable', 'required_without:phone', 'email', 'max:255'],
            'joined_on' => ['nullable', 'date_format:Y-m-d'],
        ], ['name.required' => 'Enter the member\'s name.', 'phone.required_without' => 'Add a phone number or email address.', 'email.required_without' => 'Add a phone number or email address.', 'joined_on.date_format' => 'Enter a valid join date in YYYY-MM-DD format.']);
        $errors = $validator->errors()->all();
        $row['membership_type_id'] = $types[Str::lower($row['membership_type'])] ?? null;
        if ($row['membership_type_id'] === null) {
            $errors[] = 'Choose an active membership type of this organisation.';
        }
        $row['errors'] = array_values(array_unique($errors));

        return $row;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function attributes(array $row): array
    {
        return array_intersect_key($row, array_flip(['name', 'phone', 'email', 'membership_type_id']));
    }

    private function cacheKey(string $token): string
    {
        return 'community.import.preview:'.$token;
    }
}
