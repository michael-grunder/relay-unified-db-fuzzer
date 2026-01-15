<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Runner;

final class CliServerCrashException extends \RuntimeException
{
    /**
     * @param list<array{pid:int, signal:int}> $crashSignals
     */
    public function __construct(string $message, private readonly array $crashSignals)
    {
        parent::__construct($message);
    }

    /**
     * @return list<array{pid:int, signal:int}>
     */
    public function crashSignals(): array
    {
        return $this->crashSignals;
    }
}
