<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Fuzzer;

use Mgrunder\RelayUnifiedDbFuzzer\Support\RedisCommandStats;
use Mgrunder\RelayUnifiedDbFuzzer\Support\SeedUtil;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

final class FuzzerApplication
{
    private readonly string $runnerScript;
    private readonly string $runsDir;
    private readonly string $reproducersDir;
    private ?FuzzerStatusDisplay $display = null;
    private int $masterSeed;
    private RedisCommandStats $commandStats;
    /** @var array<string, int>|null */
    private ?array $commandStatsBaseline = null;
    private ?int $commandStatsBaselineTotal = null;

    public function __construct(
        private readonly FuzzerOptions $options,
        private readonly string $projectRoot,
        private readonly LoggerInterface $logger,
    ) {
        $this->runnerScript = $projectRoot . '/runner';
        $this->runsDir = $projectRoot . '/.fuzzer/runs';
        $this->reproducersDir = $projectRoot . '/' . $options->reproducersDir;
        if (!is_dir($this->runsDir) && !mkdir($this->runsDir, 0777, true)) {
            throw new \RuntimeException(sprintf('Failed to create runs directory "%s"', $this->runsDir));
        }
        if (!is_dir($this->reproducersDir) && !mkdir($this->reproducersDir, 0777, true)) {
            throw new \RuntimeException(sprintf('Failed to create reproducers directory "%s"', $this->reproducersDir));
        }

        $this->masterSeed = $options->seed ?? random_int(0, PHP_INT_MAX);
        $this->commandStats = new RedisCommandStats($options->redisHost, $options->redisPort);
    }

    public function run(): int
    {
        $runLimit = $this->options->runs;
        $runLabel = $runLimit < 0 ? 'unlimited' : $runLimit;
        if ($this->options->display === 'tui') {
            $this->display = new FuzzerStatusDisplay(microtime(true));
        }
        $this->captureCommandStatsBaseline();
        $this->logger->info('Starting fuzzer run', [
            'master_seed' => $this->masterSeed,
            'runs' => $runLabel,
            'ops' => $this->options->ops,
            'workers' => $this->options->workers,
            'mode' => $this->options->mode,
            'rr' => $this->options->rr,
            'commands' => $this->options->commands,
        ]);
        $failures = 0;
        for ($runIndex = 0; $runLimit < 0 || $runIndex < $runLimit; $runIndex++) {
            $runSeed = SeedUtil::derive($this->masterSeed, $runIndex);
            $result = $this->runSingle($runIndex, $runSeed);
            if ($result['had_failure']) {
                $failures++;
            }
            if ($this->display !== null) {
                $this->display->render($this->buildDisplayState($result, $runIndex + 1, $runLimit, $failures));
            }
        }

        $this->logger->info('Completed fuzzer runs', [
            'runs' => $runLabel,
            'failures' => $failures,
        ]);

        return $failures > 0 ? 1 : 0;
    }

