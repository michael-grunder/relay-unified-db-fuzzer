<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Support;

final class RedisCommandStats
{
    private const CALLS_PATTERN = '/^calls=(\d+),.*$/';

    public function __construct(
        private readonly string $redisHost,
        private readonly int $redisPort
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function snapshotCounts(): array
    {
        if (!class_exists(\Relay\Relay::class)) {
            throw new \RuntimeException('Relay extension is not loaded');
        }

        $relay = new \Relay\Relay($this->redisHost, $this->redisPort);
        $stats = $relay->info('commandstats');
        if (!is_array($stats)) {
            throw new \RuntimeException('Unexpected commandstats response');
        }

        $counts = [];
        foreach ($stats as $cmd => $info) {
            $cmd = str_replace('cmd_', '', (string)$cmd);
            if (!preg_match(self::CALLS_PATTERN, (string)$info, $matches)) {
                continue;
            }
            $counts[$cmd] = (int)$matches[1];
        }

        return $counts;
    }
}
