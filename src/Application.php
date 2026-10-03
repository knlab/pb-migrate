<?php

declare(strict_types=1);

namespace KnLab\PbMigrate;

use KnLab\PbMigrate\Exception\ApiErrorMessage;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Application extends BaseApplication
{
    public function __construct(
        string $name = 'pb-migrate',
        string $version = '0.7.2',
        ?PBClientFactory $factory = null,
    ) {
        parent::__construct($name, $version);
        $this->setDefaultCommand('repl');

        $factory ??= new PBClientFactory();

        foreach ($this->defaultCommandClasses() as $class) {
            $command = new $class();
            if ($command instanceof Command\AbstractBotCommand) {
                $command->setFactory($factory);
            }
            $this->add($command);
        }

        // Hide Symfony Console plumbing from the user-facing command list.
        // `list` / `help` / `completion` still work if typed (the REPL also
        // routes `?` and bare `help` to `list`), but they're transport-layer
        // and clutter the workflow-facing overview.
        foreach (['list', 'help', 'completion'] as $name) {
            if ($this->has($name)) {
                $this->get($name)->setHidden(true);
            }
        }
    }

    /**
     * No first argument → REPL.
     */
    protected function getCommandName(InputInterface $input): ?string
    {
        $first = $input->getFirstArgument();
        if ($first === null || $first === '') {
            return 'repl';
        }
        return $first;
    }

    /**
     * Symfony renders only getMessage(), which for ApiException no longer
     * carries the server's explanation (pb-php >= 2.1.4). Append it so a
     * direct CLI run shows the same detail the REPL does.
     */
    protected function doRenderThrowable(\Throwable $e, OutputInterface $output): void
    {
        parent::doRenderThrowable($e, $output);

        $detail = ApiErrorMessage::detail($e);
        if ($detail !== null) {
            $output->writeln(
                sprintf('<comment>Server message: %s</comment>', OutputFormatter::escape($detail)),
                OutputInterface::VERBOSITY_QUIET,
            );
            $output->writeln('', OutputInterface::VERBOSITY_QUIET);
        }
    }

    /**
     * @return list<class-string<\Symfony\Component\Console\Command\Command>>
     */
    private function defaultCommandClasses(): array
    {
        return [
            // Local registration management
            Command\AddCommand::class,
            Command\RemoveCommand::class,
            Command\ConfigCommand::class,
            // Bot lifecycle (remote)
            Command\BotListCommand::class,
            Command\BotRemoteCommand::class,
            Command\BotFilesCommand::class,
            Command\BotCreateCommand::class,
            Command\BotDeleteCommand::class,
            Command\CompileCommand::class,
            // File operations
            Command\CatCommand::class,
            Command\FileDeleteCommand::class,
            // Conversation
            Command\TalkCommand::class,
            Command\DebugCommand::class,
            Command\AtalkCommand::class,
            // Sync
            Command\PushCommand::class,
            Command\PullCommand::class,
            Command\DiffCommand::class,
            Command\ReportCommand::class,
            Command\StatusCommand::class,
            // Testing / batching
            Command\TestCommand::class,
            Command\BatchCommand::class,
            // Alters
            Command\AlterListCommand::class,
            Command\AlterSetCommand::class,
            Command\AlterUnsetCommand::class,
            Command\AlterResetCommand::class,
            // REPL
            Command\ReplCommand::class,
        ];
    }
}
