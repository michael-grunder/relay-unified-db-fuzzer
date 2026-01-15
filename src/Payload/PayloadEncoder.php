<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Payload;

final class PayloadEncoder
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function toSerialized(array $payload): string
    {
        return serialize($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromSerialized(string $contents): array
    {
        $data = @unserialize($contents, ['allowed_classes' => false]);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid payload serialization');
        }

        return $data;
    }
}

