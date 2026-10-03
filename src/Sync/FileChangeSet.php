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
     * matches one of the given patterns. Kinds without a filename in their
     * URL (`properties`, `pdefaults`) have an empty name, so they are
     * selected by the bare kind value instead (`--only properties`).
     *
     * @param list<string> $patterns each is either "name", "kind/name", or a bare kind for properties / pdefaults
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
                $bareKind = $c->kind->hasFilenameInPath() ? null : $c->kind->value;
                foreach ($patterns as $p) {
                    if ($p === $c->name || $p === $key || $p === $bareKind) {
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
