<?php

declare(strict_types=1);

namespace App\Services\Events\Profiles;

use App\Models\EventAttendeeProfileImage;
use App\Models\EventRegistration;
use App\Models\Tenant;
use App\Services\Events\PlatformAttendeeVerification;
use App\Services\Tenancy\StorageAllowances;
use App\Services\Tenancy\StorageMeter;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class ProfileImages
{
    public function __construct(private ProfilePublication $publication, private ProfileEligibility $eligibility, private StorageMeter $meter, private StorageAllowances $allowances) {}

    public static function proofHash(Session $session): string
    {
        return hash_hmac('sha256', 'attendee-photo:'.$session->getId().'|'.json_encode($session->get(PlatformAttendeeVerification::SESSION_KEY), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function upload(EventRegistration $candidate, Session $session, UploadedFile $file): EventAttendeeProfileImage
    {
        $registration = $this->publication->ownedRegistration($candidate, $session);
        abort_unless($this->eligibility->registrationEligible($registration), 403);
        $bytes = $this->encode($file);
        $intent = $registration->getConnection()->transaction(function () use ($registration, $session, $bytes): EventAttendeeProfileImage {
            $tenant = Tenant::query()->lock('for no key update')->findOrFail($registration->tenant_id);
            $current = EventRegistration::withoutGlobalScopes()->lockForUpdate()->findOrFail($registration->id);
            $this->publication->ownedRegistration($current, $session);
            abort_unless($this->eligibility->registrationEligible($current), 403);
            if ($current->attendee_profile_generation === null) {
                $current->forceFill(['attendee_profile_generation' => (string) Str::uuid()])->saveQuietly();
                $current->refresh();
            }
            $limit = $this->allowances->limit($tenant);
            if ($limit !== null && $this->meter->calculateUsage($tenant) + mb_strlen($bytes) > $limit) {
                throw ValidationException::withMessages(['photo' => 'Your organisation storage allowance is full. You can still publish without a photo.']);
            }
            if (EventAttendeeProfileImage::withoutGlobalScopes()->where('registration_id', $current->id)->whereNull('profile_id')->whereNull('retired_at')->whereNull('deleted_at')->where('expires_at', '>', now())->count() >= 3) {
                throw ValidationException::withMessages(['photo' => 'Finish choosing a photo or try again in fifteen minutes.']);
            }

            return EventAttendeeProfileImage::query()->forceCreate([
                'tenant_id' => $tenant->id, 'event_id' => $current->event_id, 'registration_id' => $current->id,
                'owner_hash' => $this->eligibility->ownerHash($current->event_id, $current->email),
                'proof_hash' => self::proofHash($session), 'registration_generation' => $current->attendee_profile_generation,
                'disk' => app()->environment('production') ? 's3' : 'attendee-profiles-local',
                'path' => 'attendee-profiles/'.$tenant->id.'/'.Str::uuid().'.webp',
                'byte_size' => mb_strlen($bytes), 'expires_at' => now()->addMinutes(15),
            ]);
        }, 3);
        try {
            $intent->getConnection()->transaction(function () use ($intent, $bytes): void {
                $image = EventAttendeeProfileImage::withoutGlobalScopes()->lockForUpdate()->findOrFail($intent->id);
                if ($image->retired_at !== null || $image->deleted_at !== null || ! $image->expires_at->isFuture()) {
                    throw ValidationException::withMessages(['photo' => 'This upload expired. Choose the photo again.']);
                }
                if (! Storage::disk($image->disk)->put($image->path, $bytes, ['visibility' => 'private'])) {
                    throw ValidationException::withMessages(['photo' => 'The photo could not be stored. Try again.']);
                }
                $image->forceFill(['stored_at' => now()])->save();
            });
        } catch (Throwable $exception) {
            $intent->forceFill(['retired_at' => now()])->save();
            throw ValidationException::withMessages(['photo' => 'The photo could not be stored. Your existing profile is unchanged.']);
        }

        return $intent->refresh();
    }

    private function encode(UploadedFile $file): string
    {
        if (! $file->isValid() || $file->getSize() > 2097152 || ! function_exists('imagewebp')) {
            throw ValidationException::withMessages(['photo' => 'Choose a JPEG, PNG or WebP photo up to 2 MiB.']);
        }
        $bytes = file_get_contents($file->getRealPath());
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);
        if ($bytes === false || $info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $info[0] > 6000 || $info[1] > 6000 || $info[0] * $info[1] > 12000000 || $this->animated($bytes, $info[2])) {
            throw ValidationException::withMessages(['photo' => 'Choose a static JPEG, PNG or WebP photo, at most 6000 pixels per side and 12 megapixels.']);
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw ValidationException::withMessages(['photo' => 'This photo could not be read.']);
        }
        try {
            if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $exif = @exif_read_data($file->getRealPath());
                $orientation = (int) ($exif['Orientation'] ?? 1);
                if (in_array($orientation, [2, 4, 5, 7], true)) {
                    imageflip($image, IMG_FLIP_HORIZONTAL);
                }
                $angle = match ($orientation) {
                    3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0
                };
                if ($angle !== 0) {
                    $rotated = imagerotate($image, $angle, 0);
                    if ($rotated !== false) {
                        $image = $rotated;
                    }
                }
            }
            $width = imagesx($image);
            $height = imagesy($image);
            $side = min($width, $height);
            $output = imagecreatetruecolor(512, 512);
            imagealphablending($output, false);
            imagesavealpha($output, true);
            imagecopyresampled($output, $image, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), 512, 512, $side, $side);
            ob_start();
            try {
                if (! imagewebp($output, null, 82)) {
                    throw ValidationException::withMessages(['photo' => 'This photo could not be processed.']);
                }
                $encoded = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (! is_string($encoded) || $encoded === '') {
                throw ValidationException::withMessages(['photo' => 'This photo could not be processed.']);
            }

            return $encoded;
        } finally {
            unset($image, $output);
        }
    }

    private function animated(string $bytes, int $type): bool
    {
        if ($type === IMAGETYPE_PNG) {
            $offset = 8;
            while ($offset + 12 <= mb_strlen($bytes)) {
                $size = unpack('Nsize', mb_substr($bytes, $offset, 4))['size'];
                $chunk = mb_substr($bytes, $offset + 4, 4);
                if ($chunk === 'acTL') {
                    return true;
                }
                if ($chunk === 'IEND' || $size > mb_strlen($bytes) - $offset - 12) {
                    break;
                }
                $offset += 12 + $size;
            }
        }
        if ($type === IMAGETYPE_WEBP) {
            $offset = 12;
            while ($offset + 8 <= mb_strlen($bytes)) {
                $chunk = mb_substr($bytes, $offset, 4);
                $size = unpack('Vsize', mb_substr($bytes, $offset + 4, 4))['size'];
                if (in_array($chunk, ['ANIM', 'ANMF'], true) || ($chunk === 'VP8X' && $size > 0 && (ord($bytes[$offset + 8]) & 2) !== 0)) {
                    return true;
                }
                if ($size > mb_strlen($bytes) - $offset - 8) {
                    break;
                }
                $offset += 8 + $size + ($size % 2);
            }
        }

        return false;
    }
}
