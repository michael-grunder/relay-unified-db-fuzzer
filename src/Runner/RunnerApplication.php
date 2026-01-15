<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Runner;

use Mgrunder\RelayUnifiedDbFuzzer\Payload\CommandRegistry;
use Mgrunder\RelayUnifiedDbFuzzer\Payload\KeyGenerator;
use Mgrunder\RelayUnifiedDbFuzzer\Payload\PayloadEncoder;
use Mgrunder\RelayUnifiedDbFuzzer\Payload\PayloadGenerator;
use Mgrunder\RelayUnifiedDbFuzzer\Payload\ValueGenerator;

final class RunnerApplication
{
    public function __construct(
        private readonly RunnerOptions $options,
        private readonly string $projectRoot,
    ) {
    }

    public function run(): int
    {
        $artifactDir = $this->ensureArtifactDir();
        $registry = new CommandRegistry();
        $commandSet = $registry->allowlist($this->options->commands);
        $payloadGenerator = new PayloadGenerator(
            $commandSet,
            new KeyGenerator(),
            new ValueGenerator()
        );

        $payload = $payloadGenerator->generate(
            $this->options->ops,
            $this->options->workers,
            $this->options->seed
        );

        $payloadBin = $artifactDir . '/payload.bin';
        $payloadJson = $artifactDir . '/payload.json';
        file_put_contents($payloadBin, PayloadEncoder::toSerialized($payload));
        file_put_contents($payloadJson, PayloadEncoder::toJson($payload));

        $result = ['pids' => [], 'failures' => 0];
        $error = null;

        try {
            $result = $this->options->mode === 'cli-server'
                ? $this->runCliServerMode($payload)
                : $this->runForkMode($payload);
        } catch (\Throwable $t) {
            $error = $t->getMessage();
            $result['failures'] = max(1, $result['failures']);
            fwrite(STDERR, "Runner error: {$error}\n");
        }

        $summary = [
            'mode' => $this->options->mode,
            'seed' => $this->options->seed,
            'pids' => $result['pids'],
            'failures' => $result['failures'],
            'payload_bin' => $payloadBin,
            'payload_json' => $payloadJson,
            'error' => $error,
        ];
        file_put_contents($artifactDir . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT));

        return $result['failures'] === 0 && $error === null ? 0 : 1;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{pids:list<int>, failures:int}
     */
    private function runForkMode(array $payload): array
    {
        $executor = new ForkModeExecutor(new RelayFactory($this->options->phpIni));
        return $executor->run($payload['workers']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{pids:list<int>, failures:int}
     */
    private function runCliServerMode(array $payload): array
    {
        $executor = new CliServerModeExecutor(
            $this->options->phpBinary,
            $this->projectRoot . '/public',
            $this->options->host,
            $this->options->port,
            $this->options->workers,
            $this->options->phpIni
        );

        return $executor->run($payload);
    }

    private function ensureArtifactDir(): string
    {
        $dir = $this->options->artifactDir ?? (sys_get_temp_dir() . '/relay-run-' . uniqid());
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Failed to create artifact directory "%s"', $dir));
        }

        return realpath($dir) ?: $dir;
    }
}
