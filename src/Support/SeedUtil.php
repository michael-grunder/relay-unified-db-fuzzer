<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Support;

final class SeedUtil
{
    /**
     * Derive a deterministic child seed from a parent seed plus additional salts.
     *
     * @param int $seed
     * @param int ...$parts
     */
    public static function derive(int $seed, int ...$parts): int
    {
        $payload = pack('J', $seed);
        foreach ($parts as $part) {
            $payload .= pack('J', $part);
        }

        $hash = hash('sha256', $payload, true);
        $unpacked = unpack('Jvalue', substr($hash, 0, 8));

        return (int)$unpacked['value'];
    }

    public static function combine(int ...$parts): int
    {
        return self::derive(0xEA71C5EEDBEEF, ...$parts);
    }
}

