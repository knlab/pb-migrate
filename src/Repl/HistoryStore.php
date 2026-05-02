<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Repl;

final class HistoryStore
{
    public function __construct(public readonly string $path)
    {
    }

    public static function default(): self
    {
        $home = getenv('HOME') ?: sys_get_temp_dir();
        return new self($home . DIRECTORY_SEPARATOR . '.pb-migrate_history');
    }

    public function load(): void
    {
        if (function_exists('readline_read_history') && is_file($this->path)) {
            @readline_read_history($this->path);
        }
    }

    public function append(string $line): void
    {
        if (function_exists('readline_add_history')) {
            @readline_add_history($line);
        }
    }

    public function save(): void
    {
        if (function_exists('readline_write_history')) {
            @readline_write_history($this->path);
        }
    }
}