    /**
     * @return array{
     *   had_failure:bool,
     *   last_seed:int,
     *   last_exit:int|null,
     *   last_failures:int,
     *   cores:int,
     *   total_commands:int|null,
     *   command_total:int|null,
     *   hits:int|null,
     *   misses:int|null,
     *   usage_total_requests:int|null,
     *   usage_max_active_requests:int|null,
     *   crash_signals:list<string|int>,
     *   error:string|null,
     *   error_class:string|null
     * }
     */
    private function runSingle(int $runIndex, int $runSeed): array
    {
        $runId = sprintf('run-%05d', $runIndex);
        $runDir = $this->runsDir . '/' . $runId;
        if (!is_dir($runDir) && !mkdir($runDir, 0777, true)) {
            throw new \RuntimeException(sprintf('Failed to create run directory "%s"', $runDir));
        }

        $command = $this->buildRunnerCommand($runSeed, $runDir);
        $stdoutFile = $runDir . '/stdout.txt';
        $stderrFile = $runDir . '/stderr.txt';

        $this->logger->debug('Launching runner', [
            'run' => $runIndex,
            'seed' => $runSeed,
            'cmd' => $command,
            'run_dir' => $runDir,
        ]);

        $process = new Process($command, $this->projectRoot);
        $stdoutHandle = fopen($stdoutFile, 'ab');
        $stderrHandle = fopen($stderrFile, 'ab');
        if ($stdoutHandle === false || $stderrHandle === false) {
            throw new \RuntimeException('Failed to open log files for writing');
        }

        $process->run(function (string $type, string $buffer) use ($stdoutHandle, $stderrHandle): void {
            if ($type === Process::OUT) {
                fwrite($stdoutHandle, $buffer);
            } else {
                fwrite($stderrHandle, $buffer);
            }
        });

        fclose($stdoutHandle);
        fclose($stderrHandle);

        $summaryPath = $runDir . '/summary.json';
        $summary = null;
        if (is_file($summaryPath)) {
            $rawSummary = (string)file_get_contents($summaryPath);
            $decoded = json_decode($rawSummary, true);
            if (is_array($decoded)) {
                $summary = $decoded;
            } else {
                $this->logger->warning('Failed to decode summary.json', [
                    'run' => $runIndex,
                    'seed' => $runSeed,
                    'path' => $summaryPath,
                    'raw' => $rawSummary,
                ]);
            }
        } else {
            $this->logger->warning('Missing summary.json', [
                'run' => $runIndex,
                'seed' => $runSeed,
                'path' => $summaryPath,
            ]);
        }

        $exitCode = $process->getExitCode();
        $failures = (int)($summary['failures'] ?? 0);
        $pids = $summary['pids'] ?? [];
        $relayStats = is_array($summary['relay_stats'] ?? null) ? $summary['relay_stats'] : [];
        $relayUsage = is_array($relayStats['usage'] ?? null) ? $relayStats['usage'] : [];
        $relayCacheStats = is_array($relayStats['stats'] ?? null) ? $relayStats['stats'] : [];
        $relayIni = is_array($relayStats['ini'] ?? null) ? $relayStats['ini'] : [];
        $coreFiles = $this->collectCoreFiles($pids);
        $hadFailure = ($exitCode !== 0) || $failures > 0 || $coreFiles !== [];
        [$commandStatsTotal, $commandStatsDelta] = $this->captureCommandStatsDelta();
        $hits = $this->parseNullableInt($relayCacheStats['hits'] ?? null);
        $misses = $this->parseNullableInt($relayCacheStats['misses'] ?? null);
        $usageTotal = $this->parseNullableInt($relayUsage['total_requests'] ?? null);
        $usageMaxActive = $this->parseNullableInt($relayUsage['max_active_requests'] ?? null);

        $this->logger->info('Run completed', [
            'run' => $runIndex,
            'seed' => $runSeed,
            'exit' => $exitCode,
            'failures' => $failures,
            'cores' => count($coreFiles),
            'total_commands' => $commandStatsDelta,
            'crash_signals' => $summary['crash_signals'] ?? [],
            'error' => $summary['error'] ?? null,
            'error_class' => $summary['error_class'] ?? null,
            'usage_total_requests' => $usageTotal,
            'usage_max_active_requests' => $usageMaxActive,
            'stats_hits' => $hits,
            'stats_misses' => $misses,
            'ini' => $relayIni,
        ]);

        if ($hadFailure) {
            $this->logger->warning('Run failure detected', [
                'run' => $runIndex,
                'seed' => $runSeed,
                'exit' => $exitCode,
                'failures' => $failures,
                'cores' => $coreFiles,
                'crash_signals' => $summary['crash_signals'] ?? [],
                'summary_path' => $summaryPath,
                'stderr' => $stderrFile,
                'error' => $summary['error'] ?? null,
                'error_class' => $summary['error_class'] ?? null,
            ]);
            $this->persistReproducer($runIndex, $runSeed, $runDir, $summary ?? [], $command, $coreFiles);
        }

        return [
            'had_failure' => $hadFailure,
            'last_seed' => $runSeed,
            'last_exit' => $exitCode,
            'last_failures' => $failures,
            'cores' => count($coreFiles),
            'total_commands' => $commandStatsDelta,
            'command_total' => $commandStatsTotal,
            'hits' => $hits,
            'misses' => $misses,
            'usage_total_requests' => $usageTotal,
            'usage_max_active_requests' => $usageMaxActive,
            'crash_signals' => $summary['crash_signals'] ?? [],
            'error' => $summary['error'] ?? null,
            'error_class' => $summary['error_class'] ?? null,
        ];
    }

