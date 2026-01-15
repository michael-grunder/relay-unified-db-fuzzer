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
        private readonly bool $holdOpen,
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
        if ($this->holdOpen) {
            $this->logger->warning('CLI server hold enabled; leaving server running for inspection', [
                'endpoint' => $this->endpoint('/'),
                'fuzz_endpoint' => $this->endpoint('/fuzz.php', $this->buildEndpointQuery()),
                'sanity_endpoint' => $this->endpoint('/check.php', $this->buildEndpointQuery(['flush' => '1'])),
                'doc_root' => $this->documentRoot,
                'pid' => $pid,
            ]);
            $this->holdServerOpen($server);
            return [
                'pids' => $pid > 0 ? [$pid] : [],
                'failures' => 0,
            ];
        }

        $sanity = $this->sanityCheckServer();
        if (!$sanity['ok']) {
            $this->shutdownServer($server);
            throw new \RuntimeException($this->formatSanityCheckFailure($sanity));
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

    private function holdServerOpen(Process $process): void
    {
        while ($process->isRunning()) {
            usleep(500000);
        }

        $this->logger->warning('CLI server exited while holding open', [
            'exit_code' => $process->getExitCode(),
            'signal' => $process->getTermSignal(),
        ]);
    }

    /**
     * @return array{
     *     ok: bool,
     *     endpoint: string,
     *     message: ?string,
     *     trace: ?string,
     *     response: ?string
     * }
     */
    private function sanityCheckServer(): array
    {
        $endpoint = $this->endpoint('/check.php', $this->buildEndpointQuery(['flush' => '1']));
        $result = [
            'ok' => false,
            'endpoint' => $endpoint,
            'message' => null,
            'trace' => null,
            'response' => null,
        ];
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
            $result['message'] = 'Failed to hit cli-server sanity check endpoint';
            return $result;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            $this->logger->error('CLI server sanity check returned invalid JSON', [
                'endpoint' => $endpoint,
                'response' => $response,
            ]);
            $result['message'] = 'CLI server sanity check returned invalid JSON';
            $result['response'] = $response;
            return $result;
        }

        if (($decoded['status'] ?? null) !== 'ok') {
            $this->logger->error('CLI server sanity check reported failure', [
                'endpoint' => $endpoint,
                'response' => $response,
                'message' => $decoded['message'] ?? null,
                'exception' => $decoded['exception'] ?? null,
                'trace' => $decoded['trace'] ?? null,
            ]);
            $result['message'] = is_string($decoded['message'] ?? null) ? $decoded['message'] : 'CLI server sanity check failed';
            $result['trace'] = is_string($decoded['trace'] ?? null) ? $decoded['trace'] : null;
            $result['response'] = $response;
            return $result;
        }

        $this->logger->debug('CLI server sanity check passed', [
            'endpoint' => $endpoint,
            'response' => $decoded,
        ]);
        $result['ok'] = true;
        return $result;
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

        $endpoint = $this->endpoint('/fuzz.php', $this->buildEndpointQuery());
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

    private function endpoint(string $path, array $query = []): string
    {
        $url = sprintf('http://%s:%d%s', $this->host, $this->port, $path);
        if ($query === []) {
            return $url;
        }

        return $url . '?' . http_build_query($query);
    }

    /**
     * @param array<string, string|int> $extra
     * @return array<string, string|int>
     */
    private function buildEndpointQuery(array $extra = []): array
    {
        return $extra + [
            'log-level' => $this->logLevel,
            'redis-host' => $this->redisHost,
            'redis-port' => $this->redisPort,
        ];
    }

    /**
     * @param array{
     *     ok: bool,
     *     endpoint: string,
     *     message: ?string,
     *     trace: ?string,
     *     response: ?string
     * } $sanity
     */
    private function formatSanityCheckFailure(array $sanity): string
    {
        $detail = $sanity['message'] ?? 'unknown error';
        if ($sanity['trace']) {
            $detail .= "\n" . $sanity['trace'];
        } elseif ($sanity['response']) {
            $detail .= "\nResponse: " . $sanity['response'];
        }

        return sprintf('CLI server sanity check failed: %s', $detail);
    }
}
