<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Sync;

use Spontena\PbPhp\FileKind;

final class FileChange
{
    public const ADD = 'add';
    public const UPDATE = 'update';
    public const DELETE = 'delete';

    public function __construct(
        public readonly string $action,
        public readonly FileKind $kind,
        public readonly string $name,
        public readonly ?string $localPath = null,
    ) {
    }
}
