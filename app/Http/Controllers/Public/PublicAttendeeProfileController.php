<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendee\PublishPublicAttendeeProfileRequest;
use App\Http\Requests\Attendee\UploadPublicAttendeePhotoRequest;
use App\Models\EventRegistration;
use App\Services\Events\Profiles\ProfileImages;
use App\Services\Events\Profiles\ProfilePublication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicAttendeeProfileController extends Controller
{
    public function upload(UploadPublicAttendeePhotoRequest $request, string $registration, ProfileImages $images): JsonResponse
    {
        $image = $images->upload($this->registration($registration), $request->session(), $request->file('photo'));

        return response()->json(['image' => ['id' => $image->id]])->header('Cache-Control', 'private, no-store');
    }

    public function current(Request $request, string $registration, ProfilePublication $profiles): JsonResponse
    {
        return $this->response($profiles->current($this->registration($registration), $request->session()));
    }

    public function publish(PublishPublicAttendeeProfileRequest $request, string $registration, ProfilePublication $profiles): JsonResponse
    {
        $ticket = $this->registration($registration);
        $profiles->publish($ticket, $request->session(), $request->validated());

        return $this->response($profiles->current($ticket, $request->session()));
    }

    public function withdraw(Request $request, string $registration, ProfilePublication $profiles): JsonResponse
    {
        $ticket = $this->registration($registration);
        $profiles->withdraw($ticket, $request->session());

        return $this->response($profiles->current($ticket, $request->session()));
    }

    private function registration(string $id): EventRegistration
    {
        return EventRegistration::withoutGlobalScopes()->findOrFail($id);
    }

    /** @param array<string,mixed> $profile */
    private function response(array $profile): JsonResponse
    {
        return response()->json(['profile' => $profile])->header('Cache-Control', 'private, no-store');
    }
}
