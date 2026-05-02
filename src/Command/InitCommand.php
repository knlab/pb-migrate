<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'init', description: 'Scaffold a new pb-migrate project (pb-migrate.json + .env.example + aiml/ skeleton)')]
final class InitCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('directory', InputArgument::OPTIONAL, 'Directory to create (default: current directory)', '.');
        $this->addArgument('botname', InputArgument::OPTIONAL, 'Initial bot name in pb-migrate.json', 'mybot');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $target = (string) $input->getArgument('directory');
        $botname = (string) $input->getArgument('botname');

        $cwd = getcwd() ?: '.';
        $root = $target === '.' ? $cwd : (str_starts_with($target, '/') ? $target : $cwd . DIRECTORY_SEPARATOR . $target);

        if (!is_dir($root) && !mkdir($root, 0o755, true) && !is_dir($root)) {
            $io->error(sprintf('Could not create directory: %s', $root));
            return Command::FAILURE;
        }

        $aimlDir = $root . DIRECTORY_SEPARATOR . 'aiml' . DIRECTORY_SEPARATOR . $botname;
        if (!is_dir($aimlDir) && !mkdir($aimlDir, 0o755, true) && !is_dir($aimlDir)) {
            $io->error(sprintf('Could not create AIML directory: %s', $aimlDir));
            return Command::FAILURE;
        }

        $config = $root . DIRECTORY_SEPARATOR . 'pb-migrate.json';
        $envExample = $root . DIRECTORY_SEPARATOR . '.env.example';
        $gitignore = $root . DIRECTORY_SEPARATOR . '.gitignore';
        $sampleAiml = $aimlDir . DIRECTORY_SEPARATOR . 'greetings.aiml';

        $skipped = [];
        $created = [];

        $writeIfMissing = static function (string $path, string $content) use (&$skipped, &$created): void {
            if (file_exists($path)) {
                $skipped[] = $path;
                return;
            }
            file_put_contents($path, $content);
            $created[] = $path;
        };

        $writeIfMissing($config, self::configTemplate($botname));
        $writeIfMissing($envExample, self::envTemplate());
        $writeIfMissing($gitignore, self::gitignoreTemplate());
        $writeIfMissing($sampleAiml, self::aimlTemplate());

        foreach ($created as $path) {
            $io->writeln(sprintf('  <info>created</info> %s', $path));
        }
        foreach ($skipped as $path) {
            $io->writeln(sprintf('  <comment>exists, skipped</comment> %s', $path));
        }

        $io->success(sprintf('Initialized pb-migrate project at %s', $root));
        $io->writeln('Next steps:');
        $io->writeln('  1. cp .env.example .env  (and fill in PB_APP_ID / PB_USER_KEY)');
        $io->writeln('  2. pb-migrate bot:create ' . $botname);
        $io->writeln('  3. pb-migrate push --bot ' . $botname);

        return Command::SUCCESS;
    }

    private static function configTemplate(string $botname): string
    {
        return json_encode([
            '$schema' => 'https://knlab.github.io/pb-migrate/schema.json',
            'host' => '${PB_HOST:-https://api.pandorabots.com}',
            'appId' => '${PB_APP_ID}',
            'userKey' => '${PB_USER_KEY}',
            'botKey' => '${PB_BOT_KEY:-}',
            'bots' => [
                $botname => [
                    'directory' => './aiml/' . $botname,
                    'files' => '*',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private static function envTemplate(): string
    {
        return <<<ENV
        # Pandorabots credentials. Fill these in and copy this file to .env (which is gitignored).
        PB_APP_ID=
        PB_USER_KEY=

        # Optional: bot key for atalk (anonymous talk)
        # PB_BOT_KEY=

        # Optional: override the default API host
        # PB_HOST=https://api.pandorabots.com
        ENV . "\n";
    }

    private static function gitignoreTemplate(): string
    {
        return <<<GIT
        # Secrets — never commit
        .env
        .env.local

        # Local push/pull cache (regenerated automatically)
        .pb-migrate-cache.json
        GIT . "\n";
    }

    private static function aimlTemplate(): string
    {
        return <<<AIML
        <?xml version="1.0" encoding="UTF-8"?>
        <aiml version="2.0">
            <category>
                <pattern>HELLO</pattern>
                <template>Hello, world.</template>
            </category>
        </aiml>
        AIML . "\n";
    }
}
