<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Sync;

final class FileChangeSet
{
    /**
     * @param list<FileChange> $changes
     */
    public function __construct(private readonly array $changes)
    {
    }

    /**
     * @return list<FileChange>
     */
    public function all(): array
    {
        return $this->changes;
    }

    /**
     * @return list<FileChange>
     */
    public function byAction(string $action): array
    {
        return array_values(array_filter($this->changes, static fn (FileChange $c) => $c->action === $action));
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    public function count(): int
    {
        return count($this->changes);
    }
}
