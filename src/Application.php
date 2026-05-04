<?php

declare(strict_types=1);

namespace KnLab\PbMigrate;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;

final class Application extends BaseApplication
{
    public function __construct(
        string $name = 'pb-migrate',
        string $version = '0.7.1',
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
