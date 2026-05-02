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

    /**
     * Return a new set containing only the changes whose name (or kind/name)
     * matches one of the given patterns.
     *
     * @param list<string> $patterns each is either "name" or "kind/name"
     */
    public function filter(array $patterns): self
    {
        if ($patterns === []) {
            return $this;
        }

        $filtered = array_values(array_filter(
            $this->changes,
            static function (FileChange $c) use ($patterns): bool {
                $key = $c->kind->value . '/' . $c->name;
                foreach ($patterns as $p) {
                    if ($p === $c->name || $p === $key) {
                        return true;
                    }
                }
                return false;
            },
        ));

        return new self($filtered);
    }

    /**
     * Replace the change list (used by interactive confirmation).
     *
     * @param list<FileChange> $changes
     */
    public function withChanges(array $changes): self
    {
        return new self($changes);
    }
}
