<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Payload;

use Mgrunder\RelayUnifiedDbFuzzer\Support\DeterministicRng;

final class CommandSet
{
    /**
     * @param list<CommandDefinition> $definitions
     */
    public function __construct(
        private readonly array $definitions
    ) {
        if ($definitions === []) {
            throw new \InvalidArgumentException('Command allow-list produced an empty set');
        }
    }

    /**
     * @return list<CommandDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    public function pick(DeterministicRng $rng): CommandDefinition
    {
        return $this->definitions[$rng->nextInt(count($this->definitions))];
    }
}

