<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Fuzzer;

/**
 * Parsed options for the outer fuzzer CLI.
 *
 * @param list<string> $commands
 */
final class FuzzerOptions
{
    /**
     * @param list<string> $commands
     */
    public function __construct(
        public readonly string $phpBinary,
        public readonly string $phpIni,
        public readonly int $ops,
        public readonly int $runs,
        public readonly int $workers,
        public readonly string $mode,
        public readonly array $commands = [],
        public readonly ?int $seed = null,
        public readonly string $host = '127.0.0.1',
        public readonly int $port = 8080,
        public readonly string $reproducersDir = 'reproducers',
    ) {
    }
}
