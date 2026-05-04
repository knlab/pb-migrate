<?php

declare(strict_types=1);

namespace KnLab\PbMigrate\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'debug', description: 'Send input to a bot with trace and print the response + a formatted trace summary (use --json for raw JSON)')]
final class DebugCommand extends AbstractBotCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('input', InputArgument::REQUIRED, 'User input to send to the bot');
        $this->addOption('client-name', null, InputOption::VALUE_REQUIRED, 'client_name', '');
        $this->addOption('session', null, InputOption::VALUE_REQUIRED, 'sessionid', '');
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Reset conversation state');
        $this->addOption('extra', null, InputOption::VALUE_NONE, 'Include extra information in trace');
        $this->addOption('reload', null, InputOption::VALUE_NONE, 'Reload bot before processing');
        $this->addOption('no-trace', null, InputOption::VALUE_NONE, 'Disable trace (default: enabled)');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the raw JSON response (jq-friendly) instead of the formatted view');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = $this->style($input, $output);
        $config = $this->loadConfig($input);
        $client = $this->client($config);
        $bot = $this->resolveBot($config, $input);

        $reply = $client->debug(
            input: (string) $input->getArgument('input'),
            botname: $bot->name,
            clientName: (string) $input->getOption('client-name'),
            sessionId: (string) $input->getOption('session'),
            reset: (bool) $input->getOption('reset'),
            extra: (bool) $input->getOption('extra'),
            trace: !$input->getOption('no-trace'),
            reload: (bool) $input->getOption('reload'),
        );

        if ($input->getOption('json')) {
            $io->writeln(json_encode($reply, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            return Command::SUCCESS;
        }

        $this->renderFormatted($io, $reply);
        return Command::SUCCESS;
    }

    private function renderFormatted(SymfonyStyle $io, \stdClass $reply): void
    {
        $responses = $reply->responses ?? [];
        $io->writeln('');
        $io->writeln('<info>Response:</info>');
        if (is_array($responses) && $responses !== []) {
            foreach ($responses as $line) {
                $io->writeln(sprintf('  <options=bold>%s</>', (string) $line));
            }
        } else {
            $io->writeln('  <comment>(empty)</comment>');
        }

        $trace = $reply->trace ?? null;
        if (!is_array($trace) || $trace === []) {
            $io->writeln('');
            $io->writeln('<comment>This bot did not return a trace (older bot or trace disabled).</comment>');
            $this->renderSession($io, $reply);
            return;
        }

        $io->writeln('');
        $io->writeln(sprintf('<info>Trace (%d steps):</info>', count($trace)));
        foreach ($trace as $step) {
            if (!is_object($step)) {
                continue;
            }
            $this->renderStep($io, $step);
        }

        $this->renderSession($io, $reply);
    }

    private function renderStep(SymfonyStyle $io, \stdClass $step): void
    {
        $type = (string) ($step->type ?? '');
        $level = (int) ($step->level ?? 0);
        $indent = str_repeat('  ', $level);

        $color = match ($type) {
            'begin', 'srai-begin', 'sraix-begin' => 'cyan',
            'match' => 'yellow',
            'end', 'srai-end', 'sraix-end' => 'green',
            default => 'default',
        };

        $io->writeln(sprintf('%s<fg=%s>L%d  %s</>', $indent, $color, $level, $type));

        switch ($type) {
            case 'begin':
            case 'srai-begin':
            case 'sraix-begin':
                $this->renderInput($io, $indent, $step);
                if ($type === 'sraix-begin' && isset($step->bot)) {
                    $io->writeln(sprintf('%s    bot: <options=bold>%s</>', $indent, (string) $step->bot));
                }
                break;
            case 'match':
                $matched = isset($step->matched) && is_array($step->matched)
                    ? implode(' ', array_map('strval', $step->matched))
                    : '(none)';
                $io->writeln(sprintf('%s    pattern:  <options=bold>%s</>', $indent, $matched));
                if (isset($step->filename)) {
                    $io->writeln(sprintf('%s    file:     %s', $indent, (string) $step->filename));
                }
                if (isset($step->template)) {
                    $io->writeln(sprintf('%s    template: %s', $indent, (string) $step->template));
                }
                break;
            case 'end':
            case 'srai-end':
            case 'sraix-end':
                $result = isset($step->result) && is_array($step->result)
                    ? implode(' ', array_map('strval', array_filter($step->result, static fn ($v) => $v !== '' && $v !== ' ' && $v !== "\n")))
                    : '(none)';
                $io->writeln(sprintf('%s    result: <options=bold>%s</>', $indent, $result));
                break;
            default:
                $io->writeln(sprintf('%s    <comment>%s</comment>', $indent, json_encode($step, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''));
                break;
        }
    }

    private function renderInput(SymfonyStyle $io, string $indent, \stdClass $step): void
    {
        $tokens = isset($step->input) && is_array($step->input)
            ? implode(' ', array_map('strval', $step->input))
            : '';
        if ($tokens !== '') {
            $io->writeln(sprintf('%s    input: %s', $indent, $tokens));
        }
    }

    private function renderSession(SymfonyStyle $io, \stdClass $reply): void
    {
        $sessionId = $reply->sessionid ?? null;
        if ($sessionId !== null) {
            $io->writeln('');
            $io->writeln(sprintf('Session: %s', (string) $sessionId));
        }
    }
}
