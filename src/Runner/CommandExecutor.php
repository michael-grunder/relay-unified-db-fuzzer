<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Runner;

final class CommandExecutor
{
    public function __construct(
        private readonly \Relay\Relay $client
    ) {
    }

    /**
     * @param array{cmd:string, args:array} $operation
     */
    public function execute(array $operation): void
    {
        $command = strtolower($operation['cmd'] ?? '');
        if ($command === '' || !method_exists($this->client, $command)) {
            throw new \RuntimeException(sprintf('Unsupported command "%s"', $command));
        }

        $args = $operation['args'] ?? [];
        $callable = [$this->client, $command];
        @call_user_func_array($callable, $args);
    }
}
