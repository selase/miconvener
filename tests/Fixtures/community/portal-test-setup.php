<?php

declare(strict_types=1);
function setupCommunityPortalTest(object $test): void
{
    $test->tenant = App\Models\Tenant::factory()->create();
    $test->other = App\Models\Tenant::factory()->create();
    $test->member = App\Models\Member::factory()->create(['tenant_id' => $test->tenant->id, 'email' => 'a@example.test', 'phone' => '+233241234567', 'notes' => 'PRIVATE STAFF NOTES']);
    $test->relative = App\Models\Member::factory()->create(['tenant_id' => $test->tenant->id, 'email' => 'relative@example.test', 'phone' => $test->member->phone]);
    $test->foreign = App\Models\Member::factory()->create(['tenant_id' => $test->other->id, 'email' => 'b@example.test', 'phone' => $test->member->phone]);
    $test->session = app('session.store');
    $test->session->put(App\Services\Events\PlatformAttendeeVerification::SESSION_KEY, ['email' => 'a@example.test', 'expires_at' => now()->addHour()->timestamp]);
    $test->base = 'http://'.mb_ltrim((string) config('session.domain'), '.').'/my';
}
