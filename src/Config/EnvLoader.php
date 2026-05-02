<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Config;

use Symfony\Component\Dotenv\Dotenv;

final class EnvLoader
{
    public static function loadFrom(string $cwd): void
    {
        $dotenv = new Dotenv();

        foreach (['.env', '.env.local'] as $name) {
            $path = $cwd . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                $dotenv->load($path);
            }
        }
    }
}
