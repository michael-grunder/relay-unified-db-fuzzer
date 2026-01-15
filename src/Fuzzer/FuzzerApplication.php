<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Fuzzer;

use Mgrunder\RelayUnifiedDbFuzzer\Support\SeedUtil;
use Symfony\Component\Process\Process;

final class FuzzerApplication
{
    private readonly string $runnerScript;
    private readonly string $runsDir;
    private readonly string $reproducersDir;
    private int $masterSeed;

    public function __construct(
        private readonly FuzzerOptions $options,
        private readonly string $projectRoot,
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
    }

    public function run(): int
    {
        printf("Master seed: %d\n", $this->masterSeed);
        $failures = 0;
        for ($runIndex = 0; $runIndex < $this->options->runs; $runIndex++) {
            $runSeed = SeedUtil::derive($this->masterSeed, $runIndex);
            $result = $this->runSingle($runIndex, $runSeed);
            if ($result) {
                $failures++;
            }
        }

        printf(
            "Completed %d run(s); failures=%d\n",
            $this->options->runs,
            $failures
        );

        return $failures > 0 ? 1 : 0;
    }

    private function runSingle(int $runIndex, int $runSeed): bool
    {
        $runId = sprintf('run-%05d', $runIndex);
        $runDir = $this->runsDir . '/' . $runId;
        if (!is_dir($runDir) && !mkdir($runDir, 0777, true)) {
            throw new \RuntimeException(sprintf('Failed to create run directory "%s"', $runDir));
        }

        $command = $this->buildRunnerCommand($runSeed, $runDir);
        $stdoutFile = $runDir . '/stdout.txt';
        $stderrFile = $runDir . '/stderr.txt';

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
        $summary = is_file($summaryPath)
            ? json_decode((string)file_get_contents($summaryPath), true)
            : null;

        $exitCode = $process->getExitCode();
        $failures = (int)($summary['failures'] ?? 0);
        $pids = $summary['pids'] ?? [];
        $coreFiles = $this->collectCoreFiles($pids);
        $hadFailure = ($exitCode !== 0) || $failures > 0 || $coreFiles !== [];

        printf(
            "[run=%d seed=%d] exit=%d failures=%d cores=%d\n",
            $runIndex,
            $runSeed,
            $exitCode,
            $failures,
            count($coreFiles)
        );

        if ($hadFailure) {
            $this->persistReproducer($runIndex, $runSeed, $runDir, $summary ?? [], $command, $coreFiles);
        }

        return $hadFailure;
    }

    /**
     * @return list<int|string>
     */
    private function buildRunnerCommand(int $runSeed, string $runDir): array
    {
        $cmd = [
            $this->options->phpBinary,
            '-c',
            $this->options->phpIni,
            $this->runnerScript,
            '--php=' . $this->options->phpBinary,
            '--php-ini=' . $this->options->phpIni,
            '--ops=' . $this->options->ops,
            '--workers=' . $this->options->workers,
            '--mode=' . $this->options->mode,
            '--seed=' . $runSeed,
            '--artifact-dir=' . $runDir,
        ];

        if ($this->options->commands !== []) {
            $cmd[] = '--commands=' . implode(',', $this->options->commands);
        }

        if ($this->options->mode === 'cli-server') {
            $cmd[] = '--host=' . $this->options->host;
            $cmd[] = '--port=' . $this->options->port;
        }

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
    }

    /**
     * @param list<int|string> $command
     */
    private function formatCommand(array $command): string
    {
        return implode(' ', array_map('escapeshellarg', $command));
    }
}
