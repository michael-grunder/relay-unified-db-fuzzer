<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Runner;

use Mgrunder\RelayUnifiedDbFuzzer\Payload\PayloadEncoder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

final class CliServerModeExecutor
{
    public function __construct(
        private readonly string $phpBinary,
        private readonly string $documentRoot,
        private readonly string $host,
        private readonly int $port,
        private readonly int $workers,
        private readonly ?string $phpIni,
        private readonly string $logLevel,
        private readonly string $redisHost,
        private readonly int $redisPort,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{
     *     meta: array<string, int|string>,
     *     workers: list<array{worker:int, operations:list<array{op:int, cmd:string, args:array}>}>
     * } $payload
     * @return array{pids:list<int>, failures:int}
     */
    public function run(array $payload): array
    {
        $this->logger->info('Starting cli-server mode', [
            'host' => $this->host,
            'port' => $this->port,
            'workers' => $this->workers,
        ]);
        $server = $this->bootServer();
        $pid = $server->getPid() ?: 0;

        if (!$this->waitForServerReady()) {
            $this->shutdownServer($server);
            throw new \RuntimeException('PHP built-in server did not become ready');
        }
        if (!$this->sanityCheckServer()) {
            $this->shutdownServer($server);
            throw new \RuntimeException('CLI server sanity check failed');
        }

        $failures = 0;
        $requestPayloads = $this->chunkRequests($payload);
        foreach ($requestPayloads as $chunk) {
            $encoded = PayloadEncoder::toSerialized($chunk);
            $result = $this->sendRequest($encoded, $chunk['worker']);
            if (!$result) {
                $failures++;
            }
        }

        $this->shutdownServer($server);

        return [
            'pids' => $pid > 0 ? [$pid] : [],
            'failures' => $failures,
        ];
    }

    private function bootServer(): Process
    {
        $cmd = [$this->phpBinary];
        if ($this->phpIni !== null) {
            $cmd[] = '-c';
            $cmd[] = $this->phpIni;
        }
        $cmd[] = '-S';
        $cmd[] = sprintf('%s:%d', $this->host, $this->port);
        $cmd[] = '-t';
        $cmd[] = $this->documentRoot;

        $env = [
            'PHP_CLI_SERVER_WORKERS' => (string)$this->workers,
            'RELAY_FUZZ_LOG_LEVEL' => $this->logLevel,
            'RELAY_REDIS_HOST' => $this->redisHost,
            'RELAY_REDIS_PORT' => (string)$this->redisPort,
        ];

        if ($this->phpIni !== null) {
            $env['PHPRC'] = $this->phpIni;
        }

        $this->logger->debug('Booting php built-in server', [
            'cmd' => $cmd,
            'env' => $env,
        ]);
        $process = new Process($cmd, $this->documentRoot, $env);
        $process->start(function (string $type, string $buffer): void {
            $this->logger->debug('CLI server output', [
                'type' => $type,
                'buffer' => trim($buffer),
            ]);
        });

        return $process;
    }

    /**
     * @param array{
     *     meta: array<string, mixed>,
     *     workers: list<array{worker:int, operations:list<array{op:int, cmd:string, args:array}>}>
     * } $payload
     * @return list<array<string, mixed>>
     */
    private function chunkRequests(array $payload): array
    {
        $chunks = [];
        foreach ($payload['workers'] as $worker) {
            $chunks[] = [
                'meta' => $payload['meta'],
                'worker' => $worker['worker'],
                'operations' => $worker['operations'],
            ];
        }

        return $chunks;
    }

    private function waitForServerReady(): bool
    {
        $attempts = 0;
        $maxAttempts = 30;
        $sleepUsec = 100000;

        while ($attempts < $maxAttempts) {
            $fp = @fsockopen($this->host, $this->port, $errno, $errstr, 0.1);
            if ($fp) {
                fclose($fp);
                $this->logger->debug('CLI server ready', ['attempts' => $attempts + 1]);
                return true;
            }

            usleep($sleepUsec);
            $attempts++;
        }

        $this->logger->error('CLI server did not become ready', [
            'attempts' => $attempts,
            'host' => $this->host,
            'port' => $this->port,
        ]);
        return false;
    }

    private function sanityCheckServer(): bool
    {
        $query = http_build_query([
            'host' => $this->redisHost,
            'port' => $this->redisPort,
            'flush' => '1',
        ]);
        $endpoint = $this->endpoint('/check.php') . '?' . $query;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
            ],
        ]);
        $response = @file_get_contents($endpoint, false, $context);
        if ($response === false) {
            $this->logger->error('Failed to hit cli-server sanity check endpoint', [
                'endpoint' => $endpoint,
            ]);
            return false;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || ($decoded['status'] ?? null) !== 'ok') {
            $this->logger->error('CLI server sanity check reported failure', [
                'endpoint' => $endpoint,
                'response' => $response,
            ]);
            return false;
        }

        $this->logger->debug('CLI server sanity check passed', [
            'endpoint' => $endpoint,
            'response' => $decoded,
        ]);
        return true;
    }

    private function sendRequest(string $payload, int $worker): bool
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/octet-stream\r\n",
                'content' => $payload,
                'timeout' => 10,
            ],
        ]);

        $endpoint = $this->endpoint('/fuzz.php');
        $response = @file_get_contents($endpoint, false, $context);
        if ($response === false) {
            $this->logger->error('Failed to POST payload to cli-server endpoint', [
                'endpoint' => $endpoint,
                'worker' => $worker,
            ]);
            return false;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || ($decoded['status'] ?? null) !== 'ok') {
            $this->logger->error('CLI server reported failure', [
                'response' => $response,
                'worker' => $worker,
            ]);
            return false;
        }

        $this->logger->debug('CLI server request completed', [
            'worker' => $worker,
            'response' => $decoded,
        ]);
        return true;
    }

    private function shutdownServer(Process $process): void
    {
        if (!$process->isRunning()) {
            return;
        }

        $this->logger->debug('Shutting down cli-server');
        $process->signal(SIGTERM);
        $deadline = microtime(true) + 3.0;
        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(100000);
        }

        if ($process->isRunning()) {
            $this->logger->warning('CLI server did not stop gracefully, forcing kill');
            $process->signal(SIGKILL);
        }
    }

    private function endpoint(string $path): string
    {
        return sprintf('http://%s:%d%s', $this->host, $this->port, $path);
    }
}
