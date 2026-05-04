<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Config;

use Symfony\Component\Dotenv\Dotenv;

final class EnvLoader
{
    public static function loadFrom(string $cwd): void
    {
        // Symfony Dotenv defaults to populating $_ENV / $_SERVER only. The rest
        // of pb-migrate reads credentials via getenv(), so without usePutenv()
        // the .env file silently fails to make values visible to the tool —
        // a fresh install (`config` writes .env, new shell runs `push`) would
        // report "PB_APP_ID is not set" even though the file is correct.
        $dotenv = (new Dotenv())->usePutenv();

        foreach (['.env', '.env.local'] as $name) {
            $path = $cwd . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                $dotenv->load($path);
            }
        }
    }
}
