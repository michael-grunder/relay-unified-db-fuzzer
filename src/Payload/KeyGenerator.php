<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Payload;

use Mgrunder\RelayUnifiedDbFuzzer\Support\DeterministicRng;

final class KeyGenerator
{
    public function __construct(
        private readonly int $keyspace = 256,
        private readonly int $hotKeyCount = 8,
        private readonly int $hotKeyBias = 4, // 1 in N chance to pick cold key
    ) {
    }

    public function pickKey(DeterministicRng $rng): string
    {
        $hot = $this->hotKeyCount > 0 && !$rng->chance(1, $this->hotKeyBias);
        if ($hot) {
            $index = $rng->nextInt(max(1, min($this->keyspace, $this->hotKeyCount)));
        } else {
            $index = $rng->nextInt(max(1, $this->keyspace));
        }

        return sprintf('k:%04d', $index);
    }

    /**
     * @return list<string>
     */
    public function pickMultiple(DeterministicRng $rng, int $count): array
    {
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $keys[] = $this->pickKey($rng);
        }

        return $keys;
    }

    public function pickField(DeterministicRng $rng): string
    {
        return sprintf('f:%s', $rng->nextPrintableString(6));
    }

    public function pickMember(DeterministicRng $rng): string
    {
        return sprintf('m:%s', $rng->nextPrintableString(8));
    }
}

