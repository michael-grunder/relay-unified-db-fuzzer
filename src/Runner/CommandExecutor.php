<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Runner;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class CommandExecutor
{
    public function __construct(
        private readonly \Relay\Relay $client,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    /**
     * @param array{cmd:string, args:array} $operation
     */
    public function execute(array $operation): void
    {
        $command = strtolower($operation['cmd'] ?? '');
        if ($command === '' || !method_exists($this->client, $command)) {
            $this->logger->error('Unsupported command', [
                'cmd' => $command,
                'args' => $operation['args'] ?? null,
            ]);
            throw new \RuntimeException(sprintf('Unsupported command "%s"', $command));
        }

        $args = $operation['args'] ?? [];
        $callable = [$this->client, $command];
        $this->logger->debug('Executing command', [
            'cmd' => $command,
            'args' => $args,
            'op' => $operation['op'] ?? null,
        ]);
        try {
            call_user_func_array($callable, $args);
        } catch (\Throwable $t) {
            $this->logger->error('Command execution error', [
                'cmd' => $command,
                'args' => $args,
                'op' => $operation['op'] ?? null,
                'exception' => $t,
            ]);
            throw $t;
        }
    }
}
