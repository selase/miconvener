<?php

declare(strict_types=1);

use App\Models\Event;
use App\Services\Events\AttendeeImport;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cache.default' => 'array', 'mail.default' => 'array', 'session.driver' => 'array']);
Queue::fake();
Mail::fake();
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$event = Event::withoutGlobalScopes()->findOrFail($input['event']);
app(TenantContext::class)->setTenant($event->tenant);
$event->getConnection()->statement("SET application_name = 'attendee-import-worker'");
if ($input['action'] === 'import') {
    app(AttendeeImport::class)->process($input['import']);
} else {
    $host = $event->tenant->slug.'.miconvener.test';
    if ($input['action'] === 'cancel') {
        Illuminate\Support\Facades\Auth::guard()->setUser(App\Models\User::findOrFail($input['actor']));
        $request = Request::create('http://'.$host.'/events/'.$event->id.'/registrations/'.$input['registration'].'/cancel', 'POST', [], [], [], ['HTTP_HOST' => $host, 'HTTP_ACCEPT' => 'application/json']);
    } elseif ($input['action'] === 'verify') {
        $request = Request::create($input['url'], 'GET', [], [], [], ['HTTP_HOST' => $host]);
    } else {
        $request = Request::create('http://'.$host.'/e/'.$event->slug.'/register', 'POST', ['full_name' => 'Native race', 'email' => $input['email']], [], [], ['HTTP_HOST' => $host]);
    }
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    if ($response->getStatusCode() >= 500) {
        throw new RuntimeException('Public registration failed unexpectedly.');
    }
}
echo 'DONE';
