<?php

declare(strict_types=1);

namespace App\Libraries;

final class EnvHelper
{
    public static function setEnv($key, $value): void
    {
        $file_path = base_path('.env');
        $data = file($file_path);

        $data = array_map(fn ($data): string => mb_stristr($data, (string) $key) ? "$key=\"$value\"\n" : $data, $data);

        // Write file
        $env_file = fopen($file_path, 'w') or exit('Unable to open file!');
        fwrite($env_file, implode('', $data));
        fclose($env_file);
    }
}
