<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Config;

final class BotConfig
{
    public function __construct(
        public readonly string $name,
        public readonly string $directory,
        public readonly string $filesPattern = '*',
    ) {
    }
}
