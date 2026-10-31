<?php

declare(strict_types=1);

use App\Providers\TelescopeServiceProvider;
use Illuminate\Http\Request;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

it('does not record private portal payloads responses or session capabilities even locally', function (): void {
    $environment = app('env');
    $filters = Telescope::$filterUsing;
    $request = app('request');
    try {
        app()->instance('env', 'local');
        app()->instance('request', Request::create('/my/communities/example/access', 'POST', ['secret' => 'private-recovery-key']));
        Telescope::$filterUsing = [];
        Telescope::flushEntries();
        (new TelescopeServiceProvider(app()))->register();
        Telescope::startRecording(false);
        Telescope::recordRequest(IncomingEntry::make(['uri' => '/my/communities/example/access', 'payload' => ['secret' => 'private-recovery-key'], 'response' => ['secret' => 'new-key'], 'session' => ['community_claim_capabilities' => 'private-capability']]));
        expect(Telescope::$entriesQueue)->toBe([]);
        app()->instance('request', Request::create('/dashboard'));
        Telescope::recordRequest(IncomingEntry::make(['uri' => '/dashboard']));
        expect(Telescope::$entriesQueue)->toHaveCount(1);
    } finally {
        Telescope::stopRecording();
        Telescope::flushEntries();
        Telescope::$filterUsing = $filters;
        app()->instance('env', $environment);
        app()->instance('request', $request);
    }
});

it('marks the portal page private and non-cacheable before or after sign-in', function (): void {
    $host = mb_ltrim((string) config('session.domain'), '.');
    $this->get('http://'.$host.'/my', ['HTTP_HOST' => $host])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
});
