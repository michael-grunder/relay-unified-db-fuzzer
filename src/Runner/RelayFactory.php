<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Runner;

final class RelayFactory
{
    public function __construct(
        private readonly ?string $phpIni = null,
        private readonly string $redisHost = 'localhost',
        private readonly int $redisPort = 6379,
    ) {
    }

    public function create(): \Relay\Relay
    {
        if (!class_exists(\Relay\Relay::class)) {
            throw new \RuntimeException('Relay extension is not loaded');
        }

        // Future: customize connection/ini handling.
        return new \Relay\Relay($this->redisHost, $this->redisPort);
    }
}
