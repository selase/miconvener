<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Phone numbers as SMS providers want them: international digits, no plus
 * sign or spaces. Numbers are stored however attendees typed them, often in
 * the local form, so a leading 0 is read as a Ghanaian number.
 */
final class PhoneNumber
{
    public const string DEFAULT_COUNTRY_CODE = '233';

    /**
     * "0013018201664" and "+13018201664" are the same number; validators and the
     * stored form expect the plus.
     */
    public static function withInternationalPlus(?string $number): ?string
    {
        $number = mb_trim((string) $number);

        return str_starts_with($number, '00') ? '+'.mb_ltrim(mb_substr($number, 2)) : $number;
    }

    public static function toInternationalDigits(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = mb_substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = self::DEFAULT_COUNTRY_CODE.mb_substr($digits, 1);
        }

        // E.164 allows at most 15 digits; anything shorter than a country
        // code plus a subscriber number is not something we can text.
        return mb_strlen($digits) >= 10 && mb_strlen($digits) <= 15 ? $digits : null;
    }
}
