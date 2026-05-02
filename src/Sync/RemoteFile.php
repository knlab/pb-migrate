<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Sync;

use Spontena\PbPhp\FileKind;

final class RemoteFile
{
    public function __construct(
        public readonly FileKind $kind,
        public readonly string $name,
    ) {
    }
}
