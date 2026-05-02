<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Sync;

use Spontena\PbPhp\FileKind;

final class LocalFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly FileKind $kind,
        public readonly string $hash,
    ) {
    }
}
