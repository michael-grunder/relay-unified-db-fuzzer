<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Support;

/**
 * Simple xorshift64* RNG used to produce deterministic pseudo-random numbers.
 */
final class DeterministicRng
{
    private int $state;

    public function __construct(int $seed)
    {
        if ($seed === 0) {
            $seed = 0x9E3779B185EBCA87;
        }

        $this->state = $seed & 0xFFFFFFFFFFFFFFFF;
    }

    public function nextUInt64(): int
    {
        $x = $this->state;
        $x ^= ($x << 7) & 0xFFFFFFFFFFFFFFFF;
        $x ^= ($x >> 9);
        $x ^= ($x << 8) & 0xFFFFFFFFFFFFFFFF;
        $this->state = $x & 0xFFFFFFFFFFFFFFFF;

        return $this->state;
    }

    public function nextInt(int $max): int
    {
        if ($max <= 0) {
            throw new \InvalidArgumentException('max must be positive');
        }

        return (int)($this->nextUInt64() % $max);
    }

    public function nextRange(int $min, int $max): int
    {
        if ($min === $max) {
            return $min;
        }

        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return $min + $this->nextInt(($max - $min) + 1);
    }

    public function chance(int $numerator, int $denominator): bool
    {
        if ($denominator <= 0) {
            throw new \InvalidArgumentException('denominator must be > 0');
        }

        $numerator = max(0, min($denominator, $numerator));
        if ($numerator === 0) {
            return false;
        }

        return $this->nextInt($denominator) < $numerator;
    }

    public function pick(array $items): mixed
    {
        if ($items === []) {
            throw new \InvalidArgumentException('Cannot pick from empty array');
        }

        return $items[$this->nextInt(count($items))];
    }

    public function nextBytes(int $length): string
    {
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr($this->nextInt(256));
        }

        return $bytes;
    }

    public function nextPrintableString(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789:_-';
        $result = '';
        $len = strlen($alphabet);

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[$this->nextInt($len)];
        }

        return $result;
    }
}

