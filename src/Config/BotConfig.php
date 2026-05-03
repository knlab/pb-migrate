<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Config;

final class BotConfig
{
    public const PROPERTIES_UPLOAD_ADDITIVE = 'additive';
    public const PROPERTIES_UPLOAD_FULL = 'full';

    public function __construct(
        public readonly string $name,
        public readonly string $directory,
        public readonly string $filesPattern = '*',
        public readonly string $propertiesUpload = self::PROPERTIES_UPLOAD_ADDITIVE,
    ) {
    }
}
