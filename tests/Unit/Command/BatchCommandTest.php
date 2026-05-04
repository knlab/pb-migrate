<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Tests\Unit\Command;

use KnLab\PbMigrate\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers the orchestration in BatchCommand:
 *   - missing file → FAILURE
 *   - empty / comment-only file → SUCCESS with "no commands"
 *   - all-success run → SUCCESS
 *   - failing line stops the run by default
 *   - --continue-on-error keeps going past failures
 *   - --echo prefixes each line with "$ "
 *
 * Parsing logic itself (comments / blanks / trimming) is exercised by
 * RunbookParserTest.
 */
final class BatchCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pbm-batch-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    public function testMissingFileYieldsFailure(): void
    {
        $tester = new CommandTester($this->app()->find('batch'));
        $tester->execute(['file' => $this->tmpDir . '/does-not-exist.txt']);
        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Runbook file not found', $tester->getDisplay());
    }

    public function testEmptyOrCommentOnlyFileYieldsSuccessWithNoCommands(): void
    {
        $file = $this->tmpDir . '/empty.txt';
        file_put_contents($file, "# only comments here\n# nothing to run\n\n");

        $tester = new CommandTester($this->app()->find('batch'));
        $tester->execute(['file' => $file]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('(no commands in', $tester->getDisplay());
    }

    public function testAllSuccessRunReturnsSuccess(): void
    {
        $file = $this->tmpDir . '/runbook.txt';
        file_put_contents($file, "stub-batch ok\nstub-batch ok\n");

        $tester = new CommandTester($this->appWithStub()->find('batch'));
        $tester->execute(['file' => $file]);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('2/2 ok', $tester->getDisplay());
    }

    public function testFailureStopsRunByDefault(): void
    {
        $file = $this->tmpDir . '/runbook.txt';
        file_put_contents($file, "stub-batch ok\nstub-batch fail\nstub-batch ok\n");

        $tester = new CommandTester($this->appWithStub()->find('batch'));
        $tester->execute(['file' => $file]);
        $this->assertNotSame(0, $tester->getStatusCode());

        $display = $tester->getDisplay();
        $this->assertStringContainsString('command failed', $display);
        // Third line should not have run since we stopped on the second.
        $this->assertSame(2, substr_count($display, 'stub-batch ran with'),
            'default mode must stop at the first failure');
    }

    public function testContinueOnErrorKeepsRunningPastFailure(): void
    {
        $file = $this->tmpDir . '/runbook.txt';
        file_put_contents($file, "stub-batch ok\nstub-batch fail\nstub-batch ok\n");

        $tester = new CommandTester($this->appWithStub()->find('batch'));
        $tester->execute([
            'file' => $file,
            '--continue-on-error' => true,
        ]);
        // Final exit is non-zero because at least one line failed, but all 3 ran.
        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('2/3 ok, 1 failed', $tester->getDisplay());
    }

    public function testEchoPrefixesEachLine(): void
    {
        $file = $this->tmpDir . '/runbook.txt';
        file_put_contents($file, "stub-batch ok\nstub-batch ok\n");

        $tester = new CommandTester($this->appWithStub()->find('batch'));
        $tester->execute([
            'file' => $file,
            '--echo' => true,
        ]);
        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        $this->assertSame(2, substr_count($display, '$ stub-batch'),
            '--echo must print every executed command line with a "$ " prefix');
    }

    private function app(): Application
    {
        return new Application();
    }

    /**
     * Application with a stub command (`stub-batch <ok|fail>`) registered for
     * tests that need to control the success / failure of each runbook line.
     */
    private function appWithStub(): Application
    {
        $app = new Application();
        $app->add(new BatchStubCommand());
        return $app;
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
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

#[AsCommand(name: 'stub-batch', description: 'Test-only stub: returns SUCCESS for "ok" arg, FAILURE otherwise')]
final class BatchStubCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('mode', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(sprintf('stub-batch ran with mode=%s', $input->getArgument('mode')));
        return $input->getArgument('mode') === 'ok' ? Command::SUCCESS : Command::FAILURE;
    }
}
