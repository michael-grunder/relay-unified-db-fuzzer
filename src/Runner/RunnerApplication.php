<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Runner;

use Mgrunder\RelayUnifiedDbFuzzer\Payload\CommandRegistry;
use Mgrunder\RelayUnifiedDbFuzzer\Payload\KeyGenerator;
use Mgrunder\RelayUnifiedDbFuzzer\Payload\PayloadEncoder;
use Mgrunder\RelayUnifiedDbFuzzer\Payload\PayloadGenerator;
use Mgrunder\RelayUnifiedDbFuzzer\Payload\ValueGenerator;
use Psr\Log\LoggerInterface;

final class RunnerApplication
{
    public function __construct(
        private readonly RunnerOptions $options,
        private readonly string $projectRoot,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(): int
    {
        $artifactDir = $this->ensureArtifactDir();
        $this->logger->info('Runner starting', [
            'artifact_dir' => $artifactDir,
            'mode' => $this->options->mode,
            'log_level' => $this->options->logLevel,
            'redis_host' => $this->options->redisHost,
            'redis_port' => $this->options->redisPort,
        ]);
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

        $this->logger->info('Generated payload', [
            'ops' => $this->options->ops,
            'workers' => $this->options->workers,
            'seed' => $this->options->seed,
            'allowed_commands' => $this->options->commands,
            'total_operations' => $this->countOperations($payload),
        ]);

        $payloadBin = $artifactDir . '/payload.bin';
        $payloadJson = $artifactDir . '/payload.json';
        file_put_contents($payloadBin, PayloadEncoder::toSerialized($payload));
        file_put_contents($payloadJson, PayloadEncoder::toJson($payload));

        $result = ['pids' => [], 'failures' => 0];
        $error = null;
        $errorClass = null;
        $errorTrace = null;

        try {
            $result = $this->options->mode === 'cli-server'
                ? $this->runCliServerMode($payload)
                : $this->runForkMode($payload);
        } catch (\Throwable $t) {
            $error = $t->getMessage();
            $errorClass = $t::class;
            $errorTrace = $t->getTraceAsString();
            $result['failures'] = max(1, $result['failures']);
            $this->logger->error('Runner exception', [
                'exception' => $t,
                'mode' => $this->options->mode,
            ]);
        }

        $summary = [
            'mode' => $this->options->mode,
            'seed' => $this->options->seed,
            'pids' => $result['pids'],
            'failures' => $result['failures'],
            'payload_bin' => $payloadBin,
            'payload_json' => $payloadJson,
            'error' => $error,
            'error_class' => $errorClass,
            'error_trace' => $errorTrace,
        ];
        file_put_contents($artifactDir . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT));
        if ($result['failures'] > 0) {
            $this->logger->warning('Runner reported failures', [
                'failures' => $result['failures'],
                'pids' => $result['pids'],
            ]);
        }

        return $result['failures'] === 0 && $error === null ? 0 : 1;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{pids:list<int>, failures:int}
     */
    private function runForkMode(array $payload): array
    {
        $executor = new ForkModeExecutor(
            new RelayFactory($this->options->phpIni, $this->options->redisHost, $this->options->redisPort),
            $this->logger
        );
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
            $this->options->phpIni,
            $this->options->logLevel,
            $this->options->redisHost,
            $this->options->redisPort,
            $this->logger
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

    /**
     * @param array{workers:list<array{operations:list<array{op:int, cmd:string, args:array}>}>} $payload
     */
    private function countOperations(array $payload): int
    {
        $count = 0;
        foreach ($payload['workers'] as $worker) {
            $count += count($worker['operations'] ?? []);
        }

        return $count;
    }
}
