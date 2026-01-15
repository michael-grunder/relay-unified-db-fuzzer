<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Payload;

use Mgrunder\RelayUnifiedDbFuzzer\Support\DeterministicRng;

final class CommandRegistry
{
    /**
     * @var array<string, CommandDefinition>
     */
    private array $definitions = [];

    /**
     * @var array<string, list<string>>
     */
    private array $families = [];

    public function __construct()
    {
        $this->registerDefaults();
    }

    /**
     * @param list<string> $filters
     */
    public function allowlist(array $filters): CommandSet
    {
        if ($filters === []) {
            return new CommandSet(array_values($this->definitions));
        }

        $filters = array_map(static fn(string $value): string => strtolower($value), $filters);
        $selected = [];

        foreach ($filters as $filter) {
            if (isset($this->families[$filter])) {
                foreach ($this->families[$filter] as $cmd) {
                    $selected[$cmd] = $this->definitions[$cmd];
                }
                continue;
            }

            if (isset($this->definitions[$filter])) {
                $selected[$filter] = $this->definitions[$filter];
                continue;
            }

            throw new \InvalidArgumentException(sprintf('Unknown command or family "%s"', $filter));
        }

        return new CommandSet(array_values($selected));
    }

    /**
     * @return list<string>
     */
    public function families(): array
    {
        return array_keys($this->families);
    }

    /**
     * @return list<string>
     */
    public function commands(): array
    {
        return array_keys($this->definitions);
    }

    private function register(string $family, string $name, \Closure $builder): void
    {
        $name = strtolower($name);
        $family = strtolower($family);
        $definition = new CommandDefinition($name, $family, $builder);
        $this->definitions[$name] = $definition;
        $this->families[$family] ??= [];
        $this->families[$family][] = $name;
    }

    private function registerDefaults(): void
    {
        $this->registerStringCommands();
        $this->registerHashCommands();
        $this->registerListCommands();
        $this->registerSetCommands();
        $this->registerZsetCommands();
    }

    private function registerStringCommands(): void
    {
        $this->register('string', 'set', function (
            DeterministicRng $rng,
            KeyGenerator $keys,
            ValueGenerator $values
        ): array {
            $options = null;
            if ($rng->chance(3, 5)) {
                $options = [];
                if ($rng->chance(1, 2)) {
                    $options['nx'] = true;
                } elseif ($rng->chance(1, 2)) {
                    $options['xx'] = true;
                }

                if ($rng->chance(2, 3)) {
                    if ($rng->chance(1, 2)) {
                        $options['ex'] = max(1, $values->positiveInteger($rng));
                    } else {
                        $options['px'] = max(1, $values->positiveInteger($rng)) * 10;
                    }
                }
            }

            $args = [$keys->pickKey($rng), $values->randomString($rng)];
            if ($options !== null && $options !== []) {
                $args[] = $options;
            }

            return $args;
        });

        $this->register('string', 'get', fn(DeterministicRng $rng, KeyGenerator $keys): array => [
            $keys->pickKey($rng),
        ]);

        $this->register('string', 'mget', function (
            DeterministicRng $rng,
            KeyGenerator $keys
        ): array {
            $count = max(1, $rng->nextRange(2, 4));
            return [
                $keys->pickMultiple($rng, $count),
            ];
        });

        $this->register('string', 'del', function (
            DeterministicRng $rng,
            KeyGenerator $keys
        ): array {
            $count = $rng->nextRange(1, 4);
            return $keys->pickMultiple($rng, $count);
        });

        $this->register('string', 'incr', fn(DeterministicRng $rng, KeyGenerator $keys): array => [
            $keys->pickKey($rng),
        ]);
        $this->register('string', 'decr', fn(DeterministicRng $rng, KeyGenerator $keys): array => [
            $keys->pickKey($rng),
        ]);
        $this->register('string', 'append', function (
            DeterministicRng $rng,
            KeyGenerator $keys,
            ValueGenerator $values
        ): array {
            return [$keys->pickKey($rng), $values->randomString($rng)];
        });
    }

    private function registerHashCommands(): void
    {
        $this->register('hash', 'hset', function (
            DeterministicRng $rng,
            KeyGenerator $keys,
            ValueGenerator $values
        ): array {
            $argCount = $rng->nextRange(1, 3);
            $args = [$keys->pickKey($rng)];
            for ($i = 0; $i < $argCount; $i++) {
                $args[] = $keys->pickField($rng);
                $args[] = $values->randomString($rng);
            }

            return $args;
        });

        $this->register('hash', 'hget', fn(DeterministicRng $rng, KeyGenerator $keys): array => [
            $keys->pickKey($rng),
            $keys->pickField($rng),
        ]);

        $this->register('hash', 'hmget', function (
            DeterministicRng $rng,
            KeyGenerator $keys
        ): array {
            $count = $rng->nextRange(1, 4);
            $fields = [];
            for ($i = 0; $i < $count; $i++) {
                $fields[] = $keys->pickField($rng);
            }

            return [$keys->pickKey($rng), $fields];
        });

        $this->register('hash', 'hdel', function (
            DeterministicRng $rng,
            KeyGenerator $keys
        ): array {
            $args = [$keys->pickKey($rng)];
            $count = $rng->nextRange(1, 4);
            for ($i = 0; $i < $count; $i++) {
                $args[] = $keys->pickField($rng);
            }

            return $args;
        });

        $this->register('hash', 'hincrby', function (
            DeterministicRng $rng,
            KeyGenerator $keys,
            ValueGenerator $values
        ): array {
            return [$keys->pickKey($rng), $keys->pickField($rng), $values->smallInteger($rng)];
        });
    }

