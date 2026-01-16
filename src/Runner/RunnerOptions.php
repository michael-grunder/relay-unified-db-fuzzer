<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Runner;

final class RunnerOptions
{
    /**
     * @param list<string> $commands
     */
    public function __construct(
        public readonly string $phpBinary,
        public readonly ?string $phpIni,
        public readonly int $ops,
        public readonly int $workers,
        public readonly int $concurrentRequests,
        public readonly int $seed,
        public readonly string $mode,
        public readonly ?string $artifactDir,
        public readonly bool $rr = false,
        public readonly ?string $rrTraceDir = null,
        public readonly array $commands = [],
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 8080,
        public readonly string $redisHost = 'localhost',
        public readonly int $redisPort = 6379,
        public readonly string $logLevel = 'info',
        public readonly bool $cliServerHold = false,
    ) {
    }
}
