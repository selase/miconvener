<?php

declare(strict_types=1);

use App\Enum\MemberStatusEnum;
use App\Models\DuesCharge;
use App\Models\Member;
use App\Models\MembershipType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Cache::flush();
    [$this->tenant, $this->owner] = eventHost('imports');
    Artisan::call('community:enable', ['tenant' => $this->tenant->slug]);
    $this->type = MembershipType::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Regular']);
    $this->actingAs($this->owner)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('imports')]);
});

function importRoute(string $action): string
{
    return route('tenant.community.people.import.'.$action, ['subdomain' => 'imports']);
}

function memberSheet(string $rows): UploadedFile
{
    return UploadedFile::fake()->createWithContent('members.csv', "name,phone,email,membership_type,joined_on\n".$rows);
}

function previewMembers(object $test, string $rows): array
{
    return $test->postJson(importRoute('preview'), ['file' => memberSheet($rows)])->assertOk()->json();
}

test('import is discoverable and supplies a downloadable template', function () {
    $this->get(importRoute('show'))->assertInertia(fn (Assert $page) => $page->component('Tenant/Community/ImportMembers'));
    $response = $this->get(importRoute('template'))->assertOk()->assertDownload('members-template.csv');
    expect($response->streamedContent())->toBe("name,phone,email,membership_type,joined_on\n");
});

test('preview validates every row and saves nothing with physical line numbers', function () {
    MembershipType::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Retired', 'is_active' => false]);
    MembershipType::factory()->create(['name' => 'Foreign']);
    $result = previewMembers($this, "Ama,0241234567,AMA@example.test,regular,2026-10-01\nAma,+233241234567,,Regular,\nNo contact,,,Regular,\nUnknown,0241234568,,Missing,\nInactive,0241234569,,Retired,\nDate,0241234570,,Regular,2026-02-30\nPhone,garbage1234567890,,Regular,\nForeign,0241234571,,Foreign,\n");
    expect($result['summary'])->toBe(['new' => 1, 'duplicate' => 1, 'existing' => 0, 'error' => 6])
        ->and($result['rows'][0]['phone'])->toBe('+233241234567')
        ->and($result['rows'][0]['email'])->toBe('ama@example.test')
        ->and($result['rows'][2]['line'])->toBe(4)
        ->and($result['rows'][2]['errors'])->toBe(['Add a phone number or email address.'])
        ->and(Member::query()->count())->toBe(0);
});

test('confirm saves only valid new rows and a used preview returns 410', function () {
    $preview = previewMembers($this, "Ama,0241234567,,Regular,2026-10-01\nBad,,,Regular,\n");
    $payload = ['token' => $preview['token'], 'existing_policy' => 'skip'];
    $this->postJson(importRoute('confirm'), $payload)->assertOk()->assertJsonPath('created', 1)->assertJsonPath('errors', 1);
    $this->postJson(importRoute('confirm'), $payload)->assertGone()->assertJsonPath('message', 'This import has already been used');
    expect(Member::query()->sole()->name)->toBe('Ama');
});

test('preview tokens are bound to tenant and acting user before consumption', function () {
    $preview = previewMembers($this, "Ama,0241234567,,Regular,\n");
    $payload = ['token' => $preview['token'], 'existing_policy' => 'skip'];
    $otherUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherUser->assignRole('Org Superadmin');
    $this->tenant->users()->attach($otherUser->id);
    $this->actingAs($otherUser)->postJson(importRoute('confirm'), $payload)->assertNotFound();
    [$otherTenant, $otherOwner] = eventHost('other-imports');
    Artisan::call('community:enable', ['tenant' => $otherTenant->slug]);
    $this->actingAs($otherOwner)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('other-imports')])
        ->postJson(route('tenant.community.people.import.confirm', ['subdomain' => 'other-imports']), $payload)->assertNotFound();
    $this->actingAs($this->owner)->withServerVariables(['HTTP_HOST' => eventSubdomainHost('imports')])
        ->postJson(importRoute('confirm'), $payload)->assertOk()->assertJsonPath('created', 1);
});

test('preview expires after thirty minutes', function () {
    $preview = previewMembers($this, "Ama,0241234567,,Regular,\n");
    $this->travel(31)->minutes();
    $this->postJson(importRoute('confirm'), ['token' => $preview['token'], 'existing_policy' => 'skip'])->assertGone();
});