    private function registerListCommands(): void
    {
        $this->register('list', 'lpush', fn(
            DeterministicRng $rng,
            KeyGenerator $keys,
            ValueGenerator $values
        ): array => $this->buildPushArgs($rng, $keys, $values));

        $this->register('list', 'rpush', fn(
            DeterministicRng $rng,
            KeyGenerator $keys,
            ValueGenerator $values
        ): array => $this->buildPushArgs($rng, $keys, $values));

        $this->register('list', 'lpop', function (
            DeterministicRng $rng,
            KeyGenerator $keys
        ): array {
            $count = $rng->chance(1, 2) ? $rng->nextRange(1, 3) : 1;
            return [$keys->pickKey($rng), $count];
        });

        $this->register('list', 'rpop', function (
            DeterministicRng $rng,
            KeyGenerator $keys
        ): array {
            $count = $rng->chance(1, 2) ? $rng->nextRange(1, 3) : 1;
            return [$keys->pickKey($rng), $count];
        });

        $this->register('list', 'lrange', function (
            DeterministicRng $rng,
            KeyGenerator $keys
        ): array {
            $start = $rng->nextRange(-10, 10);
            $stop = $start + $rng->nextRange(1, 10);
            return [$keys->pickKey($rng), $start, $stop];
        });

        $this->register('list', 'llen', fn(DeterministicRng $rng, KeyGenerator $keys): array => [
            $keys->pickKey($rng),
        ]);
    }

    private function registerSetCommands(): void
    {
        $this->register('set', 'sadd', fn(
            DeterministicRng $rng,
            KeyGenerator $keys
        ): array => $this->buildSetMembers($rng, $keys));

        $this->register('set', 'srem', fn(
            DeterministicRng $rng,
            KeyGenerator $keys
        ): array => $this->buildSetMembers($rng, $keys));

        $this->register('set', 'sismember', function (
            DeterministicRng $rng,
            KeyGenerator $keys
        ): array {
            return [$keys->pickKey($rng), $keys->pickMember($rng)];
        });

        $this->register('set', 'smembers', fn(DeterministicRng $rng, KeyGenerator $keys): array => [
            $keys->pickKey($rng),
        ]);

        $this->register('set', 'scard', fn(DeterministicRng $rng, KeyGenerator $keys): array => [
            $keys->pickKey($rng),
        ]);
    }

    private function registerZsetCommands(): void
    {
        $this->register('zset', 'zadd', function (
            DeterministicRng $rng,
            KeyGenerator $keys,
            ValueGenerator $values
        ): array {
            $pairs = $rng->nextRange(1, 4);
            $args = [$keys->pickKey($rng)];
            for ($i = 0; $i < $pairs; $i++) {
                $args[] = $values->score($rng);
                $args[] = $keys->pickMember($rng);
            }

            return $args;
        });

        $this->register('zset', 'zrem', fn(
            DeterministicRng $rng,
            KeyGenerator $keys
        ): array => $this->buildSetMembers($rng, $keys));

        $this->register('zset', 'zrange', function (
            DeterministicRng $rng,
            KeyGenerator $keys
        ): array {
            $start = $rng->nextRange(0, 25);
            $end = $start + $rng->nextRange(1, 25);
            $options = null;
            if ($rng->chance(1, 2)) {
                $options = ['withscores' => true];
            }

            $args = [$keys->pickKey($rng), $start, $end];
            if ($options !== null) {
                $args[] = $options;
            }

            return $args;
        });

        $this->register('zset', 'zcard', fn(DeterministicRng $rng, KeyGenerator $keys): array => [
            $keys->pickKey($rng),
        ]);
        $this->register('zset', 'zscore', function (
            DeterministicRng $rng,
            KeyGenerator $keys
        ): array {
            return [$keys->pickKey($rng), $keys->pickMember($rng)];
        });
    }

    /**
     * @return list<mixed>
     */
    private function buildPushArgs(
        DeterministicRng $rng,
        KeyGenerator $keys,
        ValueGenerator $values
    ): array {
        $count = $rng->nextRange(1, 4);
        $args = [$keys->pickKey($rng)];
        for ($i = 0; $i < $count; $i++) {
            $args[] = $values->randomString($rng);
        }

        return $args;
    }

    /**
     * @return list<mixed>
     */
    private function buildSetMembers(DeterministicRng $rng, KeyGenerator $keys): array
    {
        $count = $rng->nextRange(1, 4);
        $args = [$keys->pickKey($rng)];
        for ($i = 0; $i < $count; $i++) {
            $args[] = $keys->pickMember($rng);
        }

        return $args;
    }
}

