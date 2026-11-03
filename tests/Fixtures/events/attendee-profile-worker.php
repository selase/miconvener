<?php

declare(strict_types=1);

use App\Models\EventRegistration;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Events\Profiles\ProfileImages;
use App\Services\Events\Profiles\ProfilePublication;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
config(['cache.default' => 'array', 'queue.default' => 'sync', 'filesystems.disks.attendee-profiles-local.root' => $input['root']]);
$session = app('session.store');
$session->setId($input['session_id']);
$session->put(PlatformAttendeeVerification::SESSION_KEY, $input['proof']);
while (microtime(true) < $input['start_at']) { usleep(1000); }
try {
    $registration = EventRegistration::withoutGlobalScopes()->findOrFail($input['registration']);
    if ($input['action'] === 'transfer') {
        $registration->getConnection()->transaction(function () use ($registration): void {
            $current = EventRegistration::withoutGlobalScopes()->lockForUpdate()->findOrFail($registration->id);
            $current->forceFill(['email' => 'new-owner@example.test'])->save();
        });
    } elseif ($input['action'] === 'withdraw') {
        app(ProfilePublication::class)->withdraw($registration, $session);
    } elseif ($input['action'] === 'upload') {
        app(ProfileImages::class)->upload($registration, $session, UploadedFile::fake()->image('photo.png', 60, 60));
    } else {
        app(ProfilePublication::class)->publish($registration, $session, $input['publication']);
    }
    echo json_encode(['result' => 'done'], JSON_THROW_ON_ERROR);
} catch (ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
    echo json_encode(['result' => 'refused'], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    echo json_encode(['error' => $exception::class.': '.$exception->getMessage()], JSON_THROW_ON_ERROR);
}
