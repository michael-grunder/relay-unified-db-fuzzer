<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Fuzzer;

final class FuzzerStatusDisplay
{
    private const LEFT_LABEL_WIDTH = 16;
    private const LEFT_VALUE_WIDTH = 26;
    private const RIGHT_LABEL_WIDTH = 16;

    public function __construct(
        private readonly float $startTime,
    ) {
    }

    /**
     * @param array{
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
     * } $state
     */
    public function render(array $state): void
    {
        $elapsed = $this->formatDuration(microtime(true) - $this->startTime);
        $lines = [];
        $lines[] = sprintf('Elapsed Time: %s', $elapsed);
        $lines[] = '';
        $lines[] = $this->row(
            'Runs',
            $this->formatRuns($state['runs_completed'], $state['run_limit']),
            'Commands',
            $this->formatNullableInt($state['total_commands'])
        );
        $lines[] = $this->row(
            'Last Seed',
            (string)$state['last_seed'],
            'Hits',
            $this->formatNullableInt($state['hits'])
        );
        $lines[] = $this->row(
            'Last Exit',
            $this->formatNullableInt($state['last_exit']),
            'Misses',
            $this->formatNullableInt($state['misses'])
        );
        $lines[] = $this->row(
            'Failures',
            (string)$state['last_failures'],
            'Total Failures',
            (string)$state['total_failures']
        );
        $lines[] = $this->row(
            'Cores',
            (string)$state['cores'],
            'Total Commands',
            $this->formatNullableInt($state['command_total'])
        );
        $lines[] = $this->row(
            'Crash Signals',
            $this->formatCrashSignals($state['crash_signals']),
            'Requests',
            $this->formatNullableInt($state['usage_total_requests'])
        );
        $lines[] = $this->row(
            'Last Error',
            $this->formatError($state['error'], $state['error_class']),
            'Max Active',
            $this->formatNullableInt($state['usage_max_active_requests'])
        );
        $lines[] = '';
        $lines[] = $this->row('Mode', $state['mode'], 'Workers', (string)$state['workers']);
        $lines[] = $this->row('Ops/Run', (string)$state['ops'], 'RR', $state['rr'] ? 'on' : 'off');
        $lines[] = $this->row('Master Seed', (string)$state['master_seed'], 'Cmds', $this->formatCommands($state['commands']));

        $payload = "\033[2J\033[H" . implode(PHP_EOL, $lines) . PHP_EOL;
        fwrite(STDOUT, $payload);
        fflush(STDOUT);
    }

    private function formatDuration(float $seconds): string
    {
        $totalSeconds = (int)round($seconds);
        return gmdate('H:i:s', $totalSeconds);
    }

    private function formatRuns(int $completed, int $limit): string
    {
        if ($limit < 0) {
            return sprintf('%d (unlimited)', $completed);
        }

        return sprintf('%d/%d', $completed, $limit);
    }

    private function formatNullableInt(?int $value): string
    {
        return $value === null ? '(n/a)' : (string)$value;
    }

    /**
     * @param list<string|int> $signals
     */
    private function formatCrashSignals(array $signals): string
    {
        if ($signals === []) {
            return '(none)';
        }

        return implode(', ', array_map('strval', $signals));
    }

    private function formatError(?string $error, ?string $class): string
    {
        if ($error === null && $class === null) {
            return '(none)';
        }

        if ($error !== null && $class !== null) {
            return sprintf('%s (%s)', $error, $class);
        }

        return $error ?? $class ?? '(none)';
    }

    /**
     * @param list<string> $commands
     */
    private function formatCommands(array $commands): string
    {
        if ($commands === []) {
            return '(all)';
        }

        $count = count($commands);
        if ($count <= 4) {
            return implode(',', $commands);
        }

        $preview = array_slice($commands, 0, 4);
        return sprintf('%s...(+%d)', implode(',', $preview), $count - 4);
    }

    private function row(
        string $leftLabel,
        string $leftValue,
        ?string $rightLabel = null,
        ?string $rightValue = null
    ): string {
        $left = str_pad($leftLabel, self::LEFT_LABEL_WIDTH, ' ', STR_PAD_RIGHT)
            . str_pad($leftValue, self::LEFT_VALUE_WIDTH, ' ', STR_PAD_RIGHT);

        if ($rightLabel === null) {
            return rtrim($left);
        }

        $right = str_pad($rightLabel, self::RIGHT_LABEL_WIDTH, ' ', STR_PAD_RIGHT)
            . ($rightValue ?? '');

        return rtrim($left . $right);
    }
}
