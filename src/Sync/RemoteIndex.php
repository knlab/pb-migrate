<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Sync;

use Spontena\PbPhp\FileKind;

/**
 * Normalised view of `getBotFiles()` response.
 * Pandorabots groups files by kind under top-level keys ("files", "sets", "maps", ...).
 */
final class RemoteIndex
{
    /**
     * @param array<string, list<RemoteFile>> $byKind kind value (e.g. "file") → entries
     */
    private function __construct(private readonly array $byKind)
    {
    }

    public static function fromResponse(\stdClass $response): self
    {
        $mapping = [
            'files' => FileKind::File,
            'sets' => FileKind::Set,
            'maps' => FileKind::Map,
            'substitutions' => FileKind::Substitution,
            'pdefaults' => FileKind::Pdefaults,
            'properties' => FileKind::Properties,
        ];

        $byKind = [];
        foreach ($mapping as $key => $kind) {
            $entries = $response->{$key} ?? [];
            if (!is_array($entries)) {
                continue;
            }

            $list = [];
            foreach ($entries as $entry) {
                if (!$entry instanceof \stdClass) {
                    continue;
                }
                $name = isset($entry->name) ? (string) $entry->name : '';
                if (!$kind->hasFilenameInPath()) {
                    // The API listing reports these kinds with the kind name as
                    // the row label (e.g. `name="pdefaults"`), but the canonical
                    // local-side representation has no name component. Normalise
                    // so diff / cache joins work against bare-name local files.
                    $name = '';
                } elseif ($name === '') {
                    continue;
                } else {
                    $name = self::stripExtension($name, $kind);
                }
                $list[] = new RemoteFile($kind, $name);
            }

            $byKind[$kind->value] = $list;
        }

        return new self($byKind);
    }

    /**
     * @return list<RemoteFile>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->byKind as $list) {
            foreach ($list as $entry) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    public function has(FileKind $kind, string $name): bool
    {
        $list = $this->byKind[$kind->value] ?? [];
        foreach ($list as $entry) {
            if ($entry->name === $name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Pandorabots reports AIML files as "name.aiml"; we want the base name to
     * align with `upload(.aiml)` semantics.
     */
    private static function stripExtension(string $reportedName, FileKind $kind): string
    {
        if ($kind === FileKind::File && str_ends_with(strtolower($reportedName), '.aiml')) {
            return substr($reportedName, 0, -5);
        }
        return $reportedName;
    }
}
