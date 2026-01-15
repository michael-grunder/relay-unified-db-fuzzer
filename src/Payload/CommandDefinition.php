<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Payload;

use Mgrunder\RelayUnifiedDbFuzzer\Support\DeterministicRng;

final class CommandDefinition
{
    private readonly int $paramCount;

    public function __construct(
        private readonly string $name,
        private readonly string $family,
        private readonly \Closure $argBuilder,
    ) {
        $reflection = new \ReflectionFunction($argBuilder);
        $this->paramCount = max(0, min(3, $reflection->getNumberOfParameters()));
    }

    public function name(): string
    {
        return $this->name;
    }

    public function family(): string
    {
        return $this->family;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function buildArgs(
        DeterministicRng $rng,
        KeyGenerator $keys,
        ValueGenerator $values
    ): array {
        $args = [$rng, $keys, $values];

        return ($this->argBuilder)(...array_slice($args, 0, $this->paramCount));
    }
}