test('existing policy updates only register fields and preserves exited status money history and pay links', function () {
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'membership_type_id' => $this->type->id, 'name' => '  AMA  Mensah ', 'phone' => '0241234567', 'status' => 'exited', 'amount_override' => 700, 'joined_on' => '2025-01-01', 'active_since' => '2025-01-01', 'exited_on' => '2026-01-01', 'notes' => 'keep']);
    $paid = DuesCharge::factory()->create(['status' => 'paid', 'tenant_id' => $this->tenant->id, 'membership_type_id' => $this->type->id, 'member_id' => $member->id]);
    $original = $member->fresh()->getAttributes();
    $preview = previewMembers($this, "Ama Mensah,+233241234567,new@example.test,Regular,2026-10-01\n");
    expect($preview['summary']['existing'])->toBe(1);
    $this->postJson(importRoute('confirm'), ['token' => $preview['token'], 'existing_policy' => 'skip'])->assertOk()->assertJsonPath('skipped', 1);
    expect($member->fresh()->email)->toBe($member->email);
    $preview = previewMembers($this, "Ama Mensah,+233241234567,new@example.test,Regular,2026-10-01\n");
    $this->postJson(importRoute('confirm'), ['token' => $preview['token'], 'existing_policy' => 'update'])->assertOk()->assertJsonPath('updated', 1);
    $fresh = $member->fresh();
    expect($fresh->email)->toBe('new@example.test')->and($fresh->status)->toBe(MemberStatusEnum::Exited)
        ->and($fresh->only(['pay_token', 'amount_override', 'active_since', 'exited_on', 'notes', 'joined_on']))
        ->toEqual($member->only(['pay_token', 'amount_override', 'active_since', 'exited_on', 'notes', 'joined_on']))
        ->and($paid->fresh()->status->value)->toBe('paid')->and($fresh->pay_token)->toBe($original['pay_token']);
});

test('confirm revalidates the current register and never updates someone added after preview', function () {
    $preview = previewMembers($this, "Ama,0241234567,new@example.test,Regular,\n");
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'membership_type_id' => $this->type->id, 'name' => 'Ama', 'phone' => '0241234567', 'email' => 'keep@example.test']);
    $this->postJson(importRoute('confirm'), ['token' => $preview['token'], 'existing_policy' => 'update'])->assertOk()->assertJsonPath('created', 0)->assertJsonPath('skipped', 1);
    expect($member->fresh()->email)->toBe('keep@example.test')->and(Member::query()->count())->toBe(1);
});

test('confirm revalidates active tenant membership types', function () {
    $preview = previewMembers($this, "Ama,0241234567,,Regular,\n");
    $this->type->update(['is_active' => false]);
    $this->postJson(importRoute('confirm'), ['token' => $preview['token'], 'existing_policy' => 'skip'])->assertOk()->assertJsonPath('created', 0)->assertJsonPath('errors', 1);
    expect(Member::query()->count())->toBe(0);
});

test('over cap refuses the whole transaction including existing member updates', function () {
    $this->tenant->features()->create(['feature_key' => 'community_members', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 1]]);
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'membership_type_id' => $this->type->id, 'name' => 'Ama', 'phone' => '0241234567', 'email' => 'keep@example.test']);
    $preview = previewMembers($this, "Ama,0241234567,change@example.test,Regular,\nNew,0241234568,,Regular,\n");
    $this->postJson(importRoute('confirm'), ['token' => $preview['token'], 'existing_policy' => 'update'])->assertUnprocessable()->assertJsonPath('message', fn ($message) => str_contains($message, 'Free plan includes 1 members'));
    expect(Member::query()->count())->toBe(1)->and($member->fresh()->email)->toBe('keep@example.test');
});

test('manual and imported identities share normalization and preserve email-only reservations', function () {
    Member::factory()->create(['tenant_id' => $this->tenant->id, 'membership_type_id' => $this->type->id, 'name' => 'Ama', 'phone' => '0241234567', 'email' => 'family@example.test']);
    Member::factory()->create(['tenant_id' => $this->tenant->id, 'membership_type_id' => $this->type->id, 'name' => 'Reserved', 'phone' => null, 'email' => 'ONLY@example.test']);
    foreach ([['  AMA  ', '+233 24 123 4567', null], ['Another', '0241234569', 'only@example.test']] as [$name, $phone, $email]) {
        $this->postJson(route('tenant.community.members.store', ['subdomain' => 'imports']), ['name' => $name, 'phone' => $phone, 'email' => $email, 'membership_type_id' => $this->type->id])->assertUnprocessable();
    }
    $preview = previewMembers($this, "  AMA  ,+233241234567,,Regular,\nAnother,0241234569,only@example.test,Regular,\nSibling,0241234567,family@example.test,Regular,\nEmail Only,,family@example.test,Regular,\n");
    expect($preview['summary'])->toBe(['new' => 2, 'duplicate' => 0, 'existing' => 2, 'error' => 0]);
});

