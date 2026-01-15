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
            'rr' => $this->options->rr,
            'rr_trace_dir' => $this->options->rrTraceDir,
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

        $result = ['pids' => [], 'failures' => 0, 'crash_signals' => []];
        $error = null;
        $errorClass = null;
        $errorTrace = null;
        $rrTraceDir = null;

        try {
            $result = $this->options->mode === 'cli-server'
                ? $this->runCliServerMode($payload)
                : $this->runForkMode($payload);
        } catch (CliServerCrashException $t) {
            $result['crash_signals'] = $t->crashSignals();
            $error = $t->getMessage();
            $errorClass = $t::class;
            $errorTrace = $t->getTraceAsString();
            $result['failures'] = max(1, $result['failures']);
            $this->logger->error('Runner exception', [
                'exception' => $t,
                'mode' => $this->options->mode,
            ]);
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

        $rrTraceDir = $this->finalizeRrTrace($artifactDir, $result['crash_signals']);
        $summary = [
            'mode' => $this->options->mode,
            'seed' => $this->options->seed,
            'pids' => $result['pids'],
            'failures' => $result['failures'],
            'crash_signals' => $result['crash_signals'],
            'payload_bin' => $payloadBin,
            'payload_json' => $payloadJson,
            'rr_trace_dir' => $rrTraceDir,
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
     * @return array{pids:list<int>, failures:int, crash_signals:list<array{pid:int, signal:int}>}
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
     * @return array{pids:list<int>, failures:int, crash_signals:list<array{pid:int, signal:int}>}
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
            $this->options->cliServerHold,
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

    /**
     * @param list<array{pid:int, signal:int}> $crashSignals
     */
    private function finalizeRrTrace(string $artifactDir, array $crashSignals): ?string
    {
        if (!$this->options->rr) {
            return null;
        }

        $traceRoot = $this->options->rrTraceDir;
        if ($traceRoot === null) {
            $traceRoot = getenv('RELAY_RR_TRACE_DIR') ?: getenv('_RR_TRACE_DIR') ?: null;
        }
        if ($traceRoot === null || !is_dir($traceRoot)) {
            return null;
        }

        $traceDir = $this->findRrTraceDir($traceRoot);
        if ($traceDir === null) {
            return null;
        }

        if ($crashSignals === []) {
            $this->removePath($traceRoot);
            return null;
        }

        $destination = $artifactDir . '/rr-trace';
        if (file_exists($destination)) {
            $destination = $artifactDir . '/rr-trace-' . uniqid();
        }

        if ($traceDir === $traceRoot) {
            if (!rename($traceRoot, $destination)) {
                $this->logger->warning('Failed to rename rr trace directory', [
                    'src' => $traceRoot,
                    'dst' => $destination,
                ]);
                return null;
            }
            return $destination;
        }

        if (!rename($traceDir, $destination)) {
            $this->logger->warning('Failed to move rr trace directory', [
                'src' => $traceDir,
                'dst' => $destination,
            ]);
            return null;
        }

        $this->removePath($traceRoot);
        return $destination;
    }

    private function findRrTraceDir(string $traceRoot): ?string
    {
        $candidate = null;
        $hasEntry = false;
        foreach (new \DirectoryIterator($traceRoot) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            $hasEntry = true;
            if ($entry->isDir()) {
                $candidate ??= $entry->getPathname();
                continue;
            }
        }

        if (!$hasEntry) {
            return null;
        }

        return $candidate ?? $traceRoot;
    }

    private function removePath(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
