<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Payload;

use Mgrunder\RelayUnifiedDbFuzzer\Support\DeterministicRng;
use Mgrunder\RelayUnifiedDbFuzzer\Support\SeedUtil;

final class PayloadGenerator
{
    public function __construct(
        private readonly CommandSet $commandSet,
        private readonly KeyGenerator $keys,
        private readonly ValueGenerator $values,
    ) {
    }

    /**
     * Build the deterministic payload for all workers.
     *
     * @return array{
     *     meta: array<string, int|string>,
     *     workers: list<array{worker:int, operations:list<array{op:int, cmd:string, args:array}>}>
     * }
     */
    public function generate(int $ops, int $workers, int $seed): array
    {
        $payload = [
            'meta' => [
                'seed' => $seed,
                'ops' => $ops,
                'workers' => $workers,
            ],
            'workers' => [],
        ];

        for ($worker = 0; $worker < $workers; $worker++) {
            $payload['workers'][] = [
                'worker' => $worker,
                'operations' => $this->buildWorkerOps($seed, $worker, $ops),
            ];
        }

        return $payload;
    }

    /**
     * @return list<array{op:int, cmd:string, args:array}>
     */
    private function buildWorkerOps(int $seed, int $workerIndex, int $ops): array
    {
        $operations = [];
        for ($opIndex = 0; $opIndex < $ops; $opIndex++) {
            $opSeed = SeedUtil::derive($seed, $workerIndex, $opIndex);
            $rng = new DeterministicRng($opSeed);
            $definition = $this->commandSet->pick($rng);
            $args = $definition->buildArgs($rng, $this->keys, $this->values);

            $operations[] = [
                'op' => $opIndex,
                'cmd' => $definition->name(),
                'args' => array_values($args),
            ];
        }

        return $operations;
    }
}

