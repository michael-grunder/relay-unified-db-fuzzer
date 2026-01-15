<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Runner;

use Mgrunder\RelayUnifiedDbFuzzer\Payload\PayloadEncoder;
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
        $server = $this->bootServer();
        $pid = $server->getPid() ?: 0;

        if (!$this->waitForServerReady()) {
            $this->shutdownServer($server);
            throw new \RuntimeException('PHP built-in server did not become ready');
        }

        $failures = 0;
        $requestPayloads = $this->chunkRequests($payload);
        foreach ($requestPayloads as $chunk) {
            $encoded = PayloadEncoder::toSerialized($chunk);
            $result = $this->sendRequest($encoded);
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

        $process = new Process($cmd, $this->documentRoot, $env);
        $process->start();

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
                return true;
            }

            usleep($sleepUsec);
            $attempts++;
        }

        return false;
    }

    private function sendRequest(string $payload): bool
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/octet-stream\r\n",
                'content' => $payload,
                'timeout' => 10,
            ],
        ]);

        $response = @file_get_contents($this->endpoint('/fuzz.php'), false, $context);
        if ($response === false) {
            fwrite(STDERR, "Failed to POST payload to cli-server endpoint\n");
            return false;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || ($decoded['status'] ?? null) !== 'ok') {
            fwrite(STDERR, "CLI server reported failure: {$response}\n");
            return false;
        }

        return true;
    }

    private function shutdownServer(Process $process): void
    {
        if (!$process->isRunning()) {
            return;
        }

        $process->signal(SIGTERM);
        $deadline = microtime(true) + 3.0;
        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(100000);
        }

        if ($process->isRunning()) {
            $process->signal(SIGKILL);
        }
    }

    private function endpoint(string $path): string
    {
        return sprintf('http://%s:%d%s', $this->host, $this->port, $path);
    }
}
