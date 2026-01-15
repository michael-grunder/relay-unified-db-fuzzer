<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Runner;

use Psr\Log\LoggerInterface;

final class ForkModeExecutor
{
    public function __construct(
        private readonly RelayFactory $relayFactory,
        private readonly LoggerInterface $logger
    ) {
        if (!function_exists('pcntl_fork')) {
            throw new \RuntimeException('pcntl extension is required for fork mode');
        }
    }

    /**
     * @param list<array{worker:int, operations:list<array{cmd:string,args:array,op:int}>}> $workers
     * @return array{pids:list<int>, failures:int, crash_signals:list<array{pid:int, signal:int}>}
     */
    public function run(array $workers): array
    {
        $childPids = [];
        $failures = 0;
        $crashSignals = [];

        foreach ($workers as $workerPayload) {
            $this->logger->debug('Spawning worker', [
                'worker' => $workerPayload['worker'] ?? null,
                'operations' => isset($workerPayload['operations']) ? count($workerPayload['operations']) : 0,
            ]);
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
            if (pcntl_wifsignaled($status)) {
                $signal = pcntl_wtermsig($status);
                if (CrashSignals::isCrashSignal($signal)) {
                    $crashSignals[] = ['pid' => $pid, 'signal' => $signal];
                }
            }
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $failures++;
            }
        }

        return ['pids' => $childPids, 'failures' => $failures, 'crash_signals' => $crashSignals];
    }

    /**
     * @param array{worker:int, operations:list<array{cmd:string,args:array,op:int}>} $payload
     */
    private function runChild(array $payload): void
    {
        $relay = $this->relayFactory->create();
        $executor = new CommandExecutor($relay, $this->logger);
        $worker = $payload['worker'];

        $this->logger->debug('Worker started', [
            'worker' => $worker,
            'operations' => count($payload['operations']),
        ]);

        foreach ($payload['operations'] as $operation) {
            try {
                $executor->execute($operation);
            } catch (\Throwable $t) {
                $this->logger->error('Worker operation failed', [
                    'worker' => $worker,
                    'op' => $operation['op'] ?? null,
                    'cmd' => $operation['cmd'] ?? null,
                    'args' => $operation['args'] ?? null,
                    'exception' => $t,
                ]);
                exit(1);
            }
        }
    }
}
