<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'compile', description: 'Compile (verify) one or more bots on Pandorabots')]
final class CompileCommand extends AbstractBotCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $client = $this->client($config);
        $bots = $this->resolveBots($config, $input);

        foreach ($bots as $bot) {
            $client->compile($bot->name);
            $io->writeln(sprintf('  <info>compiled</info> %s', $bot->name));
        }
        $io->success(sprintf('Compiled %d bot(s)', count($bots)));
        return Command::SUCCESS;
    }
}
