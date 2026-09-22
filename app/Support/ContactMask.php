<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Enough of an address for its owner to recognise it, not enough for anyone
 * else to use. For anything shown on a page reachable by holding a link.
 */
final class ContactMask
{
    public static function email(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $visible = mb_substr($user, 0, 2);

        return $visible.str_repeat('•', max(1, mb_strlen($user) - 2)).'@'.$domain;
    }
}