    /**
     * @param array{
     *   had_failure:bool,
     *   last_seed:int,
     *   last_exit:int|null,
     *   last_failures:int,
     *   cores:int,
     *   total_commands:int|null,
     *   command_total:int|null,
     *   hits:int|null,
     *   misses:int|null,
     *   usage_total_requests:int|null,
     *   usage_max_active_requests:int|null,
     *   crash_signals:list<string|int>,
     *   error:string|null,
     *   error_class:string|null
     * } $runResult
     * @return array{
     *   run_limit:int,
     *   runs_completed:int,
     *   master_seed:int,
     *   ops:int,
     *   workers:int,
     *   mode:string,
     *   rr:bool,
     *   commands:list<string>,
     *   last_seed:int,
     *   last_exit:int|null,
     *   last_failures:int,
     *   total_failures:int,
     *   cores:int,
     *   total_commands:int|null,
     *   command_total:int|null,
     *   hits:int|null,
     *   misses:int|null,
     *   usage_total_requests:int|null,
     *   usage_max_active_requests:int|null,
     *   crash_signals:list<string|int>,
     *   error:string|null,
     *   error_class:string|null
     * }
     */
    private function buildDisplayState(array $runResult, int $runsCompleted, int $runLimit, int $totalFailures): array
    {
        return [
            'run_limit' => $runLimit,
            'runs_completed' => $runsCompleted,
            'master_seed' => $this->masterSeed,
            'ops' => $this->options->ops,
            'workers' => $this->options->workers,
            'mode' => $this->options->mode,
            'rr' => $this->options->rr,
            'commands' => $this->options->commands,
            'last_seed' => $runResult['last_seed'],
            'last_exit' => $runResult['last_exit'],
            'last_failures' => $runResult['last_failures'],
            'total_failures' => $totalFailures,
            'cores' => $runResult['cores'],
            'total_commands' => $runResult['total_commands'],
            'command_total' => $runResult['command_total'],
            'hits' => $runResult['hits'],
            'misses' => $runResult['misses'],
            'usage_total_requests' => $runResult['usage_total_requests'],
            'usage_max_active_requests' => $runResult['usage_max_active_requests'],
            'crash_signals' => $runResult['crash_signals'],
            'error' => $runResult['error'],
            'error_class' => $runResult['error_class'],
        ];
    }

