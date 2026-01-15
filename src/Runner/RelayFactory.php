<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Runner;

final class RelayFactory
{
    public function __construct(
        private readonly ?string $phpIni = null,
    ) {
    }

    public function create(): \Relay\Relay
    {
        if (!class_exists(\Relay\Relay::class)) {
            throw new \RuntimeException('Relay extension is not loaded');
        }

        // Future: customize connection/ini handling.
        return new \Relay\Relay();
    }
}

