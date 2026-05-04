<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Config;

use KnLab\PbMigrate\Config\EnvLoader;
use PHPUnit\Framework\TestCase;

final class EnvLoaderTest extends TestCase
{
    private string $tmpDir;

    /** @var array<string, string|false> */
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-envloader-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);

        // Snapshot any vars our tests touch so we can restore them.
        foreach (['PBM_TEST_VAR_A', 'PBM_TEST_VAR_B'] as $key) {
            $this->envSnapshot[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envSnapshot as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }

        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testGetenvSeesValuesLoadedFromDotenv(): void
    {
        // The whole tool reads credentials via getenv(); Symfony Dotenv defaults
        // to populating $_ENV only. EnvLoader has to enable usePutenv so the
        // `.env`-only path actually works for fresh installs.
        file_put_contents($this->tmpDir . '/.env', "PBM_TEST_VAR_A=hello-from-dotenv\n");

        EnvLoader::loadFrom($this->tmpDir);

        $this->assertSame('hello-from-dotenv', getenv('PBM_TEST_VAR_A'));
    }

    public function testEnvLocalOverridesEnv(): void
    {
        file_put_contents($this->tmpDir . '/.env', "PBM_TEST_VAR_B=base\n");
        file_put_contents($this->tmpDir . '/.env.local', "PBM_TEST_VAR_B=overridden\n");

        EnvLoader::loadFrom($this->tmpDir);

        $this->assertSame('overridden', getenv('PBM_TEST_VAR_B'));
    }

    public function testNoFilesIsANoop(): void
    {
        // Should not throw or error when .env / .env.local are absent.
        EnvLoader::loadFrom($this->tmpDir);
        $this->assertFalse(getenv('PBM_TEST_VAR_A'));
    }
}