    private function parseNullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        return null;
    }

    private function captureCommandStatsBaseline(): void
    {
        $counts = $this->fetchCommandStatsCounts('baseline');
        if ($counts === null) {
            return;
        }

        $this->commandStatsBaseline = $counts;
        $this->commandStatsBaselineTotal = array_sum($counts);

        $this->logger->info('Captured Redis commandstats baseline', [
            'commandstats_total' => $this->commandStatsBaselineTotal,
        ]);
    }

    /**
     * @return array{0:int|null, 1:int|null}
     */
    private function captureCommandStatsDelta(): array
    {
        if ($this->commandStatsBaselineTotal === null) {
            return [null, null];
        }

        $counts = $this->fetchCommandStatsCounts('post-run');
        if ($counts === null) {
            return [null, null];
        }

        $currentTotal = array_sum($counts);
        return [$currentTotal, $currentTotal - $this->commandStatsBaselineTotal];
    }

    /**
     * @return array<string, int>|null
     */
    private function fetchCommandStatsCounts(string $stage): ?array
    {
        try {
            return $this->commandStats->snapshotCounts();
        } catch (\Throwable $t) {
            $this->logger->warning('Failed to capture Redis commandstats', [
                'stage' => $stage,
                'exception' => $t,
            ]);
        }

        return null;
    }

    /**
     * @return list<int|string>
     */
    private function buildRunnerCommand(int $runSeed, string $runDir): array
    {
        $cmd = [
            $this->options->phpBinary,
        ];
        if ($this->options->phpIni !== null) {
            $cmd[] = '-c';
            $cmd[] = $this->options->phpIni;
        }
        $cmd[] = $this->runnerScript;
        $cmd[] = '--php=' . $this->options->phpBinary;
        if ($this->options->phpIni !== null) {
            $cmd[] = '--php-ini=' . $this->options->phpIni;
        }
        $cmd[] = '--ops=' . $this->options->ops;
        $cmd[] = '--workers=' . $this->options->workers;
        $cmd[] = '--mode=' . $this->options->mode;
        $cmd[] = '--seed=' . $runSeed;
        $cmd[] = '--artifact-dir=' . $runDir;
        if ($this->options->rr) {
            $cmd[] = '--rr';
            if ($this->options->rrTraceDir !== null) {
                $rrDir = rtrim($this->options->rrTraceDir, '/') . '/' . basename($runDir);
                $cmd[] = '--rr-trace-dir=' . $rrDir;
            }
        }

        if ($this->options->commands !== []) {
            $cmd[] = '--commands=' . implode(',', $this->options->commands);
        }

        $cmd[] = '--redis-host=' . $this->options->redisHost;
        $cmd[] = '--redis-port=' . $this->options->redisPort;

        if ($this->options->mode === 'cli-server') {
            $cmd[] = '--host=' . $this->options->host;
            $cmd[] = '--port=' . $this->options->port;
            if ($this->options->cliServerHold) {
                $cmd[] = '--cli-server-hold';
            }
        }
        $cmd[] = '--log-level=' . $this->options->logLevel;

        return $cmd;
    }

    /**
     * @param list<int> $pids
     * @return list<string>
     */
    private function collectCoreFiles(array $pids): array
    {
        $paths = [];
        foreach ($pids as $pidValue) {
            $pid = (int)$pidValue;
            if ($pid <= 0) {
                continue;
            }
            $pattern = sprintf('/tmp/core.*.%d.*', $pid);
            $matches = glob($pattern) ?: [];
            foreach ($matches as $match) {
                $paths[] = $match;
            }
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $summary
     * @param list<string> $command
     * @param list<string> $coreFiles
     */
    private function persistReproducer(
        int $runIndex,
        int $runSeed,
        string $runDir,
        array $summary,
        array $command,
        array $coreFiles
    ): void {
        $id = sprintf('%s-%05d', date('YmdHis'), $runIndex);
        $dir = $this->reproducersDir . '/' . $id;
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Failed to create reproducer dir "%s"', $dir));
        }

        file_put_contents($dir . '/cmd.txt', $this->formatCommand($command));
        file_put_contents($dir . '/seed.txt', (string)$runSeed);
        file_put_contents($dir . '/mode.txt', $this->options->mode);

        $payloadBin = $summary['payload_bin'] ?? null;
        $payloadJson = $summary['payload_json'] ?? null;
        if ($payloadBin && is_file($payloadBin)) {
            copy($payloadBin, $dir . '/payload.bin');
        }
        if ($payloadJson && is_file($payloadJson)) {
            copy($payloadJson, $dir . '/payload.json');
        }

        foreach (['stdout.txt', 'stderr.txt', 'summary.json'] as $file) {
            $src = $runDir . '/' . $file;
            if (is_file($src)) {
                copy($src, $dir . '/' . $file);
            }
        }

        foreach ($coreFiles as $path) {
            $base = basename($path);
            $dst = $dir . '/' . $base;
            if (is_file($dst)) {
                $dst = sprintf('%s/%s.%s', $dir, $base, uniqid());
            }
            copy($path, $dst);
        }

        $rrTraceDir = $summary['rr_trace_dir'] ?? null;
        if (is_string($rrTraceDir) && is_dir($rrTraceDir)) {
            $dst = $dir . '/' . basename($rrTraceDir);
            if (file_exists($dst)) {
                $dst = sprintf('%s/%s.%s', $dir, basename($rrTraceDir), uniqid());
            }
            $this->copyDir($rrTraceDir, $dst);
        }
    }

    /**
     * @param list<int|string> $command
     */
    private function formatCommand(array $command): string
    {
        return implode(' ', array_map('escapeshellarg', $command));
    }

    private function copyDir(string $src, string $dst): void
    {
        if (!is_dir($src)) {
            return;
        }

        if (!is_dir($dst) && !mkdir($dst, 0777, true) && !is_dir($dst)) {
            throw new \RuntimeException(sprintf('Failed to create directory "%s"', $dst));
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            $target = $dst . '/' . $iterator->getSubPathName();
            if ($entry->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
                    throw new \RuntimeException(sprintf('Failed to create directory "%s"', $target));
                }
                continue;
            }
            copy($entry->getPathname(), $target);
        }
    }
}
