<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\UserLoginHistory;
use Carbon\Month;
use Carbon\WeekDay;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Stevebauman\Location\Facades\Location;

final class Helper
{
    public static function generateNumber($length = 8): int
    {
        $intMin = (10 ** $length) / 10;
        $intMax = (10 ** $length) - 1;

        return mt_rand($intMin, $intMax);
    }

    public static function getHumanDate(DateTimeInterface|WeekDay|Month|string|int|float|null $date): string
    {
        return Date::parse($date)->toDayDateTimeString();
    }

    public static function getFormattedDateString(DateTimeInterface|WeekDay|Month|string|int|float|null $date): string
    {
        return Date::parse($date)->toFormattedDateString();
    }

    public static function getInitials($str): ?string
    {
        if (preg_match_all('/\b(\w)/', mb_strtoupper((string) $str), $m)) {
            return implode('', $m[1]);
        }

        return null;
    }

    // generate avatar image
    public static function generateGravatar($email, $size = 200): string
    {
        $hash = md5(mb_strtolower(mb_trim($email)));

        return "https://www.gravatar.com/avatar/$hash?s=$size";
    }

    public static function generateUiAvatar(string $name, int $size = 128): string
    {
        return "https://ui-avatars.com/api/?name=$name&background=random&size=$size";
    }

    // generate random string
    public static function generateRandomString($length = 8): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = mb_strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    // get current year
    public static function getCurrentYear()
    {
        return Date::now()->year;
    }

    /**
     * Process an UploadedFile. The field name is the form field containing the uploaded file
     *
     * @param  mixed  $request
     * @param  mixed  $field_name
     * @param  mixed  $file_name_prefix
     * @param  mixed  $folder_name
     */
    public static function processUploadedFile(
        $request,
        string $field_name,
        string $file_name_prefix,
        string $folder_name,
        string $disk = 'public',
    ): string {
        $file = $request->file($field_name);
        $filename = $file_name_prefix.'_'.uniqid().'.'.$file->getClientOriginalExtension();

        Storage::disk($disk)->putFileAs($folder_name, $file, $filename);

        return $folder_name.'/'.$filename;
    }

    /**
     * delete a file from storage
     */
    public static function deleteFile($file_path, string $disk = 'public'): void
    {
        Storage::disk($disk)->delete($file_path);
    }

    public static function getReadableDate(DateTimeInterface|WeekDay|Month|string|int|float|null $date): string
    {
        return Date::parse($date)->diffForHumans();
    }

    public static function getClientBrowser()
    {
        return request()->userAgent();
    }

    public static function getClientLocation()
    {
        $location = Location::get();

        return $location ? ($location->countryName ?? 'Unknown') : 'Unknown';
    }

    public static function getLoggedInBrowserCountForCurrentMonth()
    {
        return UserLoginHistory::query()->CurrentMonth()
            ->selectRaw('browser, count(browser) as count')
            ->groupBy('browser')
            ->pluck('count', 'browser')
            ->toArray();
    }

    public static function getLoggedInLocationCountForCurrentMonth()
    {
        return UserLoginHistory::query()->CurrentMonth()
            ->selectRaw('location, count(location) as count')
            ->groupBy('location')
            ->pluck('count', 'location')
            ->toArray();
    }

    public static function getLoggedInPlatformCountForCurrentMonth()
    {
        return UserLoginHistory::query()->CurrentMonth()
            ->selectRaw('platform, count(platform) as count')
            ->groupBy('platform')
            ->pluck('count', 'platform')
            ->toArray();
    }

    public static function getLoggedInClientDeviceCountForCurrentMonth()
    {
        return UserLoginHistory::query()->CurrentMonth()
            ->selectRaw('client_device, count(client_device) as count')
            ->groupBy('client_device')
            ->pluck('count', 'client_device')
            ->toArray();
    }

    public static function generateRandomPassword($length = 12): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+=-';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $randomChar = $chars[random_int(0, mb_strlen($chars) - 1)];
            $password .= $randomChar;
        }

        return $password;
    }

    public static function getTenantBranding(string $key, mixed $default = null): mixed
    {
        $tenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();
        if (! $tenant) {
            return $default;
        }

        // Support dot notation for nested meta keys (e.g., 'branding.primary_color')
        return data_get($tenant->meta, $key, $default);
    }

    public static function getTenantLogoUrl(): string
    {
        $tenant = app(\App\Services\Tenancy\TenantContext::class)->getTenant();

        if ($tenant && $tenant->logo) {
            $disk = config('app.env') === 'production' ? 's3' : 'public';

            return Storage::disk($disk)->url($tenant->logo);
        }

        return asset('assets/media/logos/logo-1.svg');
    }

    public function limitCharacters($text, $length = 100)
    {
        return Str::limit($text, $length);
    }
}