test('manual updates use the same identity guard', function () {
    $first = Member::factory()->create(['tenant_id' => $this->tenant->id, 'membership_type_id' => $this->type->id, 'name' => 'Ama', 'phone' => '0241234567']);
    $second = Member::factory()->create(['tenant_id' => $this->tenant->id, 'membership_type_id' => $this->type->id]);
    $this->patchJson(route('tenant.community.members.update', ['subdomain' => 'imports', 'member' => $second->id]), ['name' => ' AMA ', 'phone' => '+233241234567', 'membership_type_id' => $this->type->id])->assertUnprocessable();
    expect($first->fresh()->phone)->toBe('0241234567');
});

test('csv and xlsx fixtures both import', function (string $fixture) {
    $file = new UploadedFile(base_path('tests/Fixtures/community/'.$fixture), $fixture, null, null, true);
    $preview = $this->postJson(importRoute('preview'), ['file' => $file])->assertOk()->json();
    expect($preview['summary']['new'])->toBe(2);
    $this->postJson(importRoute('confirm'), ['token' => $preview['token'], 'existing_policy' => 'skip'])->assertOk()->assertJsonPath('created', 2);
})->with(['members-good.csv', 'members-good.xlsx']);

test('three thousand rows with forty errors preview without saving and confirm exactly once', function () {
    $this->tenant->features()->create(['feature_key' => 'community_members', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 5000]]);
    $rows = '';
    for ($i = 0; $i < 3000; $i++) {
        $rows .= 'Member '.$i.',,'.($i < 40 ? '' : 'member'.$i.'@example.test').",Regular,2026-10-01\n";
    }
    $preview = previewMembers($this, $rows);
    expect($preview['summary']['error'])->toBe(40)->and($preview['rows'][39]['line'])->toBe(41)->and(Member::query()->count())->toBe(0);
    $payload = ['token' => $preview['token'], 'existing_policy' => 'skip'];
    $this->postJson(importRoute('confirm'), $payload)->assertOk()->assertJsonPath('created', 2960)->assertJsonPath('errors', 40);
    $this->postJson(importRoute('confirm'), $payload)->assertGone();
    expect(Member::query()->count())->toBe(2960);
});

test('upload rejects oversized unsupported malformed and overlong sheets', function () {
    $this->postJson(importRoute('preview'), ['file' => UploadedFile::fake()->create('huge.csv', 5121)])->assertUnprocessable();
    $this->postJson(importRoute('preview'), ['file' => UploadedFile::fake()->createWithContent('members.txt', 'no')])->assertUnprocessable();
    $this->postJson(importRoute('preview'), ['file' => UploadedFile::fake()->createWithContent('members.csv', "other,headers\n")])->assertUnprocessable();
    $this->postJson(importRoute('preview'), ['file' => UploadedFile::fake()->createWithContent('members.xlsx', 'not a workbook')])->assertUnprocessable();
    $this->postJson(importRoute('preview'), ['file' => memberSheet(str_repeat("Ama,0241234567,,Regular,\n", 10001))])->assertUnprocessable();
    $this->postJson(importRoute('confirm'), ['token' => 'missing', 'existing_policy' => 'delete'])->assertUnprocessable();
});

test('every import endpoint requires manage members and an enabled community', function () {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($user->id);
    $this->actingAs($user);
    $this->get(importRoute('show'))->assertForbidden();
    $this->get(importRoute('template'))->assertForbidden();
    $this->postJson(importRoute('preview'), ['file' => memberSheet('')])->assertForbidden();
    $this->postJson(importRoute('confirm'), ['token' => 'missing', 'existing_policy' => 'skip'])->assertForbidden();
    $this->actingAs($this->owner);
    Artisan::call('community:enable', ['tenant' => $this->tenant->slug, '--off' => true]);
    $this->get(importRoute('show'))->assertNotFound();
    $this->get(importRoute('template'))->assertNotFound();
    $this->postJson(importRoute('preview'), ['file' => memberSheet('')])->assertNotFound();
    $this->postJson(importRoute('confirm'), ['token' => 'missing', 'existing_policy' => 'skip'])->assertNotFound();
});

