<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Runner;

use Mgrunder\RelayUnifiedDbFuzzer\Support\SeedUtil;

final class ForkModeExecutor
{
    public function __construct(
        private readonly RelayFactory $relayFactory
    ) {
        if (!function_exists('pcntl_fork')) {
            throw new \RuntimeException('pcntl extension is required for fork mode');
        }
    }

    /**
     * @param list<array{worker:int, operations:list<array{cmd:string,args:array,op:int}>}> $workers
     * @return array{pids:list<int>, failures:int}
     */
    public function run(array $workers): array
    {
        $childPids = [];
        $failures = 0;

        foreach ($workers as $workerPayload) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('Failed to fork worker');
            }

            if ($pid === 0) {
                $this->runChild($workerPayload);
                exit(0);
            }

            $childPids[] = $pid;
        }

        foreach ($childPids as $pid) {
            pcntl_waitpid($pid, $status);
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $failures++;
            }
        }

        return ['pids' => $childPids, 'failures' => $failures];
    }

    /**
     * @param array{worker:int, operations:list<array{cmd:string,args:array,op:int}>} $payload
     */
    private function runChild(array $payload): void
    {
        $relay = $this->relayFactory->create();
        $executor = new CommandExecutor($relay);
        $worker = $payload['worker'];

        foreach ($payload['operations'] as $operation) {
            try {
                $executor->execute($operation);
            } catch (\Throwable $t) {
                fwrite(STDERR, sprintf(
                    "[worker:%d op:%d] %s\n",
                    $worker,
                    $operation['op'],
                    $t->getMessage()
                ));
                exit(1);
            }
        }
    }
}

