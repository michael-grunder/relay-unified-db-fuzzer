<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Payload;

use Mgrunder\RelayUnifiedDbFuzzer\Support\DeterministicRng;

final class ValueGenerator
{
    public function randomString(DeterministicRng $rng): string
    {
        $length = $rng->nextRange(0, 64);
        if ($length === 0) {
            return '';
        }

        return $rng->nextPrintableString($length);
    }

    public function smallInteger(DeterministicRng $rng): int
    {
        $values = [PHP_INT_MIN, PHP_INT_MAX, -1, 0, 1];
        if ($rng->chance(1, 4)) {
            return $rng->pick($values);
        }

        return $rng->nextRange(-1000, 1000);
    }

    public function positiveInteger(DeterministicRng $rng): int
    {
        if ($rng->chance(1, 5)) {
            return $rng->pick([0, 1, 2, 1000000]);
        }

        return $rng->nextRange(0, 10000);
    }

    public function score(DeterministicRng $rng): float
    {
        if ($rng->chance(1, 3)) {
            return (float)$this->smallInteger($rng);
        }

        return $rng->nextInt(100000) / 100.0;
    }
}