test('malformed manual contact values return validation errors instead of server errors', function () {
    $this->postJson(route('tenant.community.members.store', ['subdomain' => 'imports']), ['name' => 'Ama', 'phone' => ['bad'], 'email' => ['bad'], 'membership_type_id' => $this->type->id])->assertUnprocessable()->assertJsonValidationErrors(['phone', 'email']);
});

test('invalid Ghana international length is rejected consistently', function () {
    $preview = previewMembers($this, "Ama,+2332412345678,,Regular,\n");
    expect($preview['summary']['error'])->toBe(1);
    $this->postJson(route('tenant.community.members.store', ['subdomain' => 'imports']), ['name' => 'Ama', 'phone' => '+2332412345678', 'membership_type_id' => $this->type->id])->assertUnprocessable();
});

test('CSV preserves international phone prefixes as text', function () {
    $preview = previewMembers($this, "Ama,+442012345678,,Regular,\n");
    expect($preview['summary']['new'])->toBe(1)->and($preview['rows'][0]['phone'])->toBe('+442012345678');
});

test('mixed fixture reports duplicates and errors while retaining physical blank line numbering', function () {
    $file = new UploadedFile(base_path('tests/Fixtures/community/members-mixed.csv'), 'members-mixed.csv', null, null, true);
    $result = $this->postJson(importRoute('preview'), ['file' => $file])->assertOk()->json();
    expect($result['summary'])->toBe(['new' => 1, 'duplicate' => 1, 'existing' => 0, 'error' => 2]);
    $result = previewMembers($this, "Ama,0241234567,,Regular,\n\nNo Contact,,,Regular,\n");
    expect($result['rows'][1]['line'])->toBe(4);
});

test('CSV join dates require strict calendar dates rather than arbitrary numeric serials', function () {
    $result = previewMembers($this, "Ama,0241234567,,Regular,123\nKojo,0241234568,,Regular,99999999999999999999999\n");
    expect($result['summary']['error'])->toBe(2);
});

test('xlsx overflow is refused with a bounded reader and other worksheets are ignored', function () {
    $spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray([['name', 'phone', 'email', 'membership_type', 'joined_on']]);
    $spreadsheet->getActiveSheet()->fromArray([['Ama', '+233241234567', null, 'Regular', 46300]], null, 'A2');
    $spreadsheet->createSheet()->setCellValue('A10002', 'Ignored worksheet');
    $path = tempnam(sys_get_temp_dir(), 'member-import-');
    try {
        (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        $this->postJson(importRoute('preview'), ['file' => new UploadedFile($path, 'valid.xlsx', null, null, true)])->assertOk()->assertJsonPath('summary.new', 1)->assertJsonPath('rows.0.joined_on', '2026-10-05');
        $spreadsheet->getSheet(0)->setCellValue('A10002', 'Overflow');
        (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        $this->postJson(importRoute('preview'), ['file' => new UploadedFile($path, 'limit.xlsx', null, null, true)])->assertUnprocessable();
    } finally {
        unlink($path);
        $spreadsheet->disconnectWorksheets();
    }
});

test('one existing email-only member is updated at most once when distinct file rows resolve to it', function () {
    $member = Member::factory()->create(['tenant_id' => $this->tenant->id, 'membership_type_id' => $this->type->id, 'name' => 'Original', 'email' => 'shared@example.test', 'phone' => null]);
    $preview = previewMembers($this, "Ama,0241234567,shared@example.test,Regular,\nKojo,0241234568,shared@example.test,Regular,\n");
    $this->postJson(importRoute('confirm'), ['token' => $preview['token'], 'existing_policy' => 'update'])->assertOk()->assertJsonPath('updated', 1)->assertJsonPath('skipped', 1);
    expect($member->fresh()->name)->toBe('Ama')->and(Member::query()->count())->toBe(1);
});

test('a legacy phone-bearing record does not reserve its email even if the old phone needs correction', function () {
    Member::factory()->create(['tenant_id' => $this->tenant->id, 'membership_type_id' => $this->type->id, 'phone' => 'legacy-invalid', 'email' => 'family@example.test']);
    $preview = previewMembers($this, "Email Only,,family@example.test,Regular,\n");
    expect($preview['summary']['new'])->toBe(1);
});
