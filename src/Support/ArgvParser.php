<?php

declare(strict_types=1);

namespace Mgrunder\RelayUnifiedDbFuzzer\Support;

/**
 * Minimal CLI argument parser supporting --key=value and --key value styles.
 */
final class ArgvParser
{
    /**
     * @var array<string, string|null>
     */
    private array $options = [];

    /**
     * @param list<string> $argv
     */
    public function __construct(array $argv)
    {
        $this->parse($argv);
    }

    /**
     * @return array<string, string|null>
     */
    public function all(): array
    {
        return $this->options;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function get(string $name, ?string $default = null): ?string
    {
        return $this->options[$name] ?? $default;
    }

    public function require(string $name): string
    {
        if (!$this->has($name)) {
            throw new \InvalidArgumentException(sprintf('Missing required option "--%s"', $name));
        }

        $value = $this->options[$name];
        if ($value === null || $value === '') {
            throw new \InvalidArgumentException(sprintf('Option "--%s" requires a non-empty value', $name));
        }

        return $value;
    }

    public function requireInt(string $name): int
    {
        $value = $this->require($name);
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('Option "--%s" must be numeric', $name));
        }

        return (int)$value;
    }

    public function optionalInt(string $name, ?int $default = null): ?int
    {
        if (!$this->has($name)) {
            return $default;
        }

        $value = $this->get($name);
        if ($value === null || $value === '') {
            return $default;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('Option "--%s" must be numeric', $name));
        }

        return (int)$value;
    }

    /**
     * @return list<string>
     */
    public function csv(string $name): array
    {
        if (!$this->has($name)) {
            return [];
        }

        $value = (string)$this->get($name, '');
        $parts = array_filter(array_map(
            static fn(string $segment): string => trim($segment),
            explode(',', $value)
        ));

        return array_values($parts);
    }

    /**
     * @param list<string> $argv
     */
    private function parse(array $argv): void
    {
        $argc = count($argv);
        for ($i = 1; $i < $argc; $i++) {
            $token = $argv[$i];
            if (!str_starts_with($token, '--')) {
                continue;
            }

            $token = substr($token, 2);
            if ($token === '') {
                continue;
            }

            $value = null;
            if (str_contains($token, '=')) {
                [$name, $value] = explode('=', $token, 2);
            } else {
                $name = $token;
                $next = $argv[$i + 1] ?? null;
                if ($next !== null && !str_starts_with($next, '--')) {
                    $value = $next;
                    $i++;
                }
            }

            $this->options[$name] = $value;
        }
    }
}

